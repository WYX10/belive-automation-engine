# Judge Demo Script — The BeLive Automation Engine

**Team GrenA · TAR UMT Johor Branch · BeLive × TAR UMT AI Solopreneur Challenge 2026**

This is the on-stage walkthrough. Every step below runs **live** — real database, real model
calls, real WhatsApp messages. Nothing is mocked or pre-rendered on demo day.

---

## Positioning (say this first)

- BeLive already brands a **"BeLive AI System"** on belive.asia ("24/7 assistance for any issues").
  **Eve is an extension of that existing product line**, not a foreign add-on.
- BeLive's own hero CTA is already a WhatsApp link — **we automated the channel BeLive already
  relies on**, we didn't propose a new one.
- **We checked where BeLive actually lists** — ibilik.my and roomz.asia, not PropertyGuru/iProperty
  (condo/sale-oriented). The portal monitor targets ibilik.my first, with the proposal's original
  portals kept as secondary. Building for the channel they really use is a Marketing Intelligence
  point, not an admission of error.
- The admin dashboard, room matching language ("AI-driven matching") and owner-portal concepts all
  mirror what BeLive already promises publicly — we built into their stack's direction, not around it.

*Known figure discrepancy: the live site says "3,000+ Rooms"; our proposal says 3,500+ spaces. If a
judge raises it, acknowledge the site's number — do not argue.*

---

## Pre-demo checklist (do this the night before AND the morning of)

1. `php database/migrate.php` on a fresh database, then `php database/seed.php`.
2. Real API keys entered in **Admin → API credentials**, each showing **✓ ok** after *Test & activate*:
   Meta WhatsApp (with phone_number_id), Claude (Anthropic), Gemini.
3. `.env` has `MOCK_AI=false`. **Never demo with the offline stub.**
4. Webhook registered & verified in the Meta App dashboard (see setup guide §5).
5. Replace picsum placeholder photo URLs in `rooms` with real BeLive room photos (setup guide §6).
6. Send one WhatsApp test message from a team phone; confirm a real reply arrives.
7. `php tests/run.php` → all green. Keep the output visible in a terminal as fallback proof.

---

## Demo 0 — The front door: public website → channel #2 live (≈2 min)

Start where a real tenant starts: **open `/` (the public site)** on the projector.

1. Home page: BeLive's own voice, stat strip, featured rooms — every card shows **all three
   tenure prices** (flexible monthly / 6-month / 12-month+ best value) and **RM 0 deposit**.
   *"No hidden fees, no surprises"* — nothing is hidden behind an enquiry.
2. Open a room (e.g. RM-107, M Vertica) → pricing table with the 12-month saving-vs-flexible
   figure → fill the enquiry form with a judge's number, pick a tenure, submit.
3. Seconds later the judge's WhatsApp receives Eve's follow-up **that already knows the exact room
   and tenure they were looking at** — show the lead in Admin → Leads with `enquired room` and
   `preferred tenure` filled, and the `room_recommendation` row in the activity log naming the
   model. That context-carrying handoff is proposal channel #2, working.

## Demo 1 — The four AI skills, live (≈3 min)

Judge sends a WhatsApp message to Eve's number, e.g.
*"Hi, I'm a student looking for a room in Cheras, budget around RM600."*

While the reply arrives (seconds), show **Admin → Chat history**:

1. **Understands** — the inbound row shows extracted intent + entities (location=Cheras,
   budget=600, profile=student) with the model's own reasoning line.
2. **Decides** — the internal decide row: qualified ✓, closing probability, matched rooms
   (students get budget rooms — situational, not scripted; ask a judge to try
   *"I'm a working professional"* and watch the matching change).
3. **Creates** — the reply is in BeLive's own voice (short, benefit-first, sentence case).
4. **Automates** — lead status flipped to qualified on **Admin → Leads**, assessment panel filled,
   all with zero human touch.

Point at **Admin → Activity log**: every action names the model that handled it. Switch the
conversation model in **Admin → AI models** and show the next reply logs the new model —
model-swapping is live, per interaction.

## Demo 2 — Self-learning, the Setapak scenario (≈4 min) — the 20-point demo

The database is pre-seeded with the exact scenario from our proposal: three Setapak enquiries
that went silent right after a price quote.

1. Judge (or team phone) sends: *"Any medium room in Setapak? Budget RM700."*
   → Eve replies **price-first** (current default). Show the reply tagged `price_quote` in chat history.
2. Run the learning job in the terminal, projected:
   ```
   php cron/learning_job.php
   ```
   Output shows: `drop-off patterns detected: 1` → `rules learned: 1`.
3. Open **Admin → Learning log**: a new row — *Mistake: poor sequencing (drop-off pattern, Setapak)
   → Rule learned: "For Setapak enquiries, send room photos before quoting the price."* This rule
   text was written by the model, live, seconds ago — click through to rule detail to show the
   source feedback.
4. Judge sends another Setapak enquiry from a different number:
   → **photos arrive first, price is held back.** Chat history shows `memory: [rule id]` on the reply.

**Eve changed her own conversation flow from observed customer behaviour.** No code changed, no
restart, no human wrote the rule.

## Demo 3 — Instant correction loop (≈2 min)

1. Judge asks something Eve gets wrong (or pick any reply), click **Flag as incorrect** in chat
   history, type the correction (e.g. *"Price for that room is RM650, not RM900"*).
2. The flash message shows the rule the model just distilled — **synchronously, not queued**.
3. Judge re-asks the same question → the corrected answer, immediately.

Also try the customer-side path: reply to Eve with *"that's wrong, the price is RM650"* — Eve files
the correction herself and honours it **in that very same reply**.

## Demo 4 — Memory: the returning customer (≈1 min)

Seeded lead **Daniel Wong** enquired about Sentul two days ago. Message from his number:
*"Hi, me again."* → Eve opens with his name and prior search
(*"Still looking for that medium room in Sentul…"*) — recalled from his lead history, never a
generic "hi again".

## Demo 5 — Zero-touch booking + Refer & Earn (≈2 min)

1. In the owner portal, submit a property. Show that **Add room** is locked while its status is
   **Pending admin review**. In **Admin → Property reviews**, approve it; return to the owner page
   and add a room with its referral-point value and room photo. Then open **Admin → Rooms** to show
   that admin can edit the owner-uploaded room, add another room to the property, and maintain its
   photo gallery. A rejected property shows the admin reason and an
   owner-only **Correct and resubmit property** form; it returns to pending and remains room-locked.
2. Continue any qualified conversation with *"Can I view it tomorrow 3pm?"*
   → Eve parses the natural-language time, cross-checks the live schedule, books, and sends a
   confirmation receipt. Show **Admin → Bookings** — the row appeared with zero admin input.
   (Try a clashing time to show the conflict handler proposing the nearest free slot.)
3. Show a lead's **Refer & Earn link** (Admin → Leads → open lead). Open it, submit the enquiry
   form as a "friend", book — the referrer is credited the booked room's owner-configured points automatically and gets a WhatsApp
   notification. Show the `referral_reward_credited` row in the activity log.

## Demo 6 — Lead channels + content (≈2 min)

- **Website form** (`/enquiry`): submit → instant AI WhatsApp follow-up (channel #2).
- **Manual intake** (Admin → Leads → Manual intake): log a TikTok/PropertyGuru enquiry — identical
  downstream AI pipeline (see honest-limitations below).
- **Content studio** (Admin → Content): pick a room, generate — an on-brand caption from live room
  metrics, by the model shown on the draft. Approve → mark posted → dashboard count updates.

---

## Honest limitations (say these before judges ask)

| Item | Status | Why |
|---|---|---|
| TikTok DM/comment capture | **Manual-intake fallback** | TikTok's official API does not allow third-party DM/comment webhook capture. Enquiries are logged in one form; everything downstream (scoring, conversion, memory, booking) is identical to automated channels. |
| Listing portal monitoring (ibilik.my primary, roomz.asia, PropertyGuru/iProperty secondary) | **Manual-intake fallback** | We checked where BeLive actually lists (ibilik.my/roomz.asia) and retargeted the monitor accordingly, keeping the proposal's named portals as secondary. None of the four exposes a public inbound-enquiry API for third parties — enquiries only surface inside their own agent apps. Same fallback pattern, identical downstream pipeline. |
| Social post publishing | **Semi-automated** | Caption generation is fully automated from live room data. Meta page publishing requires a reviewed Meta app (out of RM 500 / 2-week scope); TikTok has no third-party publish API. Flow: generate → approve → paste → mark posted. Integration-ready for the Meta publish API. |
| AI-guided 3D virtual tours | **Descoped — post-competition roadmap** | RM 500 leaves no room for 3D asset creation or tour hosting, and a from-scratch build would starve the self-learning system (20 pts) of build time. We chose to descope it openly rather than fake a static viewer. |
| Tenant/Owner portals (Phase 9) | **Bonus beyond the written proposal** | Software-only concepts from our Sabah market research (verified listings, move-in logs, agreements, fair pricing). Framed as a deliberate extension, not something that was always in the submission. The listing verifier uses admin document review + GPS match, **not a real eKYC API**; the agreement flow is a typed-name acknowledgement, **not a cryptographic e-signature**. |
| IoT-fed owner panels | **Integration-ready, labelled sample data** | BeLive genuinely runs smart meters/locks; we build zero hardware. Panels ship with clearly-labelled sample values and a documented input contract — never a simulated live sensor feed. |
| Tenant electricity view (`/tenant/electric`) | **Real stored bills; readings are recorded, not live** | Every figure is read back from `electric_bills` — issued once from two meter readings and stored complete, so the tenant can check both readings against the dial. Each room has its own meter, which is why a tenant can be shown their own usage at all. The readings themselves are entered or imported from BeLive's existing meters (`reading_source`); the page never polls hardware, and the demo bills come from `php database/seed_electric_bills.php`. |

## If the live demo misbehaves

Run `php tests/run.php` on the projector: the full automated regression suite proves the learning loop end-to-end
(feedback → real rule distilled → retrieval honours confidence/active state) against a throwaway
database created on the spot. This is the fallback proof that nothing is faked.
