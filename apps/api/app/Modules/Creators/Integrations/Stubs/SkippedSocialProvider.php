<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Stubs;

use App\Modules\Creators\CreatorsServiceProvider;
use App\Modules\Creators\Features\SocialVerificationEnabled;
use App\Modules\Creators\Integrations\Contracts\SocialPlatformProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\PostVerification;
use App\Modules\Creators\Integrations\Exceptions\FeatureDisabledException;

/**
 * Flag-OFF binding for {@see SocialPlatformProvider} (Sprint 9 Chunk 2, D-9/D-11).
 *
 * Swapped in by {@see CreatorsServiceProvider} when
 * `social_verification_enabled` is OFF. The verification job is only dispatched
 * on the flag-ON path, so this stub should never be reached in normal flow; if
 * any code path bypasses the flag check, it surfaces a clear error per #40 /
 * "No silent vendor calls".
 */
final class SkippedSocialProvider implements SocialPlatformProvider
{
    public function verifyPostUrl(string $handle, string $postUrl): PostVerification
    {
        throw FeatureDisabledException::for(
            'SocialPlatformProvider',
            SocialVerificationEnabled::NAME,
            'verifyPostUrl',
        );
    }
}
