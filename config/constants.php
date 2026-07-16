<?php

/**
 * Fixed enums used across the app. Single source of truth — every module that
 * needs a phase name, error type, or status value reads it from here, never
 * from a local string literal.
 */

// Pipeline phases (drive per-phase model assignment in ai_model_config).
define('AI_PHASES', ['lead_gen', 'conversion', 'content_creation']);

// The four judged AI skills — exact proposal naming, do not rename.
define('AI_SKILLS', ['understand', 'decide', 'create', 'automate']);

// ai_feedback.error_type values. The first four are factual corrections; the
// last two are conversation-strategy lessons (the Setapak drop-off scenario
// is 'poor_sequencing', detected from patterns rather than an explicit flag).
define('FEEDBACK_ERROR_TYPES', [
    'wrong_price',
    'wrong_availability',
    'wrong_tone',
    'missed_intent',
    'poor_sequencing',
    'low_engagement',
]);

// Where a piece of feedback came from.
define('FEEDBACK_SOURCES', [
    'admin_flag',          // explicit "flag as incorrect" in chat history
    'customer_correction', // customer directly corrects Eve mid-chat
    'implicit_repeat',     // customer had to ask the same thing twice
    'implicit_dropoff',    // conversation died right after a message type
    'pattern_detection',   // aggregate cron analysis across many leads
]);

// Lead lifecycle.
define('LEAD_STATUSES', ['new', 'qualified', 'converted']);

// All six proposal lead channels flow into one leads table via this field.
define('LEAD_SOURCE_CHANNELS', [
    'whatsapp',        // direct inbound WhatsApp message
    'social',          // FB/IG comment or DM capture
    'website',         // smart enquiry form
    'listing_portal',  // PropertyGuru / iProperty (manual-intake fallback)
    'referral',        // Refer & Earn link
    'tiktok',          // TikTok manual-intake fallback
]);

// ai_learned_memory.rule_type — the schema supports both factual corrections
// and sequencing/strategy lessons, per the proposal's own Setapak example.
define('MEMORY_RULE_TYPES', ['fact', 'sequencing', 'strategy', 'tone']);

// Confidence handling for learned rules.
define('MEMORY_CONFIDENCE_DEFAULT', 0.60);
define('MEMORY_CONFIDENCE_THRESHOLD', 0.30); // below this a rule soft-deactivates
define('MEMORY_REINFORCE_STEP', 0.10);
define('MEMORY_DECAY_STEP', 0.25);

define('BOOKING_STATUSES', ['pending', 'confirmed', 'cancelled', 'completed']);

define('CONTENT_POST_STATUSES', ['draft', 'approved', 'posted']);
define('CONTENT_PLATFORMS', ['facebook', 'instagram', 'tiktok']);

define('REFERRAL_REWARD_STATUSES', ['pending', 'credited']);
define('REFERRAL_REWARD_POINTS', 50); // fixed points per successful referral

// API credential service identifiers.
define('CREDENTIAL_SERVICES', ['whatsapp', 'anthropic', 'gemini', 'meta_graph']);
