# Azure deployment — BeLive with Supabase

Use the [BeLive IT handover guide](../../docs/IT_HANDOVER.md) as the current
installation and operations reference. The deployed database is Supabase
PostgreSQL; a new Azure MySQL server is not required for this arrangement.

## Deployment checklist

1. Use Linux App Service with a supported PHP runtime and `pdo_pgsql`.
2. Set the startup command to
   `bash /home/site/wwwroot/deploy/azure/startup.sh` so Nginx serves `public/`.
3. Configure private App Settings using handover section 7. Preserve the existing
   `APP_ENCRYPTION_KEY`, provider credentials, webhook token and uploaded media.
4. Use Supabase's **Session pooler**, port 5432, and `DB_SCHEMA=belive`.
5. Install the downloaded Supabase CA certificate and configure
   `DB_SSL_ROOT_CERT=/home/belive-certs/database-ca.pem` with
   `DB_SSL_MODE=verify-full`. See handover section 8 for the complete steps.
6. Deploy through `.github/workflows/main_belive-engine.yml`. A push to `main`
   triggers a production deployment. For a different web app, update the target
   name and the private GitHub deployment credential through the approved process.
7. Provision Python/uv, the MCP virtualenv, PHP GD, FFmpeg/ffprobe, fonts and
   optional eSpeak NG on the **running host**. The PHP build workflow and startup
   script do not install these dependencies. Changes outside `/home` are not
   persistent in the current Azure image.
8. Run `php database/migrate.php`, then the read-only TLS/schema check in handover
   section 13. Do not rerun the initial import or seed demo records into the
   already imported production database.
9. Configure an independent content worker or authenticated one-minute HTTP
   scheduler, plus learning/decay/engagement jobs; see handover section 11.
10. Complete the acceptance and recovery checks before retiring Azure MySQL.

## Scheduling

The startup script configures Nginx/upload limits; it does not supervise a
worker. Admin traffic can trigger daily drafting but is not a dependable
scheduler. Use a supported supervised worker or POST `/cron/content` with a
private Bearer token from an independent scheduler. Do not put tokens in URLs.

## Storage and costs

Back up database data, uploaded media and the encryption key separately.
Preserve uploads when redeploying. Use company-controlled resource ownership.
A free Supabase database does not remove paid App Service, AI, storage, worker or
provider charges. A free/sleeping web tier cannot guarantee posting uptime.

Historical MySQL/demo instructions, including disabling TLS verification and
replacing encryption keys during migration, have been superseded by the IT guide.
