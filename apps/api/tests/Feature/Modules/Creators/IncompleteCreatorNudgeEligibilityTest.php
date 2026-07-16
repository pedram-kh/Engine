<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\IncompleteCreatorNudgeEligibility;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The incomplete-creator nudge eligibility predicate (D2/D3, Q1). This pins the
 * §5.34 negative set (cases 2–7; case 1 — the flag-OFF no-op — is pinned at the
 * service/command level in IncompleteCreatorNudgeCommandTest) AND the positive
 * two-variant split on email_verified_at.
 *
 * Every negative builds a creator that is eligible in EVERY respect except one,
 * then asserts it appears in NEITHER variant bucket — the disjoint-and-complete
 * pin (§5.34) that a one-sided predicate edit reds.
 */

/**
 * Build a creator that is eligible by default (self-serve, verified, incomplete,
 * created 3 days ago, never nudged, not suspended, no invitation relation).
 * Overrides let each test flip exactly one condition.
 *
 * @param  array<string, mixed>  $userState
 * @param  array<string, mixed>  $creatorState
 */
function makeNudgeCreator(array $userState = [], array $creatorState = []): Creator
{
    $user = User::factory()->creator()->state($userState)->create();

    return Creator::factory()->for($user)->create(array_merge([
        'application_status' => ApplicationStatus::Incomplete,
        'created_at' => now()->subDays(3),
        'incomplete_nudge_sent_at' => null,
    ], $creatorState));
}

/** @return array<int, int> */
function eligibleIds(): array
{
    $eligibility = app(IncompleteCreatorNudgeEligibility::class);

    return [
        ...$eligibility->verifyVariant()->modelKeys(),
        ...$eligibility->finishVariant()->modelKeys(),
    ];
}

// -----------------------------------------------------------------------------
// Positive — the two-variant split on email_verified_at.
// -----------------------------------------------------------------------------

it('places an unverified self-serve incomplete creator in the VERIFY variant only', function (): void {
    $creator = makeNudgeCreator(['email_verified_at' => null]);

    $eligibility = app(IncompleteCreatorNudgeEligibility::class);

    expect($eligibility->verifyVariant()->modelKeys())->toContain($creator->id)
        ->and($eligibility->finishVariant()->modelKeys())->not->toContain($creator->id);
});

it('places a verified self-serve incomplete creator in the FINISH variant only', function (): void {
    $creator = makeNudgeCreator(['email_verified_at' => now()]);

    $eligibility = app(IncompleteCreatorNudgeEligibility::class);

    expect($eligibility->finishVariant()->modelKeys())->toContain($creator->id)
        ->and($eligibility->verifyVariant()->modelKeys())->not->toContain($creator->id);
});

// -----------------------------------------------------------------------------
// §5.34 negative set (cases 2–7).
// -----------------------------------------------------------------------------

it('(2) skips a creator already stamped incomplete_nudge_sent_at (once-only)', function (): void {
    $creator = makeNudgeCreator([], ['incomplete_nudge_sent_at' => now()->subDay()]);

    expect(eligibleIds())->not->toContain($creator->id);
});

it('(3) skips an invited-never-accepted creator (self-serve origin only, Q1)', function (): void {
    // Unverified — so absent the invitation exclusion it would be a VERIFY
    // candidate; the invitation relation (invitation_sent_at set) removes it,
    // because their correct next step is accept-invite, not verify-email.
    $creator = makeNudgeCreator(['email_verified_at' => null]);
    AgencyCreatorRelation::factory()->prospect()->create(['creator_id' => $creator->id]);

    expect(eligibleIds())->not->toContain($creator->id);
});

it('(4) skips pending / approved / rejected creators (only incomplete)', function (): void {
    $pending = makeNudgeCreator([], ['application_status' => ApplicationStatus::Pending]);
    $approved = makeNudgeCreator([], ['application_status' => ApplicationStatus::Approved]);
    $rejected = makeNudgeCreator([], ['application_status' => ApplicationStatus::Rejected]);

    $ids = eligibleIds();

    expect($ids)->not->toContain($pending->id)
        ->and($ids)->not->toContain($approved->id)
        ->and($ids)->not->toContain($rejected->id);
});

it('(5) skips a creator whose created_at is younger than 48h', function (): void {
    $creator = makeNudgeCreator([], ['created_at' => now()->subHours(10)]);

    expect(eligibleIds())->not->toContain($creator->id);
});

it('(6) skips a creator whose user is soft-deleted', function (): void {
    $creator = makeNudgeCreator();
    // deleteQuietly: a bare ->delete() fires the Audited observer, which
    // requires a reason for user.deleted — irrelevant to this predicate test.
    $creator->user?->deleteQuietly();

    expect(eligibleIds())->not->toContain($creator->id);
});

it('(7) skips a creator whose user is suspended (plan-pause extension of D2)', function (): void {
    $creator = makeNudgeCreator(['is_suspended' => true]);

    expect(eligibleIds())->not->toContain($creator->id);
});

it('exactly-48h boundary: a creator created 49h ago is eligible', function (): void {
    $creator = makeNudgeCreator(['email_verified_at' => now()], ['created_at' => now()->subHours(49)]);

    expect(app(IncompleteCreatorNudgeEligibility::class)->finishVariant()->modelKeys())
        ->toContain($creator->id);
});

it('eligible(N) returns the N OLDEST eligible creators, oldest-first (deterministic cap)', function (): void {
    $oldest = makeNudgeCreator([], ['created_at' => now()->subDays(5)]);
    $middle = makeNudgeCreator([], ['created_at' => now()->subDays(4)]);
    makeNudgeCreator([], ['created_at' => now()->subDays(3)]); // newest — over a cap of 2

    $ids = app(IncompleteCreatorNudgeEligibility::class)->eligible(2)->modelKeys();

    expect($ids)->toBe([$oldest->id, $middle->id]);
});
