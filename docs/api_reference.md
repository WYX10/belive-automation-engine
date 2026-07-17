# API Reference — The BeLive Automation Engine

All requests route through the front controller (`public/index.php`); the `.htaccess` (Apache) or
CLI-server router script funnels every path through it. Webhooks do **not** bypass the router —
single entry point, one bootstrap.

## Public endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `/` | Public homepage — hero, stat strip, featured rooms, locations (the judges' front door) |
| GET | `/rooms` | Room listing with filters: `location`, `room_type` (single/middle/master), `tenure` (monthly/6_month/12_month), `max_price` (applied at the chosen tenure) |
| GET | `/rooms/{id}` | Room detail: gallery, amenity list, tenure pricing table with best-value + saving-vs-flexible, enquiry form |
| POST | `/enquire` | Room enquiry (channel #2): `name`, `wa_phone`, `room_id`, `tenure`, `message?` → lead with room context + instant AI WhatsApp follow-up |
| GET | `/health` | Liveness check — JSON `{ok, app, time}` |
| GET | `/webhook/whatsapp` | Meta verification handshake (`hub.mode`, `hub.verify_token`, `hub.challenge`) — echoes the challenge iff the token matches `WA_VERIFY_TOKEN` |
| POST | `/webhook/whatsapp` | Meta event receiver. `object=whatsapp_business_account` → conversation pipeline; `object=page/instagram` → comment capture. Always answers 200 immediately, then processes |
| GET | `/webhook/verify` | Same handshake handler at its spec-fixed path |
| GET/POST | `/enquiry` | Website smart enquiry form (channel #2). POST fields: `name`, `wa_phone`, `message`, optional `ref_code`. Creates a lead + instant AI WhatsApp follow-up |
| GET | `/r/{code}` | Refer & Earn share link — counts the click, forwards to `/enquiry?ref={code}` |

### Inbound WhatsApp payload (what the simulator reproduces)

```json
{
  "object": "whatsapp_business_account",
  "entry": [{ "changes": [{ "field": "messages", "value": {
    "contacts": [{ "profile": {"name": "Aina"}, "wa_id": "60171112222" }],
    "messages": [{ "from": "60171112222", "id": "wamid...", "timestamp": "…",
                   "type": "text", "text": {"body": "Any room in Setapak?"} }]
  }}]}]
}
```

## Admin panel routes (session auth; login at `/admin/login`)

| Method | Path | Purpose |
|---|---|---|
| GET | `/admin/dashboard` | Proposal-mockup stat cards + live lead detail |
| GET | `/admin/leads` · `/admin/leads/view?id=` | Lead list (status/channel filters) · lead detail with AI assessment, transcript, referral link |
| GET/POST | `/admin/leads/add` → `/webhook/tiktok_fallback` | Manual intake (TikTok / PropertyGuru / iProperty fallback) |
| GET | `/admin/chat_history?lead_id=` | Conversation threads with per-reply model + reasoning |
| POST | `/admin/chat_history/flag` | Flag a reply (`interaction_id`, `error_type`, `comment`) → **synchronous** rule learning |
| GET | `/admin/learning_log` · `/admin/learning_log/rule?id=` | Mistake \| Correction \| Rule \| Reinforced \| Status · rule detail with source feedback + shaped replies |
| GET | `/admin/activity_log` | Every AI action with the model that handled it |
| GET/POST | `/admin/content` · `/admin/content/preview?id=` | Generate caption drafts from room metrics · approve / mark posted |
| GET/POST | `/admin/bookings` | Zero-touch bookings; complete/cancel controls |
| GET/POST | `/admin/credentials` · `/admin/credentials/add` · POST `/admin/credentials/test` | Encrypted key management, masked display, test-before-activate |
| GET/POST | `/admin/models` · POST `/admin/models/switch` | Per-phase model assignment (registry-driven) |

All admin POSTs require the session CSRF token (`csrf_token` field, provided by every form).

## CLI entry points

| Command | Purpose |
|---|---|
| `php database/migrate.php [--core-only]` | Create DB + run ALL migrations (`--core-only` skips the Phase 9 portal tables) |
| `php database/seed.php [--force]` | Load demo rooms + the Setapak scenario |
| `php cron/learning_job.php [--quiet-hours=4]` | Drop-off pattern detection + batch rule distillation + reinforcement |
| `php cron/memory_decay.php [--stale-days=30]` | Confidence decay + below-threshold retirement |
| `php tests/run.php` | 23-check learning/retrieval suite on a throwaway DB |
| `php tests/concurrency_test.php [--url=]` | 6 simultaneous conversations, isolation assertions |
| `php tests/simulate_whatsapp.php "<text>" [phone] [name] [--url=]` | One Meta-shaped inbound message |

## Key internal surfaces (for the team)

- `App\AI\ModelRouter::clientForPhase(string $phase): LlmClient` — the only way skills obtain a
  model client; admin model-swaps apply on the next call. `MOCK_AI=true` swaps in the labelled
  offline stub (local testing only).
- `App\AI\Skills\{UnderstandSkill, DecideSkill, CreateSkill, AutomateSkill}` — the four judged
  skills; every call logs to `ai_interactions` (with reasoning) via `EpisodicLogger` and to
  `ai_activity_log` (with model).
- `App\AI\Memory\MemoryRetriever::forContext(?string $tag)` /
  `MemoryStore::saveRule(...)` — read/write sides of `ai_learned_memory`.
- `App\AI\Memory\FeedbackCollector` — `adminFlag()`, `detectImplicit()`, `detectDropoffPatterns()`.
- `App\AI\Memory\LearningEngine::processFeedback(int $id): ?int` — one feedback row → one distilled
  rule (real model call; never hardcoded).
- `App\Pipeline\Conversion\ConversationManager::handleInbound(array $message)` — the full
  Understand → Decide → Create → Automate pipeline with memory + booking handoff.

## Database (dev/demo names)

Default schema name: `belive_eve` (set via `DB_NAME` in `.env`). Tables:
`api_credentials`, `ai_model_config`, `leads` (incl. `enquired_room_id` + `preferred_tenure`),
`ai_interactions`, `ai_feedback`, `ai_learned_memory`, `ai_activity_log`, `content_posts`,
`rooms` + `bookings` (009, extended by 014), `referrals`, `room_pricing` / `room_images` /
`room_amenities` (015–017, Phase 6.5 catalog), `migrations` (runner bookkeeping),
plus the portal tables `verified_listings`, `move_in_logs`, `digital_agreements` (011–013,
included in a default migrate run).

Catalog surfaces: `App\Catalog\RoomRepository` (all page reads), `PricingCalculator`
(price per tenure + saving vs flexible), `RoomRecommender` (candidate block for DecideSkill;
recommendations logged to `ai_activity_log` as `room_recommendation` with the model).
