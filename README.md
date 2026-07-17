# The BeLive Automation Engine

**Eve** — an AI-powered WhatsApp automation system for BeLive (beLive by spacifyOS), built by
Team **GrenA** (TAR UMT Johor Branch) for the **BeLive × TAR UMT AI Solopreneur Challenge 2026**.

Eve runs a three-phase pipeline — **Lead Generation → Conversion → Booking Automation** — with a
genuinely self-learning memory system: feedback is collected, distilled into reusable rules by a
real LLM call, remembered per customer and per context, and applied to future conversations.

## Stack

| Layer | Choice |
|---|---|
| Backend | Vanilla PHP 8.1+ (PSR-4 via Composer, no framework) |
| Database | MySQL / MariaDB |
| Messaging | Meta WhatsApp Cloud API |
| Reasoning / content | Claude API (`claude-sonnet-5` default, `claude-opus-4-8` configurable) |
| Live-chat NLP | Gemini API (`gemini-3.5-flash`) |

## Quick start

```bash
php composer.phar install
copy .env.example .env        # fill in DB credentials + generate keys (see comments)
php database/migrate.php      # runs ALL migrations (catalog + portal tables included)
php -S localhost:8080 -t public public/index.php
```

Open `http://localhost:8080/admin/login`. API keys (WhatsApp, Claude, Gemini) are entered in
**Admin → API Credentials** — encrypted at rest, never in code or git.

Full instructions: [docs/setup_guide.md](docs/setup_guide.md) ·
Demo walkthrough: [docs/judge_demo_script.md](docs/judge_demo_script.md) ·
Endpoints: [docs/api_reference.md](docs/api_reference.md)

## Tests

```bash
php tests/run.php
```
