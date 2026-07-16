<?php

/**
 * Registry of available AI models. This is what populates the dropdown in
 * admin/models — model choices are never hardcoded into pages. Adding a model
 * here makes it assignable to any phase from the admin panel.
 *
 * provider maps to the credential service that supplies its API key and to
 * the client class that speaks its wire protocol.
 */

return [
    'claude-sonnet-5' => [
        'provider'  => 'anthropic',
        'label'     => 'Claude Sonnet 5',
        'purpose'   => 'Cost-efficient default for reasoning, replies and content',
        'cost_tier' => 'standard',
    ],
    'claude-opus-4-8' => [
        'provider'  => 'anthropic',
        'label'     => 'Claude Opus 4.8',
        'purpose'   => 'Premium option for harder reasoning tasks',
        'cost_tier' => 'premium',
    ],
    'gemini-3.5-flash' => [
        'provider'  => 'gemini',
        'label'     => 'Gemini 3.5 Flash',
        'purpose'   => 'Fast, low-cost live-chat NLP',
        'cost_tier' => 'economy',
    ],
];
