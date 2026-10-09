<?php

declare(strict_types=1);

use App\AI\TenantMarketing;
use App\Models\Lead;

$marketingTenant = Lead::findOrCreate('60115553001', 'MCP Tenant', 'whatsapp');
$block = TenantMarketing::promptBlock(['lead_id' => $marketingTenant['id'], 'location' => 'Cheras', 'budget' => 650, 'amenities' => ['wifi']], [], 'price_enquiry', (int) $marketingTenant['id']);
check('official SDK MCP skill executes over stdio', str_contains($block, 'MCP SKILL PLAN'));
check('MCP asks only for missing requirements', str_contains($block, '"ask_next":"move_in_date"'));
check('MCP reports empty inventory honestly', str_contains($block, '"has_inventory":false'));
check('MCP handles price questions with verified prices first', str_contains($block, 'Give the verified tenure price first'));
check('MCP inputs do not expose tenant identity', !str_contains($block, '60115553001') && !str_contains($block, 'MCP Tenant'));
$_ENV['MARKETING_MCP_ENABLED'] = 'false';
$fallback = TenantMarketing::promptBlock([], [], 'room_enquiry', (int) $marketingTenant['id']);
check('disabled MCP preserves useful built-in conversation guidance', str_contains($fallback, 'Respect the stated budget') && !str_contains($fallback, 'MCP SKILL PLAN'));
unset($_ENV['MARKETING_MCP_ENABLED']);
