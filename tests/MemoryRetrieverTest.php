<?php

declare(strict_types=1);

/**
 * Proves retrieval honours context_tag, active flag, and the confidence
 * threshold — i.e. that deactivated or collapsed rules genuinely stop
 * steering Eve. Run via: php tests/run.php
 */

use App\AI\Memory\MemoryRetriever;
use App\Models\LearnedMemory;

// Seed a spread of rules across contexts, states and confidence levels.
$setapakActive = LearnedMemory::create([
    'context_tag' => 'Setapak', 'rule_type' => 'sequencing',
    'learned_rule' => 'For Setapak enquiries, send room photos before quoting any price.',
    'confidence_score' => 0.80, 'active' => 1,
]);
$generalActive = LearnedMemory::create([
    'context_tag' => 'general', 'rule_type' => 'tone',
    'learned_rule' => 'Always reply in the customer’s language.',
    'confidence_score' => 0.70, 'active' => 1,
]);
$cherasActive = LearnedMemory::create([
    'context_tag' => 'Cheras', 'rule_type' => 'fact',
    'learned_rule' => 'Cheras master rooms start at RM 850.',
    'confidence_score' => 0.90, 'active' => 1,
]);
$setapakInactive = LearnedMemory::create([
    'context_tag' => 'Setapak', 'rule_type' => 'fact',
    'learned_rule' => 'RETIRED RULE — must never be retrieved.',
    'confidence_score' => 0.85, 'active' => 0,
]);
$setapakLowConf = LearnedMemory::create([
    'context_tag' => 'Setapak', 'rule_type' => 'strategy',
    'learned_rule' => 'LOW CONFIDENCE RULE — below threshold, must never be retrieved.',
    'confidence_score' => 0.10, 'active' => 1,
]);

$retrieved = MemoryRetriever::forContext('Setapak');
$ids = array_map(fn ($r) => (int) $r['id'], $retrieved);

check('Setapak context retrieves the active Setapak rule', in_array($setapakActive, $ids, true));
check('Setapak context also retrieves global (general) rules', in_array($generalActive, $ids, true));
check('other-context rule (Cheras) is NOT retrieved for Setapak', !in_array($cherasActive, $ids, true));
check('inactive rule is excluded despite high confidence', !in_array($setapakInactive, $ids, true));
check('below-threshold rule is excluded despite active=1', !in_array($setapakLowConf, $ids, true));

// Deactivation via confidence collapse: contradict until under threshold.
LearnedMemory::contradict($setapakActive); // 0.80 → 0.55
LearnedMemory::contradict($setapakActive); // 0.55 → 0.30
LearnedMemory::contradict($setapakActive); // 0.30 → 0.05 → auto-deactivates

$afterCollapse = array_map(fn ($r) => (int) $r['id'], MemoryRetriever::forContext('Setapak'));
$row = LearnedMemory::find($setapakActive);

check('repeated contradiction collapses confidence', (float) $row['confidence_score'] < MEMORY_CONFIDENCE_THRESHOLD);
check('collapsed rule auto-deactivates (soft, kept for audit)', (int) $row['active'] === 0);
check('collapsed rule no longer retrieved', !in_array($setapakActive, $afterCollapse, true));

// Case-insensitive tag normalization.
$normalized = array_map(fn ($r) => (int) $r['id'], MemoryRetriever::forContext('setapak'));
check('context tag matching is case-insensitive via normalization', $normalized === $afterCollapse);
