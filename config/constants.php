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

define('CONTENT_POST_STATUSES', ['draft', 'approved', 'rejected', 'posted']);
define('CONTENT_PLATFORMS', ['facebook', 'instagram', 'tiktok']);
// content_posts.media_kind — a photo post or a rendered vertical promo reel.
define('CONTENT_MEDIA_KINDS', ['image', 'video']);
// Promo video shape: 9:16 at 1080p, the format all three platforms treat as
// native short-form. A scene is one shot with its burned-in line.
define('CONTENT_VIDEO_WIDTH', 1080);
define('CONTENT_VIDEO_HEIGHT', 1920);
define('CONTENT_VIDEO_FPS', 30);
define('CONTENT_VIDEO_SCENE_MAX', 5);
define('CONTENT_VIDEO_SCENE_SECONDS', ['min' => 2.0, 'max' => 6.0]);
// Admin-edited caption ceiling — comfortably under Instagram's 2,200-character
// limit, the tightest of the three platforms we publish to.
define('CONTENT_CAPTION_MAX', 2000);

// content_posts.publish_status — outcome of the auto-publish attempt.
// 'simulated' = dry-run (no active credential), clearly badged, never passed
// off as a real platform post.
define('CONTENT_PUBLISH_STATUSES', ['published', 'simulated', 'failed']);

define('REFERRAL_REWARD_STATUSES', ['pending', 'credited']);
define('REFERRAL_REWARD_POINTS', 50); // fixed points per successful referral
define('RENT_REWARD_POINTS', 200); // four confirmed referrals
define('RENT_REWARD_CREDIT_RM', 50);
define('RENT_REWARD_STATUSES', ['requested', 'approved', 'applied', 'rejected']);

// electric_bills.status. 'overdue' is absent on purpose — it is unpaid with a
// due date in the past, derived at read time (ElectricBill::isOverdue).
define('ELECTRIC_BILL_STATUSES', ['unpaid', 'paid', 'waived']);

// renewal_offers.status — the promotional rent an owner offers a tenant whose
// term is running out. 'expired' is absent for the same reason as 'overdue'
// above: it is 'offered' past its expires_on, derived at read time
// (RenewalOffer::isOpen).
define('RENEWAL_OFFER_STATUSES', ['offered', 'accepted', 'declined', 'withdrawn']);
define('RENEWAL_OFFER_DECISIONS', ['accepted', 'declined']); // what a tenant may answer

// Where a meter reading pair came from. Never a live-feed simulation.
define('ELECTRIC_READING_SOURCES', ['smart_meter', 'manual']);

// API credential service identifiers.
// 'instagram' is the Instagram-Login token (graph.instagram.com); 'meta_graph'
// is the Facebook Page token that also covers the older Instagram Graph API
// path. Which one is present decides how Instagram calls are made — see
// App\Integrations\Meta\InstagramApi.
define('CREDENTIAL_SERVICES', ['whatsapp', 'anthropic', 'gemini', 'meta_graph', 'instagram', 'openai', 'openrouter', 'tiktok']);

// Services that supply LLMs (subset of CREDENTIAL_SERVICES; drives the model registry).
define('LLM_PROVIDERS', ['anthropic', 'gemini', 'openai', 'openrouter']);
