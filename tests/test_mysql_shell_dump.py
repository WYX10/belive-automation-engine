"""Regression checks for incomplete/ambiguous MySQL Shell export inputs."""
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

spec = importlib.util.spec_from_file_location("dump_converter", Path(__file__).resolve().parents[1] / "database/convert_mysql_shell_dump.py")
converter = importlib.util.module_from_spec(spec)
spec.loader.exec_module(converter)


class MySQLShellDumpTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)

    def tearDown(self):
        self.temp.cleanup()

    def table(self, name, columns, content, rows=1):
        base = "belive_eve@" + name
        options = {"schema": "belive_eve", "table": name, "columns": columns,
                   "fieldsTerminatedBy": "\t", "fieldsEnclosedBy": "",
                   "fieldsEscapedBy": "\\", "linesTerminatedBy": "\n"}
        (self.root / (base + ".json")).write_text(json.dumps({"options": options, "primaryIndex": [columns[0]]}))
        (self.root / (base + ".sql")).write_text("CREATE TABLE synthetic_fixture () AUTO_INCREMENT=25;")
        compressed = subprocess.run(["zstd", "-q", "-c"], input=content.encode(), capture_output=True, check=True).stdout
        (self.root / (base + "@@0.tsv.zst")).write_bytes(compressed)
        (self.root / "@.json").write_text(json.dumps({"origin": "dumpSchemas", "schemas": ["belive_eve"]}))
        (self.root / "@.done.json").write_text(json.dumps({"tableRows": {"belive_eve": {name: rows}}}))
        return options

    def test_staff_chunk_does_not_consume_staff_shifts(self):
        options = self.table("staff", ["id", "name"], "1\tSynthetic agent\n")
        self.table("staff_shifts", ["id", "staff_id", "weekday"], "1\t1\t2\n")
        rows = list(converter.read_rows(self.root, "belive_eve@staff", options))
        self.assertEqual(rows, [{"id": "1", "name": "Synthetic agent"}])

    def test_incomplete_dump_never_writes_an_import(self):
        self.table("staff", ["id", "name"], "1\tSynthetic agent\n", rows=2)
        output = self.root / "import.sql"
        with self.assertRaisesRegex(ValueError, "Incomplete backup"):
            converter.convert(self.root, output)
        self.assertFalse(output.exists())

    def test_truncated_record_is_rejected(self):
        options = self.table("staff", ["id", "name"], "1\tSynthetic agent")
        with self.assertRaisesRegex(ValueError, "Truncated"):
            list(converter.read_rows(self.root, "belive_eve@staff", options))

    def test_pg_unsupported_nul_never_creates_partial_output(self):
        self.table("staff", ["id", "name"], "1\tInvalid\\0name\n")
        output = self.root / "import.sql"
        with self.assertRaisesRegex(ValueError, "NUL"):
            converter.convert(self.root, output)
        self.assertFalse(output.exists())

    def test_schema_injection_is_rejected_before_writing(self):
        output = self.root / "import.sql"
        with self.assertRaisesRegex(ValueError, "identifier"):
            converter.convert(self.root, output, 'belive; DROP SCHEMA public CASCADE')
        self.assertFalse(output.exists())


if __name__ == "__main__":
    unittest.main()
