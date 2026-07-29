<?php

declare(strict_types=1);

namespace App\Integrations\Social;

use RuntimeException;

/**
 * One implementation per platform (Facebook Page, Instagram Business, TikTok).
 * Every publisher supports a dry-run mode when its credential is not
 * configured — the exact payload that WOULD be sent is logged to
 * ai_activity_log as 'social_dry_run_publish' and a simulated id is returned,
 * mirroring WhatsAppClient's dry-run philosophy.
 */
interface SocialPublisherInterface
{
    public function isConfigured(): bool;

    /**
     * @param ?string $mediaUrl the photo or the rendered promo video, absolute
     *                          — every platform fetches media by URL.
     * @param 'image'|'video' $mediaKind which of the two $mediaUrl is; the
     *                          endpoint and the payload differ per platform.
     * @return array{external_id: string, dry_run: bool}
     * @throws RuntimeException when the platform rejects the post or a
     *                          precondition fails (e.g. IG without media).
     */
    public function publish(string $caption, ?string $mediaUrl, string $mediaKind = 'image'): array;
}
