# Supabase migration

The application supports MySQL and PostgreSQL. Supabase hosts PostgreSQL; changing
the database hostname alone is insufficient. The PostgreSQL baseline includes the
application schema through MySQL migration 048. Subsequent PostgreSQL migrations
belong in `database/postgres/migrations`; recorded files are immutable and checked
by SHA-256. The original MySQL migrations and tracking table remain intact.

## Prepare and import an existing MySQL Shell backup

Keep Azure MySQL available until restoration, application checks and cutover are
complete. Keep the original dump and a second private copy. Room photos, uploaded
videos, agreements and other filesystem uploads must be copied separately.

The standard-library converter needs Python 3 and `zstd`. Run against the
extracted directory containing `@.json` and `@.done.json`:

```sh
python3 database/convert_mysql_shell_dump.py \
  --dump /private/path/belive-backup \
  --output /private/path/belive-supabase-import.sql
```

It validates the completed dump, source table/column names, TSV format and every
source table's row count. It does not execute any SQL from the backup. It produces
a SQL file and a verification manifest with owner-only permissions. The SQL file
contains private tenant records and encrypted provider credentials: keep it out
of Git, public storage and application document roots.

In Supabase, open **SQL Editor**, create a query, paste the generated SQL file,
and run it. Alternatively use `psql -X -v ON_ERROR_STOP=1 -f <file>` with a private
password file. The import creates a new `belive` schema and runs in one
transaction. An existing schema, invalid foreign key, duplicate key or count
mismatch causes failure; it never drops or overwrites existing application data.
If the editor reports an error, inspect the error rather than repeatedly running
the import. Success returns the number of tenants, rooms and tenant profiles.

All original IDs and values are retained. Identity sequences account for both
the largest imported ID and MySQL's next AUTO_INCREMENT value, including gaps.
The new version backfills tenant requirement profiles and inserts missing default
settings, including the two new content studio settings. The old `migrations` records are preserved separately
from the new `postgres_migrations` tracking table.

For a row-by-row contents check after restoration:

```sh
python3 database/convert_mysql_shell_dump.py \
  --dump /private/path/belive-backup \
  --verify /private/path/belive-supabase-import.verification.json \
  --host "SESSION_POOLER_HOST" --port 5432 \
  --database postgres --user "postgres.PROJECT_REF"
```

Replace the host/user placeholders with the project's Session pooler parameters.
`psql` obtains the password from its standard private `PGPASSFILE` or prompt. JSON
is compared semantically; every other original cell is compared exactly. New default
settings are excluded from the original settings comparison.

## Configure the PHP app on Azure

Use the **Session pooler**, port 5432. PostgreSQL session advisory locks coordinate
learning and posting workers; a transaction pooler cannot retain these locks.
The pooler also supports IPv4 without a paid direct IPv4 endpoint.

Ensure the deployed PHP runtime has `pdo_pgsql` (`php -m`). Setting it in the GitHub
build does not install it in the running Azure runtime: verify the deployed
runtime separately. In the Azure web app's environment settings, configure:

```dotenv
DB_DRIVER=pgsql
DB_HOST=<session-pooler-host>
DB_PORT=5432
DB_NAME=postgres
DB_USER=postgres.<project-ref>
DB_PASS=<replacement-password-entered-privately>
DB_SCHEMA=belive
DB_SSL_MODE=verify-full
DB_SSL_ROOT_CERT=/etc/ssl/certs/ca-certificates.crt
```

Use a trusted CA bundle/certificate from Supabase's database settings if the
runtime's CA store cannot verify the pooler. Preserve TLS/certificate verification.
Windows deployments must supply their actual CA file path.

The Azure installation encountered `certificate verify failed` before its
successful connection check. For a repeatable Azure installation, follow the
[IT handover certificate steps](IT_HANDOVER.md#82-supabase-and-trusted-tls):
download the project's CA, combine it with system trust in
`/home/belive-certs/database-ca.pem`, and set `DB_SSL_ROOT_CERT` to that path.
Keep `DB_SSL_MODE=verify-full`. Certificate and worker provisioning belong to the
running runtime, not only to the GitHub build.

**Keep the existing Azure `APP_ENCRYPTION_KEY` unchanged.** The backup contains
encrypted provider keys; a different application encryption key cannot decrypt
them. Preserve webhook verification, admin authentication, media files and cron
configuration too. Deployment and connection-setting cutover should be performed
together, after testing the new code. Keep the old Azure database settings for
rollback. Stop content workers during final copying and database cutover, then
restart them with the new connection. A live MySQL database can receive newer
messages after the backup: use a final fresh dump during a short write pause so
those messages are not lost.

`php database/migrate.php` applies PostgreSQL migrations transactionally and
verifies previously recorded checksums. It manages only the app schema, never
Supabase's `auth`, `storage` or `public` schemas. Tenant tables have RLS enabled
with no public policies. The trusted PHP backend connects as the database owner;
browser clients must not receive these database credentials. Keep the app schema
out of Data API exposed schemas.

For a read-only cloud connection check, supply `SUPABASE_DB_HOST`,
`SUPABASE_DB_PORT`, `SUPABASE_DB_NAME`, `SUPABASE_DB_USER` and `SUPABASE_DB_PASS`
privately, then run `php database/supabase_check.php --require-import`. These
separate variable names allow checks without changing the running MySQL app.

## Local verification

Run the existing PHP suite on both backends. PostgreSQL tests require a local
database and rebuild only `<DB_NAME>_test`, never a hosted Supabase project:

```sh
php tests/run.php
DB_DRIVER=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_NAME=belive_pg \
DB_USER=belive_local DB_PASS='' DB_SSL_MODE=disable php tests/run.php
python3 -m unittest discover -s tests -p 'test_mysql_shell_dump.py'
```

Check the public room catalog, admin dashboard, tenant conversation, learned-rule
deduplication, bookings, owner/tenant portals and scheduled posting. Provider
credentials and real social publishing need a separate live check; offline tests
do not establish successful external delivery.
