# Deploying BeLive Engine to Azure App Service (Linux, PHP 8)

Free-tier friendly: uses the free `*.azurewebsites.net` HTTPS URL (valid cert —
satisfies the Meta webhook requirement, no domain purchase needed).

## Architecture
- **Azure App Service** (Linux, PHP 8.2) — runs the app; docroot pointed at
  `public/` by `deploy/azure/startup.sh` + `nginx-root-public.conf`.
- **Azure Database for MySQL – Flexible Server** — the `belive_eve` schema.

---

## Phase 1 — Create the two resources (Azure Portal)

### App Service
1. Portal -> **Create a resource** -> **Web App**.
2. Subscription: **Azure for Students**. Create a new Resource Group `belive-rg`.
3. Name: `belive-engine` (URL becomes `https://belive-engine.azurewebsites.net`).
4. Publish: **Code**. Runtime stack: **PHP 8.2**. OS: **Linux**. Region: pick one near you (e.g. Southeast Asia).
5. Pricing plan: **B1 Basic** (needed for Always On + custom startup; ~within student credit). Create.

### MySQL Flexible Server
1. Portal -> **Create a resource** -> **Azure Database for MySQL Flexible Server**.
2. Same subscription + resource group `belive-rg`.
3. Server name: `belive-mysql` (host becomes `belive-mysql.mysql.database.azure.com`).
4. Workload: **Development**. MySQL version 8.0.
5. Authentication: **MySQL authentication**. Admin user `beliveadmin`, set a strong password (save it).
6. **Networking**: enable **Allow public access**, and tick **"Allow Azure services to access this server"** so the App Service can reach it. Add your home IP too if you want to connect from your laptop.
7. Create the database: after the server is up, go to **Databases -> Add** -> name it `belive_eve`.

---

## Phase 2 — Configure the App Service

### Startup command
App Service -> **Configuration -> General settings -> Startup Command**:
```
/home/site/wwwroot/deploy/azure/startup.sh
```

### Application settings (env vars)
The app loads config from a `.env` file (see Phase 4), so App Settings are only
needed for the build. Set one to force a clean composer build:
- `SCM_DO_BUILD_DURING_DEPLOYMENT` = `true`

---

## Phase 3 — Deploy the code from GitHub
1. App Service -> **Deployment Center**.
2. Source: **GitHub** -> authorize -> Org `WYX10`, repo `belive-automation-engine`, branch `main`.
3. Save. Azure adds a GitHub Actions workflow and runs the first build
   (`composer install` runs automatically because `composer.json` is present).

---

## Phase 4 — Create .env on the server (once)
`.env` is git-ignored, so create it directly on the App Service via
**SSH** (App Service -> SSH) or the **Kudu** console
(`https://belive-engine.scm.azurewebsites.net`):

```bash
cd /home/site/wwwroot
cat > .env <<'EOF'
APP_URL=https://belive-engine.azurewebsites.net
APP_ENV=production
APP_ENCRYPTION_KEY=__PASTE_A_FRESH_KEY__

DB_HOST=belive-mysql.mysql.database.azure.com
DB_PORT=3306
DB_NAME=belive_eve
DB_USER=beliveadmin
DB_PASS=__YOUR_MYSQL_PASSWORD__

ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH=__BCRYPT_HASH__

WA_VERIFY_TOKEN=__ANY_RANDOM_STRING__
MOCK_AI=false
OWNER_PORTAL_CODE=belive-owner-2026
EVE_WA_NUMBER=
EOF
```

Generate the two secrets locally and paste them in:
```
php -r "echo base64_encode(random_bytes(32));"          # APP_ENCRYPTION_KEY
php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT);"  # ADMIN_PASSWORD_HASH
```

> Fresh cloud DB = fresh encryption key is fine. You will re-enter the WhatsApp /
> Meta / AI credentials through Admin -> Credentials once (they can't be migrated
> because they were encrypted with the local key).

---

## Phase 5 — Run migrations and load the catalog
In the same SSH/Kudu shell:
```bash
cd /home/site/wwwroot
php database/migrate.php
```
MySQL Flexible Server requires TLS by default; if migrate.php can't connect, add
`?sslmode=require`-equivalent by enabling the server's "Require secure transport
= OFF" toggle for the demo, or configure the CA — simplest for a demo is OFF.

### Load the room catalog
Migrations build the schema but leave it empty, so a fresh cloud DB has no rooms
until these three run — in this order, in the same shell:
```bash
php database/import_room_listings.php database/seeds/room_listings.csv
php database/generate_room_placeholders.php --attach
php database/attach_real_room_media.php
```
1. **Import** — 177 rooms across 47 properties from the listing book.
2. **Placeholders** — gives every photoless room the "photo coming soon" line art
   for its room type. Only ever fills a genuine gap; it skips any room that
   already has an image, so it can't displace real photography.
3. **Real media** — attaches the Emporis and Riamas photography and the three
   DJI tour clips to the 7 rooms they actually depict, retiring those rooms'
   placeholders.

Expect the last script to end with `170 placeholders remaining` — that is the
correct number, one per room nobody has photographed yet, not a failure.

All three are idempotent, so re-run them freely. Step 3 in particular is easy to
forget on a redeploy: the image files ship with the repo, but nothing attaches
them to rooms without it, and the symptom is real units showing "photo coming
soon" on the live site.

---

## Phase 6 — Smoke test
- Visit `https://belive-engine.azurewebsites.net/` (public site) and
  `/admin/login` (admin panel).
- On `/rooms`, search "Riamas" and "Emporis". Those 7 rooms must show real
  photographs — if they show "photo coming soon", step 3 of the catalog load
  didn't run. Every other property is expected to show placeholders.
- In Admin -> Credentials, re-add and activate: WhatsApp, Meta Graph (page_id +
  ig_user_id), and your AI provider key.

---

## Phase 7 — Point integrations at Azure (LAST, only when the above works)
- Meta App dashboard -> WhatsApp webhook -> Callback URL
  `https://belive-engine.azurewebsites.net/webhook/whatsapp`, same verify token,
  re-verify. **Keep the local ngrok setup as a fallback until this is proven.**
- Instagram now works for real here: no ngrok interstitial, so IG image fetches
  succeed. TikTok still needs an approved TikTok app.

## Cron jobs
App Service Linux has no crontab. For the demo, run manually from SSH when
needed, or add a scheduled GitHub Action calling the scripts:
```bash
php cron/auto_draft_content.php
php cron/publish_retry.php
```

### The daily content run is the exception
It keeps its own schedule in `app_settings`, so it does not need any of the
above. Opening Admin → Dashboard or Admin → Content starts the day's run if it
is owed, and the studio card shows the last run, the next slot, and the
**Stop daily drafting** / **Run now** buttons.

To have it run on a day when nobody logs in, set a `CRON_TOKEN` app setting and
point any outside scheduler at the endpoint once or twice a day — it drafts only
when the day's run is still owed, so calling it more often is harmless:
```bash
curl -s "https://belive-engine.azurewebsites.net/cron/auto_draft?token=$CRON_TOKEN"
```
A scheduled GitHub Action with `on: schedule` is the cheapest way to do this
(the repo already deploys from Actions). Without the token the endpoint 404s.
