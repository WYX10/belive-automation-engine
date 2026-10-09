<?php

declare(strict_types=1);

use App\AI\Memory\FeedbackCollector;
use App\AI\Memory\LearningEngine;
use App\AI\Memory\MemoryRetriever;
use App\AI\Memory\MemoryStore;
use App\Core\Database;
use App\Models\Interaction;
use App\Models\Lead;
use App\Models\LearnedMemory;

$older = MemoryStore::saveRule('Dedup Area', 'strategy', 'Answer the tenant question before asking another question.');
MemoryStore::saveRule('Dedup Area', 'strategy', 'Disclose any location mismatch.');
$again = MemoryStore::saveRule('dedup area', 'strategy', "  ANSWER the tenant question  before asking another question! ");
check('duplicate lookup checks all rules, not only the newest one', $again === $older);
check('case spacing and final punctuation do not duplicate a lesson', LearnedMemory::count(['context_tag' => 'Dedup Area', 'rule_type' => 'strategy']) === 2);
$semantic = MemoryStore::saveRule('general', 'strategy', 'Answer the question before suggesting a viewing.', null, 0.6, 'answer_before_cta');
$paraphrase = MemoryStore::saveRule('general', 'strategy', 'Respond to the question first, then offer a viewing.', null, 0.6, 'answer_before_cta');
check('canonical lesson keys reuse paraphrases', $semantic === $paraphrase);
$byMeaning = MemoryStore::saveRule('general', 'strategy', 'Respond first; a viewing offer comes later.', null, 0.6, null, $semantic);
check('verified same-scope model match reuses an existing lesson', $byMeaning === $semantic);
$distinctScope = MemoryStore::saveRule('Another Area', 'strategy', 'Respond first; a viewing offer comes later.', null, 0.6, null, $semantic);
check('semantic matching cannot merge unrelated contexts', $distinctScope !== $semantic);
LearnedMemory::update($semantic, ['active' => 0]);
check('retired duplicate is reused without creating another row', MemoryStore::saveRule('general', 'strategy', 'Respond to the question first, then offer a viewing.', null, 0.6, 'answer_before_cta') === $semantic);
check('repeated feedback does not resurrect retired lessons', (int) LearnedMemory::find($semantic)['active'] === 0);
$fact = MemoryStore::saveRule('Fact Area', 'fact', 'The specific room costs RM650 monthly.', null, 0.6, 'specific_price');
$different = MemoryStore::saveRule('Fact Area', 'fact', 'The specific room costs RM750 monthly.', null, 0.6, 'specific_price', $fact);
check('different numeric facts never collapse under the same semantic key', $different !== $fact);

$source = Lead::findOrCreate('60115552001', 'Mistake Source', 'whatsapp');
$bad = Interaction::create(['lead_id' => $source['id'], 'phase' => 'conversion', 'skill' => 'automate', 'model_used' => 'mock-offline-stub',
    'direction' => 'outbound', 'message_out' => 'The room is RM9999 monthly.']);
$feedback = FeedbackCollector::adminFlag($bad, 'wrong_price', 'Check the room price, it is wrong.');
check('repeated identical flags reuse one feedback event', FeedbackCollector::adminFlag($bad, 'wrong_price', 'Check the room price, it is wrong!') === $feedback);
$system = FeedbackCollector::assistantMistake($bad, (int) $source['id'], 'Unsupported price; verify live room and tenure.');
$learned = LearningEngine::processFeedback($system);
check('observed AI mistake is linked to its learned memory', $learned !== null && (int) Database::run('SELECT memory_id FROM ai_feedback WHERE id = ?', [$system])->fetchColumn() === $learned);
check('same feedback cannot learn or reinforce twice', LearningEngine::processFeedback($system) === null);
$other = Lead::findOrCreate('60115552002', 'Other Tenant', 'whatsapp');
Lead::update((int) $other['id'], ['location' => 'Johor']);
$retrieved = array_column(MemoryRetriever::forContext('Johor', 100), 'id');
check('general mistake-prevention lesson is available to other tenants', in_array($learned, array_map('intval', $retrieved), true));
check('unverified price is not propagated as a shared fact', !str_contains(LearnedMemory::find($learned)['learned_rule'], '9999'));
$second = FeedbackCollector::assistantMistake($bad, (int) $source['id'], 'Another unsupported quoted price.');
check('another instance of the same mistake reuses its lesson', LearningEngine::processFeedback($second) === $learned);
