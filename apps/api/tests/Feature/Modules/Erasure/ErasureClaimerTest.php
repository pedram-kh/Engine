<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorPayoutMethod;
use App\Modules\Erasure\Claim\ClaimMode;
use App\Modules\Erasure\Claim\ErasureClaim;
use App\Modules\Erasure\Claim\ErasureClaimer;
use App\Modules\Erasure\Enums\ErasureLedgerState;
use App\Modules\Erasure\Execution\AuditScrub;
use App\Modules\Erasure\Guards\ErasureRefused;
use App\Modules\Erasure\Ledger\DiskLedgerMirror;
use App\Modules\Erasure\Models\ErasureLedger;
use App\Modules\Erasure\Run\ErasureRun;
use App\Modules\Erasure\Subject\SubjectNotErasable;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AccountDeletionService;
use App\Modules\Notifications\Models\UserPushToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ErasureGraph;
use Tests\Support\ErasureSnapshot;
use Tests\Support\FakeAuditScrub;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104c C1-2 — the claim: the gate in front of an erasure
|--------------------------------------------------------------------------
|
| `ErasureClaimer::claim()` is the only door to a claim. Every leg of the gate has a
| negative that proves the refusal writes NOTHING (no stamp, no audit row), and a
| positive that proves the same input passes once the leg is satisfied. The legs:
| held, not requested, not due, a guard's refusal.
*/

/** A creator who asked to be deleted and whose grace ended yesterday: the ordinary Ready account. */
function claimerSubject(array $user = []): User
{
    $account = User::factory()->creator()->createOne($user + [
        'deletion_requested_at' => now()->subDays(15),
        'deletion_scheduled_for' => now()->subDay(),
    ]);
    Creator::factory()->createOne(['user_id' => $account->id]);

    return $account->refresh();
}

function claimerCreatorId(User $user): int
{
    return Creator::query()->where('user_id', $user->id)->firstOrFail()->id;
}

function claimerStamp(User $user): ?string
{
    return DB::table('users')->where('id', $user->id)->value('erasure_started_at');
}

function claimerAuditCount(): int
{
    return AuditLog::query()->whereIn('action', [AuditAction::AccountErasureStarted, AuditAction::AccountErasureClaimReleased])->count();
}

it('claims a request whose grace has ended: stamps the account, writes one audit row with the operator and the reason', function (): void {
    $user = claimerSubject();
    $operator = User::factory()->platformAdmin()->createOne();

    $claim = app(ErasureClaimer::class)->claim($user, $operator, ClaimMode::Normal, 'Ready: the grace ended');

    $row = AuditLog::query()->where('action', AuditAction::AccountErasureStarted)->sole();

    expect($claim)->toBeInstanceOf(ErasureClaim::class)
        ->and($claim->userUlid)->toBe($user->ulid)
        ->and($claim->operatorId)->toBe($operator->id)
        ->and(claimerStamp($user))->not->toBeNull()
        ->and($user->refresh()->isErasureClaimed())->toBeTrue()
        ->and($row->actor_id)->toBe($operator->id)
        ->and($row->subject_ulid)->toBe($user->ulid)
        ->and($row->reason)->toBe('Ready: the grace ended')
        ->and($row->metadata)->toBe(['mode' => 'normal']);
});

it('refuses, and writes nothing, on every leg of the gate', function (string $leg): void {
    $this->freezeTime(); // "one second to go" must still be one second ahead when the claim reads the clock
    $user = claimerSubject();

    match ($leg) {
        'held' => $user->forceFill(['erasure_held_at' => now()])->saveQuietly(),
        'never asked' => $user->forceFill(['deletion_requested_at' => null, 'deletion_scheduled_for' => null])->saveQuietly(),
        'not due, one second to go' => $user->forceFill(['deletion_scheduled_for' => now()->addSecond()])->saveQuietly(),
        'not due, a day to go' => $user->forceFill(['deletion_scheduled_for' => now()->addDay()])->saveQuietly(),
        'payment held' => CampaignAssignment::factory()->createOne(['creator_id' => claimerCreatorId($user), 'status' => AssignmentStatus::PaymentHeld]),
        'delivered work' => CampaignAssignment::factory()->createOne(['creator_id' => claimerCreatorId($user), 'status' => AssignmentStatus::LiveVerified]),
        'real vendor' => CreatorPayoutMethod::factory()->createOne(['creator_id' => claimerCreatorId($user), 'provider' => 'stripe']),
        default => throw new InvalidArgumentException($leg),
    };
    $before = ErasureSnapshot::tables();

    expect(fn () => app(ErasureClaimer::class)->claim($user->refresh(), null, ClaimMode::Normal, 'Ready'))->toThrow(ErasureRefused::class)
        ->and(claimerStamp($user))->toBeNull()
        ->and(claimerAuditCount())->toBe(0)
        ->and(ErasureSnapshot::tables())->toBe($before);
})->with(['held', 'never asked', 'not due, one second to go', 'not due, a day to go', 'payment held', 'delivered work', 'real vendor']);

/** @return array{tokens: int, sessions: int, push: int} */
function claimerCredentials(User $user): array
{
    return [
        'tokens' => DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count(),
        'sessions' => DB::table('sessions')->where('user_id', $user->id)->count(),
        'push' => DB::table('user_push_tokens')->where('user_id', $user->id)->count(),
    ];
}

/** Two tokens (each with a push row) and two sessions for the user. */
function claimerSignIn(User $user, string $tag): void
{
    for ($i = 0; $i < 2; $i++) {
        $token = $user->createToken("device-{$tag}-{$i}");
        UserPushToken::factory()->createOne(['user_id' => $user->id, 'personal_access_token_id' => $token->accessToken->id]);
        DB::table('sessions')->insert(['id' => "sess-{$tag}-{$i}", 'user_id' => $user->id, 'ip_address' => '203.0.113.9', 'user_agent' => 'phpunit', 'payload' => 'x', 'last_activity' => time()]);
    }
}

it('signs the account out everywhere when it claims it, in both modes, and touches nobody else (AH-104c C2-0)', function (string $mode): void {
    $subject = claimerSubject($mode === 'force' ? ['deletion_requested_at' => null, 'deletion_scheduled_for' => null] : []);
    $bystander = claimerSubject();
    claimerSignIn($subject, 's');
    claimerSignIn($bystander, 'b');
    $operator = User::factory()->platformAdmin()->createOne();

    app(ErasureClaimer::class)->claim($subject, $operator, $mode === 'force' ? ClaimMode::ForceNotRequested : ClaimMode::Normal, 'Ready');

    expect(claimerCredentials($subject))->toBe(['tokens' => 0, 'sessions' => 0, 'push' => 0])
        ->and(claimerCredentials($bystander))->toBe(['tokens' => 2, 'sessions' => 2, 'push' => 2]);
})->with(['normal', 'force']);

it('leaves the sessions and tokens alone when the gate refuses', function (): void {
    $user = claimerSubject(['erasure_held_at' => now()]);
    claimerSignIn($user, 'h');

    expect(fn () => app(ErasureClaimer::class)->claim($user, null, ClaimMode::Normal, 'Ready'))->toThrow(ErasureRefused::class)
        ->and(claimerCredentials($user))->toBe(['tokens' => 2, 'sessions' => 2, 'push' => 2]);
});

it('signs the account out when a reapply reclaims it, too', function (): void {
    Storage::fake('media');
    $user = claimerSubject(['deletion_requested_at' => null, 'deletion_scheduled_for' => null]);
    (new DiskLedgerMirror)->record($user->ulid, now()->subDay());
    claimerSignIn($user, 'r');

    app(ErasureClaimer::class)->reclaim($user, null);

    expect(claimerCredentials($user))->toBe(['tokens' => 0, 'sessions' => 0, 'push' => 0]);
});

it('passes at the instant the grace ends and not a second before (the 14 days are a promise)', function (): void {
    Carbon::setTestNow('2026-11-20 12:00:00');
    $user = claimerSubject(['deletion_scheduled_for' => now()]);

    $claim = app(ErasureClaimer::class)->claim($user, null, ClaimMode::Normal, 'Ready');

    expect($claim->userUlid)->toBe($user->ulid);

    $early = claimerSubject(['deletion_scheduled_for' => now()->addSecond()]);

    expect(fn () => app(ErasureClaimer::class)->claim($early, null, ClaimMode::Normal, 'Ready'))
        ->toThrow(ErasureRefused::class, 'the grace has not ended');

    Carbon::setTestNow();
});

it('names the legs that refuse, so a shell operator can act on them, and never a person', function (): void {
    $user = claimerSubject(['erasure_held_at' => now(), 'deletion_scheduled_for' => now()->addDays(3)]);

    try {
        app(ErasureClaimer::class)->claim($user, null, ClaimMode::Normal, 'Ready');
        $this->fail('The gate should have refused.');
    } catch (ErasureRefused $e) {
        expect($e->reasons)->toHaveCount(2)
            ->and($e->getMessage())->toContain('on hold')->toContain('the grace has not ended')
            ->and($e->getMessage())->not->toContain($user->email);
    }
});

it('requires a reason, and an operator to force', function (): void {
    $user = claimerSubject();

    expect(fn () => app(ErasureClaimer::class)->claim($user, null, ClaimMode::Normal, '   '))->toThrow(ErasureRefused::class, 'a reason is required')
        ->and(fn () => app(ErasureClaimer::class)->claim($user, null, ClaimMode::ForceNotRequested, 'Because'))->toThrow(ErasureRefused::class, 'needs an operator')
        ->and(claimerStamp($user))->toBeNull()
        ->and(claimerAuditCount())->toBe(0);
});

it('forces an account that never asked, with the operator and its own reason, and still refuses a hold or a guard', function (): void {
    $operator = User::factory()->platformAdmin()->createOne();
    $never = claimerSubject(['deletion_requested_at' => null, 'deletion_scheduled_for' => null]);

    $claim = app(ErasureClaimer::class)->claim($never, $operator, ClaimMode::ForceNotRequested, 'forced, not requested: legal request 4471');
    $row = AuditLog::query()->where('action', AuditAction::AccountErasureStarted)->sole();

    expect($claim->mode)->toBe(ClaimMode::ForceNotRequested)
        ->and($row->reason)->toBe('forced, not requested: legal request 4471')
        ->and($row->metadata)->toBe(['mode' => 'force_not_requested'])
        ->and($never->refresh()->isErasureClaimed())->toBeTrue();

    $held = claimerSubject(['deletion_requested_at' => null, 'deletion_scheduled_for' => null, 'erasure_held_at' => now()]);
    $paid = claimerSubject(['deletion_requested_at' => null, 'deletion_scheduled_for' => null]);
    CampaignAssignment::factory()->createOne(['creator_id' => claimerCreatorId($paid), 'status' => AssignmentStatus::PaymentReleased]);

    expect(fn () => app(ErasureClaimer::class)->claim($held, $operator, ClaimMode::ForceNotRequested, 'Because'))->toThrow(ErasureRefused::class, 'on hold')
        ->and(fn () => app(ErasureClaimer::class)->claim($paid, $operator, ClaimMode::ForceNotRequested, 'Because'))->toThrow(ErasureRefused::class, 'payment state')
        ->and(claimerStamp($held))->toBeNull()
        ->and(claimerStamp($paid))->toBeNull();
});

it('refuses a subject that is not a creator, or is already erased, and writes nothing', function (): void {
    $agency = User::factory()->agencyAdmin()->createOne();
    $erased = User::factory()->creator()->erased()->createOne();
    Creator::factory()->erased()->createOne(['user_id' => $erased->id]);

    expect(fn () => app(ErasureClaimer::class)->claim($agency, null, ClaimMode::Normal, 'Ready'))->toThrow(SubjectNotErasable::class)
        ->and(fn () => app(ErasureClaimer::class)->claim($erased, null, ClaimMode::Normal, 'Ready'))->toThrow(SubjectNotErasable::class)
        ->and(claimerAuditCount())->toBe(0)
        ->and(claimerStamp($erased))->toBeNull();
});

it('re-reads the row under the lock: a stale model cannot claim a request that was cancelled, held or moved meanwhile', function (string $change): void {
    $stale = claimerSubject();

    // Another request wins the race while this model is still in memory with the old values.
    match ($change) {
        'cancelled' => DB::table('users')->where('id', $stale->id)->update(['deletion_requested_at' => null, 'deletion_scheduled_for' => null]),
        'held' => DB::table('users')->where('id', $stale->id)->update(['erasure_held_at' => now()]),
        'rescheduled' => DB::table('users')->where('id', $stale->id)->update(['deletion_scheduled_for' => now()->addDays(14)]),
        default => throw new InvalidArgumentException($change),
    };

    expect(fn () => app(ErasureClaimer::class)->claim($stale, null, ClaimMode::Normal, 'Ready'))->toThrow(ErasureRefused::class)
        ->and(claimerStamp($stale))->toBeNull()
        ->and(claimerAuditCount())->toBe(0);
})->with(['cancelled', 'held', 'rescheduled']);

it('is idempotent: a second claim returns a claim for the same account and writes no second row', function (): void {
    $user = claimerSubject();
    $first = app(ErasureClaimer::class)->claim($user, null, ClaimMode::Normal, 'Ready');
    $stamp = claimerStamp($user);

    $this->travel(5)->minutes();
    $second = app(ErasureClaimer::class)->claim($user->refresh(), null, ClaimMode::Normal, 'Ready');

    expect($second->userUlid)->toBe($first->userUlid)
        ->and(claimerStamp($user))->toBe($stamp)
        ->and(AuditLog::query()->where('action', AuditAction::AccountErasureStarted)->count())->toBe(1);
});

it('ends the creator\'s right to cancel: a cancel that follows the claim restores nothing', function (): void {
    $user = claimerSubject();
    app(ErasureClaimer::class)->claim($user, null, ClaimMode::Normal, 'Ready');

    $restored = app(AccountDeletionService::class)->cancel($user->refresh(), '203.0.113.7', 'spec');

    expect($restored)->toBeFalse()
        ->and($user->refresh()->deletion_requested_at)->not->toBeNull()
        ->and($user->isErasureClaimed())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::AccountDeletionCancelled)->count())->toBe(0);
});

it('lets the creator win when the cancel lands first: the claim then refuses', function (): void {
    $user = claimerSubject();

    $restored = app(AccountDeletionService::class)->cancel($user, '203.0.113.7', 'spec');

    expect($restored)->toBeTrue()
        ->and(fn () => app(ErasureClaimer::class)->claim($user->refresh(), null, ClaimMode::Normal, 'Ready'))->toThrow(ErasureRefused::class, 'has not asked')
        ->and(claimerStamp($user))->toBeNull();
});

it('releases a claim nothing has run for: the creator may cancel again, and the release is audited with a code, not a sentence', function (): void {
    $user = claimerSubject();
    $claimer = app(ErasureClaimer::class);
    $claimer->claim($user, null, ClaimMode::Normal, 'Ready');

    $released = $claimer->release($user->refresh(), null, 'digest_moved');
    $row = AuditLog::query()->where('action', AuditAction::AccountErasureClaimReleased)->sole();

    expect($released)->toBeTrue()
        ->and(claimerStamp($user))->toBeNull()
        ->and($row->metadata)->toBe(['cause' => 'digest_moved'])
        ->and($row->subject_ulid)->toBe($user->ulid)
        ->and(app(AccountDeletionService::class)->cancel($user->refresh(), null, null))->toBeTrue();
});

it('does not release a claim once a run has begun for the account: the files may be gone', function (): void {
    $user = claimerSubject();
    $claimer = app(ErasureClaimer::class);
    $claimer->claim($user, null, ClaimMode::Normal, 'Ready');
    ErasureLedger::query()->create(['user_ulid' => $user->ulid, 'state' => ErasureLedgerState::Incomplete, 'digest' => str_repeat('a', 64), 'started_at' => now()]);

    expect($claimer->release($user->refresh(), null, 'refused'))->toBeFalse()
        ->and($user->refresh()->isErasureClaimed())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::AccountErasureClaimReleased)->count())->toBe(0);
});

it('has nothing to release for an account that is not claimed', function (): void {
    $user = claimerSubject();

    expect(app(ErasureClaimer::class)->release($user, null, 'refused'))->toBeFalse()
        ->and(claimerAuditCount())->toBe(0);
});

it('resumes only an account that is already claimed, and makes no gate decision', function (): void {
    $user = claimerSubject(['erasure_held_at' => now()]);
    $claimer = app(ErasureClaimer::class);

    expect(fn () => $claimer->resume($user))->toThrow(ErasureRefused::class, 'not claimed');

    $user->forceFill(['erasure_started_at' => now()])->saveQuietly();

    expect($claimer->resume($user->refresh())->userUlid)->toBe($user->ulid);
});

it('reclaims only an account the ledger mirror says was erased, for a reapply', function (): void {
    Storage::fake('media');
    $user = claimerSubject(['deletion_requested_at' => null, 'deletion_scheduled_for' => null]);
    $claimer = app(ErasureClaimer::class);

    expect(fn () => $claimer->reclaim($user, null))->toThrow(ErasureRefused::class, 'ledger mirror has no record')
        ->and(claimerStamp($user))->toBeNull();

    (new DiskLedgerMirror)->record($user->ulid, now()->subDay());
    $claim = $claimer->reclaim($user, null);

    expect($claim->mode)->toBe(ClaimMode::Reapply)
        ->and($user->refresh()->isErasureClaimed())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::AccountErasureStarted)->sole()->metadata)->toBe(['mode' => 'reapply']);

    // Still a creator with a payment in flight: the mirror does not override a guard.
    $paid = claimerSubject(['deletion_requested_at' => null, 'deletion_scheduled_for' => null]);
    (new DiskLedgerMirror)->record($paid->ulid, now()->subDay());
    CampaignAssignment::factory()->createOne(['creator_id' => claimerCreatorId($paid), 'status' => AssignmentStatus::PaymentHeld]);

    expect(fn () => $claimer->reclaim($paid, null))->toThrow(ErasureRefused::class, 'payment state');
});

it('reports the legs without locking or writing, for the shell\'s dry-run', function (): void {
    $user = claimerSubject(['erasure_held_at' => now()]);
    $before = ErasureSnapshot::tables();

    $gates = app(ErasureClaimer::class)->requestGates($user, ClaimMode::Normal);

    expect($gates)->toBe(['the account is on hold: release the hold first'])
        ->and(app(ErasureClaimer::class)->requestGates($user, ClaimMode::ForceNotRequested))->toBe(['the account is on hold: release the hold first'])
        ->and(ErasureSnapshot::tables())->toBe($before);
});

/*
| The engine takes the claim and re-reads it: the irreversible steps cannot be reached around the gate.
*/

it('refuses to run an account that is not claimed, even with a correct digest and ulid: nothing is touched', function (): void {
    Storage::fake('media');
    app()->instance(AuditScrub::class, new FakeAuditScrub);
    $graph = ErasureGraph::seedSubject('a');
    $graph->putFiles();
    $run = app(ErasureRun::class);
    $digest = $run->plan($graph->user)->digest();
    $claim = new ErasureClaim($graph->user->ulid, ClaimMode::Normal, null);
    $before = ErasureSnapshot::tables();
    $disk = Storage::disk('media')->allFiles();

    expect(fn () => $run->execute($graph->user, $claim, $digest, $graph->user->ulid))->toThrow(ErasureRefused::class, 'not claimed')
        ->and(ErasureSnapshot::tables())->toBe($before)
        ->and(Storage::disk('media')->allFiles())->toBe($disk)
        ->and(ErasureLedger::query()->count())->toBe(0);
});

it('refuses a claim that belongs to another account, and one that was released after it was issued', function (): void {
    Storage::fake('media');
    app()->instance(AuditScrub::class, new FakeAuditScrub);
    $a = ErasureGraph::seedSubject('a');
    $b = ErasureGraph::seedSubject('b');
    $run = app(ErasureRun::class);

    foreach ([$a, $b] as $graph) {
        $graph->user->forceFill(['deletion_requested_at' => now()->subDays(15), 'deletion_scheduled_for' => now()->subDay()])->saveQuietly();
    }
    $claimOfA = app(ErasureClaimer::class)->claim($a->user->refresh(), null, ClaimMode::Normal, 'Ready');
    $digestB = $run->plan($b->user)->digest();

    expect(fn () => $run->execute($b->user, $claimOfA, $digestB, $b->user->ulid))->toThrow(ErasureRefused::class, 'different account');

    app(ErasureClaimer::class)->release($a->user->refresh(), null, 'refused');

    expect(fn () => $run->execute($a->user->refresh(), $claimOfA, $run->plan($a->user)->digest(), $a->user->ulid))->toThrow(ErasureRefused::class, 'not claimed');
});

it('names each leg of the gate as a code, and the command\'s sentence is made from the same codes', function (string $leg, array $codes): void {
    $this->freezeTime();
    $user = claimerSubject();
    match ($leg) {
        'ready' => null,
        'held' => $user->forceFill(['erasure_held_at' => now()])->saveQuietly(),
        'never asked' => $user->forceFill(['deletion_requested_at' => null, 'deletion_scheduled_for' => null])->saveQuietly(),
        'grace running' => $user->forceFill(['deletion_scheduled_for' => now()->addDay()])->saveQuietly(),
        'held and grace running' => $user->forceFill(['erasure_held_at' => now(), 'deletion_scheduled_for' => now()->addDay()])->saveQuietly(),
        default => throw new InvalidArgumentException($leg),
    };
    $claimer = app(ErasureClaimer::class);

    expect($claimer->gateCodes($user->refresh(), ClaimMode::Normal))->toBe($codes)
        ->and($claimer->requestGates($user, ClaimMode::Normal))->toHaveCount(count($codes))
        // Forcing skips the request legs, never the hold.
        ->and($claimer->gateCodes($user, ClaimMode::ForceNotRequested))->toBe(in_array('held', $codes, true) ? ['held'] : []);
})->with([
    'ready' => ['ready', []],
    'held' => ['held', ['held']],
    'never asked' => ['never asked', ['not_requested']],
    'grace running' => ['grace running', ['grace_running']],
    'held and grace running' => ['held and grace running', ['held', 'grace_running']],
]);

it('marks only the call that stamped the claim as having created it', function (): void {
    $user = claimerSubject();
    $operator = User::factory()->platformAdmin()->createOne();
    $claimer = app(ErasureClaimer::class);

    $first = $claimer->claim($user, $operator, ClaimMode::Normal, 'Ready: the grace ended');
    $second = $claimer->claim($user->refresh(), $operator, ClaimMode::Normal, 'Ready: the grace ended');

    expect($first->created)->toBeTrue()
        ->and($second->created)->toBeFalse();
});
