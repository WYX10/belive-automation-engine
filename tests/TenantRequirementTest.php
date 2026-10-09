<?php

declare(strict_types=1);

use App\AI\Skills\DecideSkill;
use App\AI\Skills\UnderstandSkill;
use App\Models\Lead;
use App\Models\TenantRequirement;

$tenantA = Lead::findOrCreate('60115551001', 'Requirement A', 'whatsapp');
$tenantB = Lead::findOrCreate('60115551002', 'Requirement B', 'whatsapp');
Lead::update((int) $tenantB['id'], ['budget' => 'not supplied']);
$id = (int) $tenantA['id'];
$saved = TenantRequirement::capture($id, ['entities' => ['location' => 'Cheras', 'budget' => 700, 'tenure' => 'monthly'],
    'tenant_profile' => 'student', 'requirements' => ['occupants' => 2, 'amenities' => ['wifi'], 'preferences' => ['quiet room']]]);
check('tenant requirements persist independently of transcript', $saved['location'] === 'Cheras' && (float) $saved['budget'] === 700.0);
$saved = TenantRequirement::capture($id, ['entities' => ['location' => null, 'budget' => null], 'requirements' => ['amenities' => ['aircon']]]);
check('later missing fields preserve known requirements', $saved['location'] === 'Cheras' && (float) $saved['budget'] === 700.0);
check('amenities accumulate across messages without duplication', $saved['amenities'] === ['wifi', 'aircon']);
$saved = TenantRequirement::capture($id, ['entities' => ['budget' => 850], 'requirements' => ['amenities' => ['wifi'], 'remove_preferences' => ['quiet room']]]);
check('explicit changed budget replaces the old value', (float) $saved['budget'] === 850.0 && (float) Lead::find($id)['budget'] === 850.0);
check('explicit preference removal is remembered', $saved['preferences'] === [] && $saved['amenities'] === ['wifi', 'aircon']);
check('requirements do not bleed into another tenant', TenantRequirement::forLead((int) $tenantB['id'])['budget'] === null);
check('legacy nonnumeric budgets do not break requirement creation', TenantRequirement::forLead((int) $tenantB['id'])['budget'] === null);
$saved = TenantRequirement::capture($id, ['clear_requirements' => ['budget'], 'requirements' => ['occupants' => -1]]);
check('explicitly withdrawing budget clears memory and legacy lead', $saved['budget'] === null && Lead::find($id)['budget'] === null);
check('invalid occupant count does not overwrite known value', (int) $saved['occupants'] === 2);
$understood = UnderstandSkill::run($id, 'My budget is now RM650. I need wifi for 2 people.', []);
check('understanding exposes requirement deltas', $understood['entities']['budget'] === 650 && $understood['requirements']['occupants'] === 2);
check('personal requirement update is not treated as an AI mistake', $understood['intent'] !== 'correction');
$understood['tenant_requirements'] = TenantRequirement::capture($id, $understood);
$decision = DecideSkill::run(Lead::find($id), $understood, ['block' => '', 'ids' => []]);
check('explicit short stay overrides student demographic guess', $decision['recommended_tenure'] === 'monthly');
check('saved requirements are supplied to marketing and decision prompts', str_contains($decision['marketing_guidance'], '"tenure": "monthly"') || str_contains($decision['marketing_guidance'], '"tenure":"monthly"'));
