<?php

declare(strict_types=1);

namespace App\Pipeline\LeadGeneration;

use App\AI\Memory\MemoryRetriever;
use App\AI\Skills\DecideSkill;
use App\AI\Skills\UnderstandSkill;
use App\Models\Lead;

/**
 * Scores/classifies a captured lead using the same Understand + Decide skills
 * the live conversation uses (phase: lead_gen, so the admin's model choice
 * for lead generation applies). Writes the assessment onto the lead row for
 * the dashboard's closing-probability / signals / recommendation panel.
 */
final class LeadScorer
{
    public static function score(int $leadId, string $sourceText): array
    {
        $lead = Lead::find($leadId);
        if ($lead === null) {
            return [];
        }

        $understanding = UnderstandSkill::run($leadId, $sourceText, [], 'lead_gen');
        Lead::mergeEnquiryDetails($leadId, $understanding['entities']);
        if ($understanding['tenant_profile'] !== null) {
            Lead::update($leadId, ['tenant_profile' => $understanding['tenant_profile']]);
        }

        $contextTag = $understanding['entities']['location'] ?? $lead['location'] ?? null;
        $memory = MemoryRetriever::promptBlock(MemoryRetriever::forContext($contextTag));

        $decision = DecideSkill::run(Lead::find($leadId) + ['is_returning' => false], $understanding, $memory, 'lead_gen');

        if ($decision['qualified'] && $lead['status'] === 'new') {
            Lead::transition($leadId, 'qualified');
        }
        Lead::setAiAssessment($leadId, $decision['closing_probability'], $decision['lead_signals'], $decision['recommendation']);

        return $decision;
    }
}
