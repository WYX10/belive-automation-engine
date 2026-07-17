# Setup Guide — The BeLive Automation Engine

From zero to a live, judge-testable system. Windows/XAMPP instructions (the team's environment);
any PHP 8.1+ + MySQL/MariaDB host works the same way.

## 1. Requirements

- PHP 8.1+ (XAMPP's PHP is fine) with `openssl`, `pdo_mysql`, `curl` extensions (XAMPP defaults)
- MySQL / MariaDB running
- Composer (a `composer.phar` is checked into the project root — `php composer.phar` works without
  a global install)

## 2. Install

```bash
php composer.phar install
copy .env.example .env
```

Edit `.env`:

| Key | What to put |
|---|---|
| `APP_URL` | The public base URL (see §5 for exposing it) |
| `APP_ENCRYPTION_KEY` | `php -r "echo base64_encode(random_bytes(32));"` — **generate a fresh one, never reuse the example** |
| `DB_*` | Your MySQL credentials (XAMPP default: root, empty password) |
| `ADMIN_USERNAME` / `ADMIN_PASSWORD_HASH` | Login for the admin panel. Hash: `php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT);"` |
| `WA_VERIFY_TOKEN` | Any random string — you'll paste the same value into the Meta dashboard in §5 |
| `MOCK_AI` | `false`. (`true` swaps in a clearly-labelled offline stub for pipeline testing without API keys — **never for the demo**.) |

## 3. Database

```bash
php database/migrate.php     # creates the DB + ALL tables (incl. catalog + portal tables)
php database/seed.php        # demo rooms (real BeLive media) + the Setapak scenario
```

## 4. Run

Development / demo-day local:

```bash
set PHP_CLI_SERVER_WORKERS=6
php -S 0.0.0.0:8080 -t public public/index.php
```

Apache/XAMPP production-style: point a vhost's DocumentRoot at `public/` — `.htaccess` routes
everything through the front controller.

Log in at `http://localhost:8080/admin/login`.

## 5. Going live on WhatsApp (the part that can't be done on localhost alone)

The system is fully built for the Meta WhatsApp Cloud API; these are the go-live steps that need
Meta-side setup and a public URL:

1. **Meta app**: developers.facebook.com → create a Business-type app → add the **WhatsApp** product.
   The API Setup page gives you a **temporary access token**, a **test phone number**, and its
   **Phone number ID**.
2. **Expose the webhook publicly.** Any of:
   - a tunnel for testing: `ngrok http 8080` (or Cloudflare Tunnel — free), or
   - real hosting: any RM-cheap PHP host with MySQL works; upload, point DocumentRoot at `public/`.
   Set `APP_URL` in `.env` accordingly.
3. **Register the webhook**: Meta App → WhatsApp → Configuration →
   Callback URL: `https://<your-host>/webhook/whatsapp` · Verify token: the exact `WA_VERIFY_TOKEN`
   from `.env` → Verify and save (the app answers Meta's `hub.challenge` automatically).
   Subscribe to the **messages** webhook field.
4. **Enter credentials in the panel**: Admin → API credentials → Add:
   - service *Meta WhatsApp Cloud API*: paste the access token + the Phone number ID → **Test & activate**
   - service *Claude (Anthropic)*: key from console.anthropic.com → **Test & activate**
   - service *Gemini (Google)*: key from aistudio.google.com → **Test & activate**
   All keys are AES-256-GCM encrypted at rest; the panel only ever shows the last 4 characters.
5. **Test numbers**: with a temporary token, Meta only delivers to numbers added under
   "To" recipients on the API Setup page — add all team + judge demo phones there
   (or complete business verification for an unrestricted permanent token).
6. Message the test number from a real phone → Eve replies. You're live.

**Free-tier note (budget)**: replies inside the 24-hour customer-service window to user-initiated
messages are free on the Cloud API — the demo flow costs nothing per message.

## 6. Demo data notes

- Seed room photo URLs are **public placeholders** (picsum.photos) so image sends genuinely work
  out of the box. Before finals, replace them with real BeLive room photos:
  upload images anywhere public, then update `rooms.photos` (JSON array of URLs).
- The Setapak drop-off scenario is pre-seeded so the learning demo can run immediately
  (see `docs/judge_demo_script.md`, Demo 2). The learned rule itself is **not** seeded —
  judges watch it being learned live.

## 7. Cron jobs (production)

| Job | Schedule | Purpose |
|---|---|---|
| `php cron/learning_job.php` | every 15–30 min | drop-off pattern detection (aggregate), batch rule distillation, rule reinforcement |
| `php cron/memory_decay.php` | daily | decay stale rules, retire below-threshold ones |

Windows Task Scheduler or crontab both work — plain CLI PHP scripts. (The synchronous learning
path — admin flags and customer corrections — needs no cron at all.)

## 8. Tests

```bash
php tests/run.php               # 23 checks: retrieval filtering + real learning loop (throwaway DB)
php tests/concurrency_test.php  # 25 checks: 6 simultaneous conversations, context isolation
php tests/simulate_whatsapp.php "any room in Cheras?" 60123456789 "Tester"   # one simulated inbound
```

The simulator posts genuine Meta-formatted payloads at the local webhook — the full pipeline runs
exactly as it would from a real phone (with no WhatsApp credential configured, outbound sends are
logged as clearly-labelled dry-run rows in the activity log instead of being delivered).

## 9. Troubleshooting

- **"No active API credential for provider …"** — add + *Test & activate* the key in Admin → API credentials.
- **Webhook verification fails** — `WA_VERIFY_TOKEN` in `.env` must exactly match the Meta dashboard value.
- **Replies not arriving on a phone** — with a temporary token, the recipient must be in the Meta
  test-recipient list (§5.5); also check Admin → Activity log for `wa_dry_run_send` rows (means no
  active WhatsApp credential) or `webhook_error` rows.
- **DB connection refused** — start MySQL in the XAMPP control panel; check `DB_*` in `.env`.
