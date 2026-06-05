<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\DataTransferObjects;

use App\Modules\Creators\Integrations\Contracts\SocialPlatformProvider;
use App\Modules\Creators\Integrations\Enums\PostVerificationOutcome;

/**
 * Result of {@see SocialPlatformProvider::verifyPostUrl()} (Sprint 9 Chunk 2,
 * D-9).
 *
 *   - outcome:        whether the post URL belongs to the connected creator
 *                     ({@see PostVerificationOutcome::Verified}), is a post but
 *                     not the creator's (`Mismatch`), or could not be found at
 *                     all (`NotFound`).
 *   - platformPostId: the resolved provider-side post id (only meaningful on a
 *                     `Verified` outcome — null otherwise).
 *
 * Consumed by `VerifyPostedContentJob`, which writes the outcome onto
 * `campaign_posted_content.verification_status` + stamps `platform_post_id` /
 * `verified_at`, and on `Verified` drives `posted → live_verified`.
 */
final readonly class PostVerification
{
    public function __construct(
        public PostVerificationOutcome $outcome,
        public ?string $platformPostId = null,
    ) {}
}
