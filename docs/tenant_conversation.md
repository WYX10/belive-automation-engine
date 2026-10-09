# Tenant conversations and marketing MCP

Eve stores each tenant's current requirements in `tenant_requirements`, keyed by
the existing lead ID. Area, budget, move-in date, room type, tenure, profile,
occupant count, amenities and preferences survive beyond the recent transcript.
Only explicit new details update this profile. Absent fields retain their
values; explicit withdrawals clear them. Existing lead fields stay synchronized
for the catalog and portals. Admin → Leads → View displays the saved profile.

## Install and run

Use PHP 8.1+ with the repository's extensions, Composer, MariaDB/MySQL, Python
3.10+, and [uv](https://docs.astral.sh/uv/). For the prepared cloud environment,
activate PHP with `source /workspace/.belive-cloud/activate` first.

From `/workspace/belive-automation-engine`:

```bash
composer install --no-interaction --prefer-dist
uv sync --project mcp/tenant_marketing --frozen
php database/migrate.php
php -S 127.0.0.1:8080 -t public public/index.php
```

The MCP uses the official [Model Context Protocol Python SDK](https://github.com/modelcontextprotocol/python-sdk),
pinned in `pyproject.toml` and `uv.lock`. Eve starts it on demand over stdio through
the official SDK client; no network listener or extra running service is needed.
The server receives requirement and inventory data, without the tenant's lead
ID, name, phone number, database credentials, or API keys. It cannot write the
database or send messages. No third-party marketing service is contacted.

Tools are `qualify_tenant`, `match_room_benefits`, `handle_rental_objection`, and
`tenant_marketing_plan`. The plan goes into both decision and reply prompts.
It explains verified benefits, respects budget and tenure, discloses mismatches,
and handles budget, location, trust and tenure objections without invented
discounts or urgency. A prompt named `tenant_conversation_guidance` explains
their use. `mcp/tenant_marketing/mcp.json` is a connection example for other MCP
hosts; adjust absolute paths outside this cloud checkout.

If the MCP runtime is missing or fails, Eve records a fallback and uses built-in
guidance. `MARKETING_MCP_ENABLED=false` explicitly disables the MCP bridge.
Keep it enabled for the normal workflow and run the MCP checks after installing.

## Mistakes and shared learning

Admin flags and tenant corrections retain their original feedback records.
Rules are distilled with existing lessons in context, so the model can reuse
an existing lesson ID and canonical key for paraphrases. The write path checks
all lessons in the same scope, including retired ones, and normalizes casing,
spacing and terminal punctuation. Database uniqueness and scoped locks prevent
concurrent inserts of the same identity. Reprocessing the same feedback does
not learn twice. Feedback rows link to their resulting memory ID.

Tenant preferences stay personal. Customer claims about price or availability
are unverified: shared lessons teach verification against live inventory,
rather than applying one tenant's claimed price to others. General lessons are
available to every tenant; area-specific lessons apply only in that area.
Retired lessons are not reactivated merely because the same feedback recurs.
Live inventory and current requirements take precedence over remembered rules.

Before sending a generated reply, the price guard checks explicit RM amounts
against the offered rooms' prices, tenure, deposits and savings. An unsupported
draft is retained as flagged internal evidence, recorded as feedback, and
replaced with an inventory-grounded reply. Learning failures leave feedback
queued for `php cron/learning_job.php`. This guard covers monetary claims; it
does not detect every possible language-model mistake. Unreported mistakes and
semantic duplicates the model fails to identify still need admin review.

## Validation

```bash
php tests/run.php
mcp/tenant_marketing/.venv/bin/python -m unittest discover -s mcp/tenant_marketing -p 'test_*.py'
```

The PHP suite uses a throwaway database and includes persistent requirement
updates, tenant isolation, real MCP execution, lesson deduplication, retirement,
cross-tenant retrieval and monetary-claim checks. AI-provider tests use the
labelled offline stub, with no API cost. Real AI and WhatsApp delivery still
require active credentials and `MOCK_AI=false`.
