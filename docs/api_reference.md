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
| POST | `/webhook/whatsapp` | Meta event receiver. `object=whatsapp_business_account` → conversation pipeline; `object=page/instagram` → comment/DM capture + social auto-reply. Always answers 200 immediately, then processes |
| GET/POST | `/webhook/meta` | The same handshake and receiver at a second URL, so the Messenger and Instagram products can be configured separately in the Meta App dashboard |
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
| GET/POST | `/admin/content` · `/admin/content/preview?id=` | Generate caption drafts — or a rendered 9:16 promo video — from room metrics, mascot-branded and carrying `#BeLiveSolopreneur` · preview the reel and its shot list · one-week best-time-to-post heatmap built from our own engagement (`?heat=facebook\|instagram\|tiktok`) · approve → publish now, or schedule for a suggested slot and let the publisher cron post it |
| GET/POST | `/admin/engagement` | Per-post engagement: viewers, likes, comments and shares read from each platform's own API, with daily snapshots and a Refresh button (`?platform=facebook\|instagram\|tiktok`). A metric the platform did not return shows as "—" with the reason, never as a zero |
| GET | `/admin/reports` · `/admin/reports/export` | Performance report for a period (`?days=7\|30\|90\|365`, `?platform=`): totals, per-channel and photo-vs-reel breakdowns, top posts, studio throughput, and the content → comment → WhatsApp → booking funnel · CSV download of the same figures |
| GET/POST | `/admin/social` | Social auto-reply: on/off, who gets a DM, the WhatsApp number and link prefill, the three reply templates, and the answered-events receipt (which comment, which reply, who actually landed on WhatsApp) |
| GET/POST | `/admin/bookings` | Zero-touch bookings; complete/cancel controls |
| GET/POST | `/admin/staff` | Viewing-staff roster: weekly shifts, time off, per-agent viewing modes and daily caps; 7-day coverage grid and manual assignment of unstaffed viewings |
| GET/POST | `/admin/property_reviews` | Review owner-submitted properties; approve before room creation or reject with an owner-facing reason |
| GET/POST | `/admin/rooms` | Drill down property → house → room (`?property=` / `?unit=`): add/rename houses, add/edit rooms inside a house, see who is renting each room, and upload validated gallery photos |
| GET | `/admin/listing_reviews` | Filterable queue for pending, verified and rejected ownership/location reviews |
| GET/POST | `/admin/listing_reviews/view?id={id}` | Open evidence, approve/reject ownership or GPS, and record reviewer notes |
| GET/POST | `/admin/credentials` · `/admin/credentials/add` · POST `/admin/credentials/test` | Encrypted key management, masked display, test-before-activate |
| GET/POST | `/admin/models` · POST `/admin/models/switch` | Per-phase model assignment (registry-driven) |

All admin POSTs require the session CSRF token (`csrf_token` field, provided by every form).

## CLI entry points

| Command | Purpose |
|---|---|
| `php database/migrate.php [--core-only]` | Create DB + run ALL migrations (`--core-only` skips the Phase 9 portal tables) |
| `php database/seed.php [--force]` | Load demo rooms + the Setapak scenario |
| `php database/seed_electric_bills.php` | Idempotent: a tagged demo tenant with a room meter and six months of bills for `/tenant/electric` |
| `php cron/learning_job.php [--quiet-hours=4]` | Drop-off pattern detection + batch rule distillation + reinforcement |
| `php cron/memory_decay.php [--stale-days=30]` | Confidence decay + below-threshold retirement |
| `php cron/publish_scheduled.php [--max=10] [--dry-run]` | Publish content posts whose scheduled slot has arrived (`--dry-run` lists the queue without sending) |
| `php cron/publish_retry.php [--max=10]` | Retry approved posts whose platform publish errored |
| `php cron/refresh_engagement.php [--max=25] [--stale=180] [--dry-run]` | Poll the platforms for viewers/likes/comments/shares on published posts (`--dry-run` lists the queue without calling out) |
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
- `App\AI\Skills\SkillSupport::generateJson(...)` — every skill whose answer must be JSON goes
  through this: it asks the provider for its native JSON mode (`json => true` — Anthropic gets an
  assistant prefill, Gemini `responseMimeType`, OpenAI `response_format`), repairs an answer cut
  off by the token budget, and retries once before giving up. A phase running on defaults because
  its model's output could not be read shows in `ai_activity_log` as `model_json_retry`,
  `model_json_failed` or `decision_fallback` rather than passing silently.
- Photo sends are two separate decisions. `send_photos_first` is the one-time show-the-room-
  before-the-price opener (suppressed once pricing has come up); `send_photos` is the plain answer
  to "can I see it?" and survives that suppression. `AutomateSkill` also honours a reply that
  promises photos, so a message never claims a photo it did not send.
- `App\AI\Memory\MemoryRetriever::forContext(?string $tag)` /
  `MemoryStore::saveRule(...)` — read/write sides of `ai_learned_memory`. A null tag (area not
  known yet) retrieves the `general` rules only — never another area's.
- `App\AI\Memory\FeedbackCollector` — `adminFlag()`, `detectImplicit()`, `detectDropoffPatterns()`.
- `App\AI\Memory\LearningEngine::processFeedback(int $id): ?int` — one feedback row → one distilled
  rule (real model call; never hardcoded).
- `App\Pipeline\Conversion\ConversationManager::handleInbound(array $message)` — the full
  Understand → Decide → Create → Automate pipeline with memory + booking handoff.
- `App\Properties\RoomPhotoManager::addUpload(...)` — owner-scoped or admin room-gallery upload;
  accepts JPG/PNG/WebP images up to 5 MB and stores randomized public paths in `room_images`.
- `App\Models\ElectricBill::registerMeter(...)` / `::issue(array $bill)` — write side of the
  per-room submeter: one meter per room, and one bill per period computed as
  `(current − previous) × rate + standing charge`, with the rate snapshotted onto the row.
  `::summaryForTenant(int $leadId)` is the read side `/tenant/electric` renders; both scope by
  `lead_id`, so a new tenant never sees the previous occupant's consumption. Overdue is derived
  (`::isOverdue`), never stored.
  `::latestForOwnerRooms(string $ownerName)` feeds the owner dashboard's Utilities & access
  panel: every room the owner has, the house it sits in (`property_units`), and the newest
  closed billing period on its meter. It deliberately does **not** join `leads` — an owner sees
  the room's consumption, never who was billed for it. Door access has no data source yet and
  is left blank behind the documented `{room_code, last_access_at}` contract.

## Database (dev/demo names)

Default schema name: `belive_eve` (set via `DB_NAME` in `.env`). Tables:
`api_credentials`, `ai_model_config`, `leads` (incl. `enquired_room_id` + `preferred_tenure`),
`ai_interactions`, `ai_feedback`, `ai_learned_memory`, `ai_activity_log`, `content_posts`,
`rooms` + `bookings` (009, extended by 014), `referrals`, `room_pricing` / `room_images` /
`room_amenities` (015–017, Phase 6.5 catalog), `referral_redemptions` (021 rent-credit
request ledger), `properties` plus room-level `referral_reward_points` and referral
`reward_room_id` attribution (022), immutable referral attribution snapshots (023), property
admin-review status and audit fields (024), stale-decision review versions (025),
`property_units` — the house level between a property and its rooms, with `rooms.unit_id`
(039), `migrations` (runner bookkeeping),
per-room electricity submetering `electric_meters` + `electric_bills` (034, read by the tenant
portal's electricity view),
plus the portal tables `verified_listings`, `move_in_logs`, `digital_agreements` (011–013,
with listing review/audit extensions in 018–019 and nullable structured agreement `tenure`,
`starts_on`, and `ends_on` fields in 020; all are included in a default migrate run). Legacy
agreements remain undated rather than receiving inferred contract dates.
The agreement signing workflow (035) adds the stage machine (`status`, `stage_version`), the
snapshotted `monthly_rent_rm` / `deposit_rm`, the landlord's particulars — NRIC and bank
account number encrypted at rest, last four digits in the clear — and `agreement_events`,
one row per hand-off. `renewal_offers` (040) hangs off a completed agreement: the promotional
rent an owner offers a tenant whose term is nearly up, with the snapshotted `current_rent_rm`,
the proposed term, and the tenant's answer.

### Social auto-reply (036)

`social_replies` records every FB/IG comment or DM Eve has answered, one row per event, with a
unique key on `(platform, object_id)`. That key is load-bearing twice over: Meta redelivers
webhooks, and Meta allows exactly **one** private reply per comment — a second attempt is an API
error, so the row is claimed before anything is sent. `leads.merged_into_lead_id` points a social
lead at the WhatsApp lead it became.

Flow: `CommentWebhookParser` / `MessageWebhookParser` normalize the payload → `CommentScanner`
captures the lead (comments are filtered to rental enquiries, a DM never is) → `CommentResponder`
sends the public reply and the private reply → `MetaMessenger` is the transport, with the same
dry-run behaviour as `WhatsAppClient` when no usable credential is active.

`InstagramApi` resolves which of Meta's **two** Instagram APIs an install talks to, because they
are not interchangeable: an app set up for Instagram Login uses `graph.instagram.com` with an
Instagram user token (`instagram_business_*` permissions), while the older Facebook Login path
uses `graph.facebook.com` with the Page token (`instagram_manage_*`). An active `instagram`
credential (038) selects the former and covers replies, DMs and content publishing alike; with
only `meta_graph` present, every Instagram call behaves exactly as it did before. Facebook is
unaffected either way — it is always the Page token.

Attribution closes the loop: the `wa.me` link carries a single-use token (`BL7A3F2C`) inside the
prefilled first message. `SocialRefMerger` redeems it on the first inbound WhatsApp message,
moves the social lead's history onto the real lead, marks the lead's `source_channel` as
`social`, and strips the code before the AI ever reads the text. A forwarded link is just a
normal new lead — the token is spent.

### Agreement workflow (035)

An agreement travels `draft` → `owner_review` → `admin_review` → `tenant_review` →
`completed` (`cancelled` is available to admin from any live stage). Admin drafts it once a
tenant has a confirmed booking against a room; the owner supplies their particulars and
signs; admin checks the returned document and releases it; the tenant signs. Admin can send
it back to the owner (which voids the owner's signature) and the tenant can ask for a change
instead of signing (which returns it to admin).

`App\Agreements\AgreementWorkflow` owns every transition and enforces two rules: a stage can
only be reached from the stage before it, and each form carries the `stage_version` it was
rendered from, so a stale tab cannot overwrite newer state. `DigitalAgreementGenerator`
writes the body with `{{LANDLORD_NAME}}`-style tokens — a model is never asked to guess an
NRIC or an account number — and `AgreementRenderer` merges the owner's stored particulars in
at read time, appending Schedule A and the execution block deterministically. Screens:
`/admin/agreements`, `/admin/agreements/view`, `/owner/agreements`, `/tenant/agreement`.

### Renewal offers (040)

`/owner/tenancies` ("Tenants & renewals") lists every rental period the owner has running —
tenant, property · house · room, start and end date, days left and a progress bar — read from
completed agreements via `DigitalAgreement::tenanciesForOwner()`. Only a `completed` agreement
counts as a tenancy; anything earlier is a document still being signed.

In the last `DigitalAgreement::ENDING_SOON_DAYS` (30) of a tenancy the owner can offer that
tenant a promotional rent for a new term. The same constant drives the `ending_soon` timeline
badge, so the countdown and the offer button always agree.
`App\Renewals\RenewalOfferManager` owns the rules: the tenancy must be `ending_soon` or
`ending_today`, the promo must be **below** the rent snapshotted on the agreement, the offer
expires on or before the tenancy's end date, and only one offer per tenancy may be open at a
time (checked under `FOR UPDATE`). The new term starts the day after the current one ends.

The tenant sees it on `/tenant/agreement` (teaser on `/tenant/dashboard`) and answers with
`accepted` / `declined`; `::respond()` scopes by `lead_id`, so only the person the offer was
made to can answer it. **Accepting records intent only** — it never edits `digital_agreements`.
BeLive drafts the renewal agreement afterwards through the usual admin → owner → tenant round
trip. `expired` is derived (`RenewalOffer::isOpen`), never stored, and `current_rent_rm` is
snapshotted so re-pricing the room cannot rewrite a saving the tenant has already been shown.

### Tenant's own room (`/tenant/my_room`)

"My room" is the tenant-side mirror of `/owner/tenancies`: the room they rent, how long they
have it for, and what else is free in their area.

Which room that is comes from `DigitalAgreement::currentForTenant()` — their **signed**
agreement (`completed`, both dates set), never a viewing they once booked, with the room's
development and house joined on. A tenant can hold several tenancies over time, so the one
closest to today wins: live, then about to start, then most recently ended.
`tenant_tenancy()` (in `public/_portal_layout.php`) wraps that and falls back to the booked
room for somebody whose agreement is still being drafted — with **no dates**, because an
unsigned document has no term to count down. The countdown itself is the shared
`DigitalAgreement::timeline()` block, so the dashboard, the agreement page, this page and the
owner's tenancy list can never disagree; `::termLabel()` names the whole term ("12 months").

Recommendations come from `RoomRepository::similarInArea()` and are **strictly same-area**:
unlike `Room::matches()` it never widens the search, so an empty list is the honest answer
rather than a room across town. Available rooms only, the tenant's own room excluded, ranked
same house → same development → same room type → closest rent to what they actually pay
(the rent snapshotted on their agreement, falling back to the room's listed rate), priced at
the tenure they signed for. `RoomRepository::findWithHierarchy()` is the room read behind both
halves. Proven by `tests/TenantRoomViewTest.php`.

Catalog surfaces: `App\Catalog\RoomRepository` (all page reads, incl. `findWithHierarchy` /
`similarInArea`), `PricingCalculator` (price per tenure + saving vs flexible), `RoomRecommender`
(candidate block for DecideSkill; recommendations logged to `ai_activity_log` as
`room_recommendation` with the model).
