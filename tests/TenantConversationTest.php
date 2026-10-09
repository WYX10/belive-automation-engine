<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\Interaction;
use App\Models\Lead;
use App\Models\TenantRequirement;
use App\Pipeline\Conversion\ConversationManager;

$persistentTenant = Lead::findOrCreate('60115555001', 'Persistent Tenant', 'whatsapp');
$persistentId = (int) $persistentTenant['id'];
TenantRequirement::capture($persistentId, ['entities' => ['location' => 'Cheras', 'budget' => 800, 'tenure' => 'monthly'], 'requirements' => ['amenities' => ['wifi']]]);
for ($n = 0; $n < 14; $n++) {
    Interaction::create(['lead_id' => $persistentId, 'phase' => 'conversion', 'skill' => 'understand', 'model_used' => 'test-fixture', 'direction' => 'inbound', 'message_in' => 'An unrelated old message.']);
}
$capturingWa = new class extends App\Integrations\WhatsApp\WhatsAppClient {
    public array $texts = [];
    public function sendText(string $toWaPhone, string $text): array
    {
        $this->texts[] = $text;
        return ['message_id' => 'requirement-test', 'dry_run' => true];
    }
};
(new ConversationManager($capturingWa))->handleInbound(['wa_phone' => '60115555001', 'name' => 'Persistent Tenant', 'text' => 'What is the price?', 'message_id' => 'requirement-turn', 'timestamp' => time()]);
$afterTurn = TenantRequirement::forLead($persistentId);
check('conversation remembers requirements outside the recent transcript', $afterTurn['location'] === 'Cheras' && (float) $afterTurn['budget'] === 800.0 && $afterTurn['tenure'] === 'monthly');
check('conversation executes the marketing MCP for the correct tenant', (int) Database::run("SELECT COUNT(*) FROM ai_activity_log WHERE lead_id = ? AND action = 'marketing_mcp_used'", [$persistentId])->fetchColumn() === 1);
check('conversation sends one grounded reply', count($capturingWa->texts) === 1 && trim($capturingWa->texts[0]) !== '');
check('conversation does not ask again for the known area or budget', !preg_match('/which area|what.*budget/i', $capturingWa->texts[0]));
