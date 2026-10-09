# Setup Guide — The BeLive Automation Engine

This guide covers the original local Windows/XAMPP demo and provider-account
setup. For the company installation, hardware/software requirements and the
current Azure + Supabase deployment, use [IT_HANDOVER.md](IT_HANDOVER.md).
The full MCP workflow needs Linux; native Windows uses built-in guidance unless
the bridge's virtualenv launch path is adapted. Preserve the existing encryption
key and data when taking over an installed system.

## 1. Requirements

- PHP 8.1+ (XAMPP's PHP is fine) with `openssl`, `pdo_mysql`, `curl` extensions (XAMPP defaults)
- MySQL / MariaDB running
- Composer installed locally (the Composer binary is not tracked in Git)

## 2. Install

```bash
composer install
copy .env.example .env
```

Edit `.env`:

| Key | What to put |
|---|---|
| `APP_URL` | The public base URL (see §5 for exposing it) |
| `APP_ENCRYPTION_KEY` | `php -r "echo base64_encode(random_bytes(32));"` — **generate only for an empty new installation; preserve the existing key for a migration** |
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

```cmd
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

### Why the website enquiry form opens WhatsApp instead of pushing a message

That same 24-hour window is the reason "Enquire on WhatsApp" hands the visitor a prefilled wa.me
link rather than messaging them out of the blue. A first-time visitor has never messaged our
number, so there is no open window to reply into: Meta refuses the send (error **131047**), or
accepts it and drops it, and either way the customer's phone stays silent. They tap send, the
window opens from their side, and Eve answers on the inbound webhook with the room context already
attached to their lead. A visitor who *has* messaged us in the last 24 hours still gets the instant
AI reply, unchanged.

The other lawful way to open a conversation is an approved **message template** for first contact —
a WABA-side approval (Meta Business Suite → WhatsApp Manager → Message templates), not a code
change. Add template sending to `WhatsAppClient` once a template is approved if you want the push.

**When a message does not arrive, look here first** — both are visible in **Admin → Activity**:
- `wa_send_failed` — Meta refused the send; the row carries its error code and message.
  Common ones: **131047** closed window, **131030** number not in the test-recipient list
  (see step 5 above), **131026** number is not on WhatsApp.
- `wa_delivery_failed` — Meta accepted the send with a message id and then failed to deliver it.
  This only ever arrives on the status webhook, so it is invisible anywhere else.

## 5b. Going live on Facebook / Instagram auto-reply

Someone comments on a beLive post → they get a public reply and a private DM holding a WhatsApp
link → the conversation continues with Eve. Copy and toggles live in **Admin → Social auto-reply**;
everything below is the Meta-side setup.

1. **Prerequisites.** The Instagram account must be a **Professional** account linked to the
   Facebook Page. In the Instagram app: Settings → Privacy → Messages →
   **Allow access to messages** must be ON, or Meta silently never sends message webhooks.
2. **Add the products** to the same Meta app: **Messenger** and **Instagram**. Newer dashboards
   call these *use cases* — "Engage with customers on Messenger" and "Manage messaging & content
   on Instagram" — and each has a **Customize** screen holding its webhooks and permissions.

   **Which Instagram API you are on matters**, because Meta ships two and they take different
   tokens. Check the permission names on the Instagram Customize screen:

   | Permissions look like | API | Token | Credential to add |
   |---|---|---|---|
   | `instagram_business_*` | Instagram Login (`graph.instagram.com`) | Instagram user token | service **instagram** + `ig_user_id` |
   | `instagram_manage_*` | Facebook Login (`graph.facebook.com`) | Page token | service **meta_graph** with `ig_user_id` |

   The engine follows whichever is configured — an active `instagram` credential wins, and covers
   replies, DMs *and* content publishing. See `App\Integrations\Meta\InstagramApi`.

   On the Instagram Login path, get the token from **Generate access tokens → Add account** after
   giving the account the **Instagram Tester** role (Roles tab) and accepting the invite from
   inside Instagram. Business Login (the redirect-URL dialog) is only needed if *other* businesses
   will connect their own accounts — for beLive's own account, skip it.
3. **Register the callback** for each product —
   Callback URL: `https://<your-host>/webhook/meta` · Verify token: your `WA_VERIFY_TOKEN`.
   Subscribe these fields:
   - Messenger (Page): `feed` (comments on posts) and `messages` (DMs)
   - Instagram: `comments` and `messages`
4. **Subscribe the Page to the app** — the step everyone misses, and the reason a correctly
   configured webhook delivers nothing:

   ```
   POST https://graph.facebook.com/v20.0/{page-id}/subscribed_apps
     ?subscribed_fields=feed,messages
     &access_token={page-access-token}
   ```

5. **Credentials**: Admin → API credentials → Add:
   - *Meta Graph — Page token*: the token plus `page_id` (and `ig_user_id` if you are on the
     Facebook-Login Instagram path). Covers Facebook comments, DMs and publishing.
   - *Instagram Login — Instagram user token*: only on the Instagram-Login path, plus the
     Instagram account id.

   Without a usable credential, replies are composed and logged but **not delivered** — the
   Social auto-reply page badges this state `simulated` and names which Instagram path is live.
6. **Set the WhatsApp number** in Admin → Social auto-reply. Without it the link falls back to the
   shared `wa.link` and attribution is lost.
7. Comment on a live post from another account → public reply appears, DM arrives, tapping the
   link opens WhatsApp with the message prefilled. The event and its outcome show up in the
   Answered events table.

**Permissions / App Review.** `pages_messaging` and `instagram_manage_messages` need App Review
before they work for the public. In Development mode they already work for anyone holding a role
on the app — enough for the demo, not enough for real tenants.

**Meta's rules the engine enforces for you**: exactly one private reply per comment (within 7
days), a 24-hour window to answer a direct message, and no reply to our own comments — which
would otherwise loop forever.

## 6. Demo data notes

- Seed room photo URLs are **public placeholders** (picsum.photos) so image sends genuinely work
  out of the box. Before finals, replace them with real BeLive room photos:
  upload images anywhere public, then update `rooms.photos` (JSON array of URLs).
- The Setapak drop-off scenario is pre-seeded so the learning demo can run immediately
  (see `docs/judge_demo_script.md`, Demo 2). The learned rule itself is **not** seeded —
  judges watch it being learned live.

## 7. Background jobs

For dependable publishing, run a supervised
`php cron/content_worker.php --interval=30 --max=10`, or invoke
**POST `/cron/content`** every minute with a private
`Authorization: Bearer <CRON_TOKEN>` header. The HTTP route processes delivery
and dispatches daily drafting. Website traffic alone is insufficient. See
[IT_HANDOVER.md, section 11](IT_HANDOVER.md#11-scheduling-and-background-services)
for supervision, cron examples and Azure alternatives.

| Job | Schedule | Purpose |
|---|---|---|
| `php cron/learning_job.php` | Every 15–30 minutes | Pending feedback and aggregate learning |
| `php cron/memory_decay.php` | Daily | Retire stale low-confidence lessons |
| `php cron/refresh_engagement.php` | Hourly | Update platform engagement snapshots |

The persistent content worker handles delivery/retries and dispatches the day's
creation job separately. For a scheduled CLI `--once` worker, also schedule
`php cron/auto_draft_content.php --if-due`: once mode only processes delivery.
Do not add legacy publisher/retry tasks alongside the chosen worker by default.

Content studio posting times use Asia/Kuala_Lumpur. The automatic daily
scheduling option starts disabled; review its approval policy before enabling.
A due post begins on the next poll, followed by provider processing latency.
A scheduled post on an unconnected account remains queued. Check an uncertain
publish outcome on the provider before retrying.

## 8. Tests

Run these in an isolated local development environment, never with production
credentials. Live simulator calls may invoke real providers when configured.

```bash
php tests/run.php               # rebuilds a throwaway local _test database
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
