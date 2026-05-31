<?php

declare(strict_types=1);

use App\Modules\Agencies\Support\DashboardActivityFeed;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Sprint 4 Chunk 1 (1c) — pins the dashboard activity feed allowlist so
 * adding OR removing an action from the feed is a deliberate, reviewed
 * change (§5.1 / §5.15). If you change `ACTION_ALLOWLIST`, you must update
 * this test in the same diff — that is the point.
 *
 * The exclusions are as deliberate as the inclusions (signal over churn):
 * see DashboardActivityFeed's class docblock for the curation rationale.
 */
it('pins the curated feed action allowlist exactly', function (): void {
    expect(DashboardActivityFeed::ACTION_ALLOWLIST)->toEqualCanonicalizing([
        'creator.invited',
        'bulk_invite.completed',
        'agency_creator_relation.created',
        'brand.created',
        'brand.archived',
        'brand.restored',
        'invitation.created',
        'invitation.accepted',
        'agency_settings.updated',
    ]);
});

it('deliberately EXCLUDES field-churn / noise actions from the feed', function (): void {
    // Guards the curation: these must NOT silently slip into the feed.
    $mustBeExcluded = [
        'agency_creator_relation.updated', // field churn (e.g. rating tweak)
        'agency_creator_relation.deleted',
        'brand.updated',                   // field churn
        'bulk_invite.started',             // progress noise
        'bulk_invite.failed',              // error-channel noise
        'auth.login.succeeded',            // not agency-lifecycle
    ];

    foreach ($mustBeExcluded as $action) {
        expect(DashboardActivityFeed::ACTION_ALLOWLIST)->not->toContain($action);
    }
});

it('whitelists only non-PII summary counts for bulk_invite.completed', function (): void {
    expect(DashboardActivityFeed::METADATA_WHITELIST['bulk_invite.completed'])
        ->toEqualCanonicalizing(['invited', 'already_invited', 'failed']);
});

it('safeMetadata drops any key not whitelisted for the action', function (): void {
    $raw = [
        'invited' => 3,
        'already_invited' => 1,
        'failed' => 0,
        'failures' => ['leak@example.com'], // sensitive — must be dropped
        'secret' => 'nope',
    ];

    $safe = DashboardActivityFeed::safeMetadata('bulk_invite.completed', $raw);

    expect($safe)->toEqualCanonicalizing(['invited' => 3, 'already_invited' => 1, 'failed' => 0]);
});

it('safeMetadata returns an empty array for an action with no whitelist (e.g. creator.invited)', function (): void {
    $safe = DashboardActivityFeed::safeMetadata('creator.invited', ['email' => 'leak@example.com']);

    expect($safe)->toBe([]);
});
