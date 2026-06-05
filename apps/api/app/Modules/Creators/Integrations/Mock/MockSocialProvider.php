<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Mock;

use App\Modules\Creators\Integrations\Contracts\SocialPlatformProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\PostVerification;
use App\Modules\Creators\Integrations\Enums\PostVerificationOutcome;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Mock social-platform provider (Sprint 9 Chunk 2, D-9) — the analogue of
 * {@see MockEsignProvider}. The only load-bearing method is
 * {@see self::verifyPostUrl()}; it returns deterministic canned outcomes keyed
 * off the submitted URL + the creator's connected handle (no OAuth token — the
 * Phase-1 onboarding stub stores handle only).
 *
 * Default (deterministic) behaviour:
 *   - the URL contains the connected handle              → Verified
 *   - the URL is a recognizable social post URL but does
 *     NOT contain the handle                             → Mismatch
 *   - the URL is not a recognizable social post URL      → NotFound
 *
 * An explicit override can be forced (the cache-keyed canned-outcome lever,
 * mirroring the e-sign mock's controllable state) via
 * {@see self::forceOutcome()} — used by the mock-vendor / demo paths and tests
 * that want to exercise a specific branch regardless of the URL shape.
 *
 * Sprint 9+ swaps in real `MetaSocialProvider` / `TikTokSocialProvider` /
 * `YouTubeSocialProvider` adapters behind the same contract.
 */
final class MockSocialProvider implements SocialPlatformProvider
{
    public const OUTCOME_TTL_SECONDS = 24 * 60 * 60;

    /**
     * Hosts/markers that make a URL "look like" a real social post — used to
     * tell a Mismatch (a real post, wrong creator) from a NotFound (not a post
     * URL at all).
     */
    private const array RECOGNIZABLE_MARKERS = [
        'instagram.com',
        'tiktok.com',
        'youtube.com',
        'youtu.be',
        'facebook.com',
        'twitter.com',
        'x.com',
    ];

    public static function forcedOutcomeKey(string $postUrl): string
    {
        return 'mock:social:outcome:'.sha1($postUrl);
    }

    /**
     * Force a canned outcome for a specific post URL (demo / test lever).
     */
    public static function forceOutcome(string $postUrl, PostVerificationOutcome $outcome): void
    {
        Cache::put(self::forcedOutcomeKey($postUrl), $outcome->value, self::OUTCOME_TTL_SECONDS);
    }

    public function verifyPostUrl(string $handle, string $postUrl): PostVerification
    {
        $forced = Cache::get(self::forcedOutcomeKey($postUrl));
        if (is_string($forced) && ($outcome = PostVerificationOutcome::tryFrom($forced)) !== null) {
            return new PostVerification(
                outcome: $outcome,
                platformPostId: $outcome === PostVerificationOutcome::Verified ? $this->derivePostId($postUrl) : null,
            );
        }

        $normalizedHandle = ltrim(strtolower(trim($handle)), '@');
        $haystack = strtolower($postUrl);

        if ($normalizedHandle !== '' && str_contains($haystack, $normalizedHandle)) {
            return new PostVerification(
                outcome: PostVerificationOutcome::Verified,
                platformPostId: $this->derivePostId($postUrl),
            );
        }

        foreach (self::RECOGNIZABLE_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                // A real-looking post URL, but not this creator's handle.
                return new PostVerification(outcome: PostVerificationOutcome::Mismatch);
            }
        }

        return new PostVerification(outcome: PostVerificationOutcome::NotFound);
    }

    /**
     * Derive a stable mock platform post id from the URL — the trailing
     * path segment when present, else a short deterministic hash.
     */
    private function derivePostId(string $postUrl): string
    {
        $path = (string) parse_url($postUrl, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
        $last = end($segments);

        if (is_string($last) && $last !== '') {
            return 'mock_post_'.Str::slug($last, '_');
        }

        return 'mock_post_'.substr(sha1($postUrl), 0, 16);
    }
}
