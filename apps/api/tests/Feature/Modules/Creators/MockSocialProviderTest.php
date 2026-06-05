<?php

declare(strict_types=1);

use App\Modules\Creators\Integrations\Enums\PostVerificationOutcome;
use App\Modules\Creators\Integrations\Mock\MockSocialProvider;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Sprint 9 Chunk 2 (D-9) — the mock social provider's only load-bearing
 * method. Deterministic, handle-based (no OAuth token): the URL containing the
 * connected handle → Verified; a recognizable social URL without it →
 * Mismatch; an unrecognizable URL → NotFound. Plus the cache-keyed forced
 * override lever (the e-sign-mock controllability analogue).
 */
function mockSocial(): MockSocialProvider
{
    return new MockSocialProvider;
}

it('returns Verified + a platform post id when the URL contains the connected handle', function (): void {
    $result = mockSocial()->verifyPostUrl('@creator', 'https://www.instagram.com/creator/p/CxYz123');

    expect($result->outcome)->toBe(PostVerificationOutcome::Verified)
        ->and($result->platformPostId)->not->toBeNull();
});

it('matches the handle case-insensitively and ignores a leading @', function (): void {
    $result = mockSocial()->verifyPostUrl('Creator', 'https://tiktok.com/@creator/video/999');

    expect($result->outcome)->toBe(PostVerificationOutcome::Verified);
});

it('returns Mismatch for a recognizable social URL that does not contain the handle', function (): void {
    $result = mockSocial()->verifyPostUrl('@creator', 'https://www.instagram.com/someoneelse/p/abc');

    expect($result->outcome)->toBe(PostVerificationOutcome::Mismatch)
        ->and($result->platformPostId)->toBeNull();
});

it('returns NotFound for a URL that is not a recognizable social post', function (): void {
    $result = mockSocial()->verifyPostUrl('@creator', 'https://example.com/not-a-post');

    expect($result->outcome)->toBe(PostVerificationOutcome::NotFound);
});

it('honours a forced outcome override regardless of the URL shape', function (): void {
    $url = 'https://www.instagram.com/creator/p/forced';
    MockSocialProvider::forceOutcome($url, PostVerificationOutcome::Mismatch);

    // The handle is present in the URL (would normally be Verified) — the
    // forced override wins.
    $result = mockSocial()->verifyPostUrl('@creator', $url);

    expect($result->outcome)->toBe(PostVerificationOutcome::Mismatch);
});
