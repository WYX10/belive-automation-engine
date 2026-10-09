#!/usr/bin/env python3
"""Convert a completed MySQL Shell TSV/zstd dump into an atomic, private PG import.

No MySQL SQL from the input is executed. Target structure comes exclusively from
the reviewed PostgreSQL baseline in this repository. Source counts are checked
before producing SQL; a manifest enables row-by-row comparison after restore.
"""
import argparse
import hashlib
import json
import os
from decimal import Decimal
from pathlib import Path
import re
import subprocess

POSTGRES = Path(__file__).resolve().parent / "postgres"


def identifier(value):
    if not re.fullmatch(r"[a-z_][a-z0-9_]{0,62}", value):
        raise ValueError("Invalid database identifier")
    return '"' + value + '"'


def literal(value):
    if value is None:
        return "NULL"
    if "\x00" in value:
        raise ValueError("PostgreSQL text cannot represent a NUL character")
    return "E'" + value.replace("\\", "\\\\").replace("'", "''").replace("\r", "\\r").replace("\n", "\\n").replace("\t", "\\t") + "'"


def unescape(value):
    if value == r"\N":
        return None
    escapes = {"0": "\x00", "b": "\b", "n": "\n", "r": "\r", "t": "\t", "Z": "\x1a", "\\": "\\"}
    return re.sub(r"\\(.)", lambda m: escapes.get(m[1], m[1]), value)


def canonical_json(value):
    if isinstance(value, Decimal):
        number = format(value, "f")
        if "." in number:
            number = number.rstrip("0").rstrip(".")
        return ["number", "0" if value == 0 else number]
    if isinstance(value, dict):
        return ["object", [[key, canonical_json(item)] for key, item in sorted(value.items())]]
    if isinstance(value, list):
        return ["array", [canonical_json(item) for item in value]]
    return [type(value).__name__, value]


def row_bytes(row, names, specs):
    values = []
    for name in names:
        value = row[name]
        if value is not None and specs[name]["json"]:
            value = canonical_json(json.loads(value, parse_int=Decimal, parse_float=Decimal))
        values.append(value)
    return (json.dumps(values, ensure_ascii=False, separators=(",", ":")) + "\n").encode("utf-8")


def read_rows(root, base, options):
    required = {"fieldsTerminatedBy": "\t", "fieldsEnclosedBy": "", "fieldsEscapedBy": "\\", "linesTerminatedBy": "\n"}
    if any(options.get(key) != expected for key, expected in required.items()) or options.get("decodeColumns"):
        raise ValueError("Unsupported MySQL Shell TSV encoding")
    names = options["columns"]
    chunks = sorted((list(root.glob(base + "@@*.tsv.zst")) + list(root.glob(base + ".tsv.zst"))), key=lambda p: [int(part) if part.isdigit() else part for part in re.split(r"(\d+)", p.name)])
    for chunk in chunks:
        result = subprocess.run(["zstd", "-dc", "--", str(chunk)], capture_output=True, check=True)
        if result.stdout and not result.stdout.endswith(b"\n"):
            raise ValueError("Truncated TSV chunk")
        for number, line in enumerate(result.stdout.decode("utf-8").split("\n")[:-1], 1):
            # splitlines would treat a raw control character as a record separator;
            # MySQL Shell escapes such characters. Reject unsupported encodings.
            fields = line.split("\t")
            if len(fields) != len(names):
                raise ValueError(f"Unexpected column count in {chunk.name}, row {number}")
            yield dict(zip(names, map(unescape, fields)))


def convert(root, output, schema="belive"):
    identifier(schema)
    metadata = json.loads((root / "@.json").read_text())
    completed = json.loads((root / "@.done.json").read_text())
    if metadata.get("origin") != "dumpSchemas" or len(metadata.get("schemas", [])) != 1:
        raise ValueError("Expected a completed single-schema MySQL Shell dump")
    source_schema = metadata["schemas"][0]
    identifier(source_schema)
    expected = completed["tableRows"][source_schema]
    specs = json.loads((POSTGRES / "columns.json").read_text())
    migrations = sorted((POSTGRES / "migrations").glob("*.sql"))
    baseline = migrations[0].read_text()
    if migrations[0].name != "001_schema.sql":
        raise ValueError("Missing reviewed PostgreSQL baseline")
    manifest = {"schema": schema, "source_schema": source_schema, "tables": {}}
    sql = ["-- Private application backup. Contains tenant data and encrypted credentials.",
           "-- Import into a NEW schema. An existing schema causes a safe failure.",
           "BEGIN;", "SET LOCAL statement_timeout = '5min';", "SET LOCAL lock_timeout = '10s';",
           "SET LOCAL TIME ZONE 'UTC';", f"CREATE SCHEMA {identifier(schema)};",
           f"SET LOCAL search_path TO {identifier(schema)}, pg_catalog;", baseline,
           "CREATE TABLE postgres_migrations (filename varchar(120) PRIMARY KEY, checksum char(64) NOT NULL, applied_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP);",
           "ALTER TABLE postgres_migrations ENABLE ROW LEVEL SECURITY;", "SET CONSTRAINTS ALL DEFERRED;"]
    for table, count in sorted(expected.items()):
        identifier(table)
        if table not in specs:
            raise ValueError("Unknown source table: " + table)
        base = source_schema + "@" + table
        options = json.loads((root / (base + ".json")).read_text())["options"]
        names = options["columns"]
        if options["table"] != table or options["schema"] != source_schema or not set(names).issubset(specs[table]):
            raise ValueError("Source columns do not match target baseline: " + table)
        for name in names:
            identifier(name)
        hasher = hashlib.sha256()
        rows = list(read_rows(root, base, options))
        if len(rows) != count:
            raise ValueError(f"Incomplete backup: {table}: expected {count} rows, read {len(rows)}")
        for payload in sorted(row_bytes(row, names, specs[table]) for row in rows):
            hasher.update(payload)
        manifest["tables"][table] = {"columns": names, "rows": count, "sha256": hasher.hexdigest(), "primary": json.loads((root / (base + ".json")).read_text()).get("primaryIndex", [])}
        for start in range(0, len(rows), 50):
            values = ["(" + ",".join(literal(row[name]) for name in names) + ")" for row in rows[start:start + 50]]
            sql.append("INSERT INTO " + identifier(table) + " (" + ",".join(map(identifier, names)) + ") VALUES\n" + ",\n".join(values) + ";")
        # Counts checked in PostgreSQL before adding the new version's default settings.
        sql.append(f"DO $$ BEGIN IF (SELECT COUNT(*) FROM {identifier(table)}) <> {count} THEN RAISE EXCEPTION 'Row count mismatch: {table}'; END IF; END $$;")
        for name in names:
            if specs[table][name]["identity"]:
                source_ddl = (root / (base + ".sql")).read_text()
                match = re.search(r"\bAUTO_INCREMENT=(\d+)", source_ddl)
                next_id = int(match[1]) if match else 1
                sequence = literal(schema + "." + table)
                sql.append(f"SELECT setval(pg_get_serial_sequence({sequence}, {literal(name)}), GREATEST(COALESCE(MAX({identifier(name)}), 0) + 1, {next_id}), false) FROM {identifier(table)};")
    sql.extend([
        "INSERT INTO tenant_requirements (lead_id, location, budget, move_in_date, room_type, tenure, tenant_profile) SELECT id, location, CASE WHEN budget COLLATE \"default\" ~ '^[0-9]{1,8}([.][0-9]{1,2})?$' THEN NULLIF(CAST(budget AS numeric(10,2)), 0) ELSE NULL END, move_in_date, room_type, preferred_tenure, LEFT(tenant_profile, 40) FROM leads ON CONFLICT DO NOTHING;",

        "REVOKE ALL ON ALL TABLES IN SCHEMA " + identifier(schema) + " FROM PUBLIC;",
        "REVOKE ALL ON ALL SEQUENCES IN SCHEMA " + identifier(schema) + " FROM PUBLIC;",
        "REVOKE ALL ON SCHEMA " + identifier(schema) + " FROM PUBLIC;",
        "DO $$ DECLARE r text; BEGIN FOREACH r IN ARRAY ARRAY['anon','authenticated','service_role'] LOOP IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) THEN EXECUTE format('REVOKE ALL ON SCHEMA %I FROM %I', current_schema(), r); EXECUTE format('REVOKE ALL ON ALL TABLES IN SCHEMA %I FROM %I', current_schema(), r); EXECUTE format('REVOKE ALL ON ALL SEQUENCES IN SCHEMA %I FROM %I', current_schema(), r); END IF; END LOOP; END $$;",
        "SET CONSTRAINTS ALL IMMEDIATE;", "COMMIT;",
        f"SELECT 'BeLive import completed' AS result, (SELECT COUNT(*) FROM {identifier(schema)}.leads) AS tenants, (SELECT COUNT(*) FROM {identifier(schema)}.rooms) AS rooms, (SELECT COUNT(*) FROM {identifier(schema)}.tenant_requirements) AS tenant_profiles;"
    ])
    # Apply only the reviewed PostgreSQL follow-up migrations after original rows.
    # Record each checksum so the application runner can verify, not replay, them.
    position = next(i for i, statement in enumerate(sql) if statement.startswith("REVOKE ALL ON ALL TABLES"))
    additional = [file.read_text() for file in migrations[1:]]
    for file in migrations:
        digest = hashlib.sha256(file.read_bytes()).hexdigest()
        additional.append("INSERT INTO postgres_migrations (filename, checksum) VALUES (" + literal(file.name) + "," + literal(digest) + ");")
    sql[position:position] = additional
    output.parent.mkdir(parents=True, exist_ok=True)
    verification = output.with_suffix(".verification.json")
    if output.exists() or verification.exists():
        raise FileExistsError("Output already exists; choose a new path")
    with os.fdopen(os.open(output, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), "w", encoding="utf-8") as file:
        file.write("\n\n".join(sql) + "\n")
    with os.fdopen(os.open(verification, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), "w", encoding="utf-8") as file:
        json.dump(manifest, file, indent=2)
    return manifest


def verify(root, manifest_path, host, port, database, user):
    """psql obtains its password from PGPASSFILE or an interactive prompt, never argv."""
    manifest = json.loads(manifest_path.read_text())
    specs = json.loads((POSTGRES / "columns.json").read_text())
    for table, info in manifest["tables"].items():
        columns = ",".join(f"CAST({identifier(name)} AS text) AS {identifier(name)}" for name in info["columns"])
        order = ",".join(map(identifier, info["primary"]))
        query = f"SELECT row_to_json(t) FROM (SELECT {columns} FROM {identifier(manifest['schema'])}.{identifier(table)}" + (" ORDER BY " + order if order else "") + ") t;"
        result = subprocess.run(["psql", "-X", "-h", host, "-p", str(port), "-U", user, "-d", database, "-v", "ON_ERROR_STOP=1", "-At", "-c", query], capture_output=True, text=True)
        if result.returncode:
            raise RuntimeError("PostgreSQL verification query failed for " + table)
        rows = [json.loads(line) for line in result.stdout.splitlines()]
        # Compare every original setting while permitting missing default keys
        # added by reviewed PostgreSQL follow-up migrations.
        if table == "app_settings":
            options = json.loads((root / (manifest["source_schema"] + "@app_settings.json")).read_text())["options"]
            original = {row["setting_key"] for row in read_rows(root, manifest["source_schema"] + "@app_settings", options)}
            rows = [row for row in rows if row["setting_key"] in original]
        hasher = hashlib.sha256()
        for payload in sorted(row_bytes(row, info["columns"], specs[table]) for row in rows):
            hasher.update(payload)
        if len(rows) != info["rows"] or hasher.hexdigest() != info["sha256"]:
            raise ValueError("Restored row contents differ: " + table)
        print(f"verified {table}: {len(rows)} rows")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dump", type=Path, required=True)
    parser.add_argument("--output", type=Path)
    parser.add_argument("--schema", default="belive")
    parser.add_argument("--verify", type=Path, help="Compare imported rows against this verification manifest")
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=5432)
    parser.add_argument("--database", default="postgres")
    parser.add_argument("--user", default="postgres")
    args = parser.parse_args()
    if args.verify:
        verify(args.dump, args.verify, args.host, args.port, args.database, args.user)
    elif args.output:
        result = convert(args.dump, args.output, args.schema)
        print(f"Converted {len(result['tables'])} tables and {sum(t['rows'] for t in result['tables'].values())} source rows; output: {args.output}")
    else:
        parser.error("Supply --output or --verify")
