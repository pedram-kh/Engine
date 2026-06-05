<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Enums;

use App\Modules\Campaigns\Enums\PostedContentVerificationStatus;
use App\Modules\Creators\Integrations\Contracts\SocialPlatformProvider;

/**
 * The outcome of {@see SocialPlatformProvider::verifyPostUrl()}
 * (Sprint 9 Chunk 2, D-9).
 *
 * String values are kept identical to the campaign-side
 * {@see PostedContentVerificationStatus} so the
 * `VerifyPostedContentJob` maps one to the other trivially — WITHOUT the
 * Creators integration layer depending on the Campaigns module (the dependency
 * direction stays Campaigns → Creators only; no cycle).
 */
enum PostVerificationOutcome: string
{
    case Verified = 'verified';
    case NotFound = 'not_found';
    case Mismatch = 'mismatch';
}
