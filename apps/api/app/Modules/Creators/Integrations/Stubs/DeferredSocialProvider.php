<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Stubs;

use App\Modules\Creators\Integrations\Contracts\SocialPlatformProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\PostVerification;
use App\Modules\Creators\Integrations\Exceptions\ProviderNotBoundException;
use App\Modules\Creators\Integrations\Mock\MockSocialProvider;

/**
 * Default binding for {@see SocialPlatformProvider} when the
 * `social_verification_enabled` flag is ON but the configured driver is not
 * recognised — fails loud at the first call rather than routing silently to a
 * wrong adapter (#40 "No silent vendor calls"). The flag-ON + driver=mock path
 * resolves to {@see MockSocialProvider}; the flag-OFF path resolves to
 * {@see SkippedSocialProvider}.
 */
final class DeferredSocialProvider implements SocialPlatformProvider
{
    public function verifyPostUrl(string $handle, string $postUrl): PostVerification
    {
        throw ProviderNotBoundException::for('SocialPlatformProvider', 'verifyPostUrl');
    }
}
