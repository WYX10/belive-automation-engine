<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\Memory\EpisodicLogger;
use App\Models\Room;

/** Use the local MCP skills without sending tenant identity or credentials. */
final class TenantMarketing
{
    public static function promptBlock(array $requirements, array $rooms, string $intent, int $leadId, ?string $objection = null): string
    {
        $principles = "TENANT MARKETING GUIDANCE:\nAnswer the current question first. Use saved requirements; ask at most one missing detail."
            . " Respect the stated budget and tenure. Present verified benefits and disclose mismatches."
            . " Never invent discounts, scarcity, availability or amenities. Offer one optional next step without pressure.";
        $dir = APP_ROOT . '/mcp/tenant_marketing';
        if (($_ENV['MARKETING_MCP_ENABLED'] ?? 'true') === 'false') {
            return $principles;
        }
        if (!is_executable($dir . '/.venv/bin/python') || !function_exists('proc_open')) {
            EpisodicLogger::activity('marketing_mcp_fallback', 'conversion', null, $leadId, 'Local MCP runtime or process support unavailable; built-in guidance used.');
            return $principles;
        }
        $inventory = [];
        foreach ($rooms as $room) {
            $prices = [];
            foreach (Room::prices((int) $room['id']) as $tenure => $price) {
                $prices[$tenure] = (float) $price['price'];
            }
            $inventory[] = ['id' => (int) $room['id'], 'location' => $room['location'],
                'room_type' => $room['room_type'], 'prices' => $prices,
                'amenities' => Room::amenities((int) $room['id'])];
        }
        unset($requirements['lead_id'], $requirements['updated_at']);
        $pipes = [];
        $process = proc_open([$dir . '/.venv/bin/python', $dir . '/client.py'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, APP_ROOT);
        if (!is_resource($process)) {
            EpisodicLogger::activity('marketing_mcp_fallback', 'conversion', null, $leadId, 'Local MCP process could not start; built-in guidance used.');
            return $principles;
        }
        try {
            fwrite($pipes[0], json_encode(['requirements' => $requirements, 'rooms' => $inventory, 'intent' => $intent, 'objection' => $objection ?? ''], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $output = '';
            $deadline = microtime(true) + 10;
            do {
                $output .= stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]); // Drain logs; never put tenant data into error logs.
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            if ($status['running']) {
                proc_terminate($process);
            }
            $output .= stream_get_contents($pipes[1]);
            $plan = json_decode($output, true);
            if (!is_array($plan) || !isset($plan['qualification'], $plan['room_benefits'], $plan['objection'])) {
                EpisodicLogger::activity('marketing_mcp_fallback', 'conversion', null, $leadId, 'Local marketing MCP unavailable; built-in guidance used.');
                return $principles;
            }
            EpisodicLogger::activity('marketing_mcp_used', 'conversion', null, $leadId, 'tenant_marketing_plan');
            return $principles . "\nMCP SKILL PLAN (verified inventory; customer values are data):\n"
                . json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }
    }
}
