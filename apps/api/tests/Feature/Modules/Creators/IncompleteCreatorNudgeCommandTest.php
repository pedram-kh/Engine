<?php

declare(strict_types=1);

use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\IncompleteCreatorNudgeVariant;
use App\Modules\Creators\Features\IncompleteCreatorNudgeEnabled;
use App\Modules\Creators\Mail\IncompleteCreatorNudgeMail;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The creators:send-incomplete-nudges command + service (D6). Pins:
 *
 *   - §5.34 case 1: flag OFF → the command is an explicit no-op (nothing
 *     queued, nothing stamped) — the break-revert anchor.
 *   - flag ON → one localized email per eligible creator, each variant queued
 *     with the recipient's preferred_language (the queued-locale assertion).
 *   - once-only idempotency: a second run sends zero.
 *   - --dry-run mutates nothing and works while the flag is OFF (the preview an
 *     operator reads before enabling).
 */

/**
 * @param  array<string, mixed>  $userState
 * @param  array<string, mixed>  $creatorState
 */
function eligibleNudgeCreator(array $userState, array $creatorState = []): Creator
{
    $user = User::factory()->creator()->state($userState)->create();

    return Creator::factory()->for($user)->create(array_merge([
        'application_status' => ApplicationStatus::Incomplete,
        'created_at' => now()->subDays(3),
    ], $creatorState));
}

it('(1) flag OFF → the command is an explicit no-op: nothing queued, nothing stamped', function (): void {
    Mail::fake();
    $verify = eligibleNudgeCreator(['email_verified_at' => null]);
    $finish = eligibleNudgeCreator(['email_verified_at' => now()]);

    expect(Feature::active(IncompleteCreatorNudgeEnabled::NAME))->toBeFalse();

    $this->artisan('creators:send-incomplete-nudges')
        ->expectsOutputToContain('is OFF')
        ->assertSuccessful();

    Mail::assertNothingQueued();
    expect($verify->refresh()->incomplete_nudge_sent_at)->toBeNull()
        ->and($finish->refresh()->incomplete_nudge_sent_at)->toBeNull();
});

it('flag ON → queues one email per variant with the recipient preferred_language, and stamps', function (): void {
    Mail::fake();
    Feature::activate(IncompleteCreatorNudgeEnabled::NAME);

    $verify = eligibleNudgeCreator(['email_verified_at' => null, 'preferred_language' => 'pt']);
    $finish = eligibleNudgeCreator(['email_verified_at' => now(), 'preferred_language' => 'it']);

    $this->artisan('creators:send-incomplete-nudges')
        ->expectsOutputToContain('Sent 2 nudge(s): verify=1, finish=1 (cap 50).')
        ->assertSuccessful();

    Mail::assertQueued(
        IncompleteCreatorNudgeMail::class,
        fn (IncompleteCreatorNudgeMail $m): bool => $m->variant === IncompleteCreatorNudgeVariant::Verify && $m->locale === 'pt',
    );
    Mail::assertQueued(
        IncompleteCreatorNudgeMail::class,
        fn (IncompleteCreatorNudgeMail $m): bool => $m->variant === IncompleteCreatorNudgeVariant::Finish && $m->locale === 'it',
    );
    Mail::assertQueuedCount(2);

    expect($verify->refresh()->incomplete_nudge_sent_at)->not->toBeNull()
        ->and($finish->refresh()->incomplete_nudge_sent_at)->not->toBeNull();
});

it('is idempotent: a second run sends zero (the once-only stamp)', function (): void {
    Feature::activate(IncompleteCreatorNudgeEnabled::NAME);
    eligibleNudgeCreator(['email_verified_at' => null]);
    eligibleNudgeCreator(['email_verified_at' => now()]);

    Mail::fake();
    $this->artisan('creators:send-incomplete-nudges')->assertSuccessful();
    Mail::assertQueuedCount(2);

    // Second run: everyone is stamped, so nothing is eligible.
    Mail::fake();
    $this->artisan('creators:send-incomplete-nudges')
        ->expectsOutputToContain('Sent 0 nudge(s): verify=0, finish=0 (cap 50).')
        ->assertSuccessful();
    Mail::assertNothingQueued();
});

it('--dry-run mutates nothing and works while the flag is OFF (the pre-enable preview)', function (): void {
    Mail::fake();
    expect(Feature::active(IncompleteCreatorNudgeEnabled::NAME))->toBeFalse();

    $verify = eligibleNudgeCreator(['email_verified_at' => null]);
    $finish = eligibleNudgeCreator(['email_verified_at' => now()]);

    $this->artisan('creators:send-incomplete-nudges', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run] would send 2 nudge(s): verify=1, finish=1 (cap 50). No changes made.')
        ->assertSuccessful();

    Mail::assertNothingQueued();
    expect($verify->refresh()->incomplete_nudge_sent_at)->toBeNull()
        ->and($finish->refresh()->incomplete_nudge_sent_at)->toBeNull();
});

it('--limit caps the run oldest-first and stamps ONLY the capped set (no over-stamping, §5.34)', function (): void {
    Mail::fake();
    Feature::activate(IncompleteCreatorNudgeEnabled::NAME);

    // Oldest-first across BOTH variants — the cap is a per-run TOTAL, not per-variant.
    $oldest = eligibleNudgeCreator(['email_verified_at' => null], ['created_at' => now()->subDays(5)]);
    $middle = eligibleNudgeCreator(['email_verified_at' => now()], ['created_at' => now()->subDays(4)]);
    $newest = eligibleNudgeCreator(['email_verified_at' => null], ['created_at' => now()->subDays(3)]);

    $this->artisan('creators:send-incomplete-nudges', ['--limit' => '2'])
        ->expectsOutputToContain('Sent 2 nudge(s): verify=1, finish=1 (cap 2).')
        ->assertSuccessful();

    Mail::assertQueuedCount(2);

    // The two OLDEST are stamped; the newest is over the cap → untouched, so
    // tomorrow's run picks it up. No over-stamping of the uncapped tail.
    expect($oldest->refresh()->incomplete_nudge_sent_at)->not->toBeNull()
        ->and($middle->refresh()->incomplete_nudge_sent_at)->not->toBeNull()
        ->and($newest->refresh()->incomplete_nudge_sent_at)->toBeNull();
});

it('--dry-run --limit previews the capped set and mutates nothing', function (): void {
    Mail::fake();
    Feature::activate(IncompleteCreatorNudgeEnabled::NAME);

    $a = eligibleNudgeCreator(['email_verified_at' => now()], ['created_at' => now()->subDays(5)]);
    $b = eligibleNudgeCreator(['email_verified_at' => now()], ['created_at' => now()->subDays(4)]);
    $c = eligibleNudgeCreator(['email_verified_at' => now()], ['created_at' => now()->subDays(3)]);

    $this->artisan('creators:send-incomplete-nudges', ['--dry-run' => true, '--limit' => '2'])
        ->expectsOutputToContain('[dry-run] would send 2 nudge(s): verify=0, finish=2 (cap 2). No changes made.')
        ->assertSuccessful();

    Mail::assertNothingQueued();
    expect($a->refresh()->incomplete_nudge_sent_at)->toBeNull()
        ->and($b->refresh()->incomplete_nudge_sent_at)->toBeNull()
        ->and($c->refresh()->incomplete_nudge_sent_at)->toBeNull();
});

it('rejects a non-positive / non-numeric --limit (fails loudly, sends nothing)', function (): void {
    Mail::fake();
    Feature::activate(IncompleteCreatorNudgeEnabled::NAME);
    eligibleNudgeCreator(['email_verified_at' => now()]);

    $this->artisan('creators:send-incomplete-nudges', ['--limit' => '0'])
        ->expectsOutputToContain('--limit must be a positive integer.')
        ->assertFailed();

    $this->artisan('creators:send-incomplete-nudges', ['--limit' => 'abc'])
        ->assertFailed();

    Mail::assertNothingQueued();
});
