<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Erasure\Claim\ErasureClaimer;
use App\Modules\Erasure\Enums\ErasureLedgerState;
use App\Modules\Erasure\Erase\EraseNotReady;
use App\Modules\Erasure\Erase\EraseStarter;
use App\Modules\Erasure\Execution\AuditScrub;
use App\Modules\Erasure\Features\AccountErasureExecuteEnabled;
use App\Modules\Erasure\Jobs\RunErasureJob;
use App\Modules\Erasure\Models\ErasureLedger;
use App\Modules\Erasure\Run\ErasureRun;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Events\LoginFailed;
use App\Modules\Identity\Events\TwoFactorRecoveryCodeConsumed;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\FailedLoginTracker;
use App\Modules\Identity\Services\TwoFactorService;
use App\Modules\Identity\Services\TwoFactorVerificationThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

use PragmaRX\Google2FA\Google2FA;
use Tests\Support\ErasureGraph;
use Tests\Support\ErasureSnapshot;
use Tests\Support\FakeAuditScrub;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// The grace boundary and the stale-run window are comparisons against `now()`.
beforeEach(function (): void {
    $this->freezeTime();
    Feature::flushCache();
    Event::fake([LoginFailed::class]);
});

/*
|--------------------------------------------------------------------------
| AH-104c C3-3 / C3-4 — the plan, the status and the Erase endpoints
|--------------------------------------------------------------------------
|
|   GET  /api/v1/admin/compliance/erasure-queue/{user}/plan
|   GET  /api/v1/admin/compliance/erasure-queue/{user}
|   POST /api/v1/admin/compliance/erasure-queue/{user}/erase
|
| The headline negative: every refusal leaves NOTHING claimed and NOTHING queued. The job
| (`RunErasureJob`) has its own spec file; the end-to-end run is at the bottom of this one.
*/

const ERASE_URL = '/api/v1/admin/compliance/erasure-queue';

/** Tables that move on every request for reasons unrelated to an erasure (cache-backed limiters, sessions). */
const ERASE_NOISE = ['cache', 'cache_locks', 'sessions'];

/**
 * A due, erasable creator with a full graph, and an admin who has 2FA.
 *
 * @return array{graph: ErasureGraph, admin: User, secret: string, codes: list<string>, ulid: string}
 */
function eraseFixture(string $tag = 'a'): array
{
    Storage::fake('media');
    app()->instance(AuditScrub::class, new FakeAuditScrub);

    $graph = ErasureGraph::seedSubject($tag);
    $graph->putFiles();
    $graph->user->forceFill(['deletion_requested_at' => now()->subDays(15), 'deletion_scheduled_for' => now()->subDay()])->saveQuietly();

    $svc = app(TwoFactorService::class);
    $secret = $svc->generateSecret();
    $codes = $svc->generateRecoveryCodes();
    $admin = User::factory()->platformAdmin()->createOne([
        'two_factor_secret' => $secret,
        'two_factor_recovery_codes' => array_map(fn (string $c): string => $svc->hashRecoveryCode($c), $codes),
        'two_factor_confirmed_at' => now(),
    ]);

    return ['graph' => $graph, 'admin' => $admin, 'secret' => $secret, 'codes' => $codes, 'ulid' => $graph->user->ulid];
}

function eraseTotp(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

function eraseDigest(User $admin, string $ulid): string
{
    $digest = actingAs($admin, 'web_admin')->getJson(ERASE_URL.'/'.$ulid.'/plan')->assertOk()->json('data.attributes.digest');
    expect($digest)->toBeString();

    return (string) $digest;
}

/**
 * The body of a valid click; `$over` replaces fields.
 *
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function eraseBody(array $fx, array $over = []): array
{
    return $over + [
        'ulid' => $fx['ulid'],
        'reason' => 'The grace ended and the creator confirmed',
        'digest' => eraseDigest($fx['admin'], $fx['ulid']),
        'mfa_code' => eraseTotp($fx['secret']),
    ];
}

function eraseClaimStamp(string $ulid): mixed
{
    return DB::table('users')->where('ulid', $ulid)->value('erasure_started_at');
}

function eraseStartedAudit(): int
{
    return AuditLog::query()->where('action', AuditAction::AccountErasureStarted)->count();
}

/**
 * Click with a valid body and return the response.
 *
 * @return TestResponse<JsonResponse>
 */
function eraseClick(array $fx, array $over = []): TestResponse
{
    $body = eraseBody($fx, $over);

    return actingAs($fx['admin'], 'web_admin')->postJson(ERASE_URL.'/'.$fx['ulid'].'/erase', $body);
}

// ── Access ──────────────────────────────────────────────────────────────────

it('401s without a session and 403s a non-admin on all three routes, claiming nothing', function (): void {
    $fx = eraseFixture();
    Feature::activate(AccountErasureExecuteEnabled::NAME);
    Queue::fake();
    $agency = User::factory()->createOne(['type' => UserType::AgencyUser, 'two_factor_confirmed_at' => now()]);
    $body = ['ulid' => $fx['ulid'], 'reason' => 'The grace ended and the creator confirmed', 'digest' => str_repeat('a', 64), 'mfa_code' => '123456'];

    expect($this->getJson(ERASE_URL.'/'.$fx['ulid'].'/plan')->status())->toBe(401)
        ->and($this->getJson(ERASE_URL.'/'.$fx['ulid'])->status())->toBe(401)
        ->and($this->postJson(ERASE_URL.'/'.$fx['ulid'].'/erase', $body)->status())->toBe(401)
        ->and($this->actingAs($agency, 'web_admin')->getJson(ERASE_URL.'/'.$fx['ulid'].'/plan')->status())->toBe(403)
        ->and($this->actingAs($agency, 'web_admin')->getJson(ERASE_URL.'/'.$fx['ulid'])->status())->toBe(403)
        ->and($this->actingAs($agency, 'web_admin')->postJson(ERASE_URL.'/'.$fx['ulid'].'/erase', $body)->status())->toBe(403)
        ->and(eraseClaimStamp($fx['ulid']))->toBeNull();
    Queue::assertNothingPushed();
});

it('404s an unknown account on all three routes', function (): void {
    $fx = eraseFixture();
    Feature::activate(AccountErasureExecuteEnabled::NAME);
    $body = ['ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'reason' => 'The grace ended and the creator confirmed', 'digest' => str_repeat('a', 64)];
    $admin = $this->actingAs($fx['admin'], 'web_admin');

    expect($admin->getJson(ERASE_URL.'/01ARZ3NDEKTSV4RRFFQ69G5FAV/plan')->status())->toBe(404)
        ->and($admin->getJson(ERASE_URL.'/01ARZ3NDEKTSV4RRFFQ69G5FAV')->status())->toBe(404)
        ->and($admin->postJson(ERASE_URL.'/01ARZ3NDEKTSV4RRFFQ69G5FAV/erase', $body)->status())->toBe(404);
});

// ── The plan ────────────────────────────────────────────────────────────────

it('returns the same plan as the command\'s dry-run: the digest, every line, the objects', function (): void {
    $fx = eraseFixture();
    ErasureGraph::seedSubject('b');

    $data = $this->actingAs($fx['admin'], 'web_admin')->getJson(ERASE_URL.'/'.$fx['ulid'].'/plan')->assertOk()->json('data.attributes');
    $plan = app(ErasureRun::class)->plan($fx['graph']->user);
    Artisan::call('erasure:run', ['user_ulid' => $fx['ulid']]);
    preg_match('/Digest: ([a-f0-9]{64})/', Artisan::output(), $m);

    expect($data['digest'])->toBe($plan->digest())
        ->and($data['digest'])->toBe($m[1] ?? '')
        ->and($data['database'])->toBe($plan->report->lines)
        ->and($data['files'])->toBe($plan->files->summary())
        ->and($data['assignments_to_cancel'])->toBe(1)
        ->and($data['ready'])->toBeTrue()
        ->and($data['gate'])->toBe([])
        ->and($data['blockers'])->toBe([])
        ->and($data['vendor_calls'])->toBe(0)
        ->and($data['claimed'])->toBeFalse()
        ->and($data['run'])->toBeNull();
});

it('writes nothing at all: every table is byte-identical around a plan, and the media disk too', function (): void {
    $fx = eraseFixture();
    $disk = Storage::disk('media')->allFiles();
    $before = ErasureSnapshot::tables(ERASE_NOISE);

    $this->actingAs($fx['admin'], 'web_admin')->getJson(ERASE_URL.'/'.$fx['ulid'].'/plan')->assertOk();

    expect(ErasureSnapshot::tables(ERASE_NOISE))->toBe($before)
        ->and(Storage::disk('media')->allFiles())->toBe($disk);
});

it('names nobody: no email, name, phone or object key in the plan', function (): void {
    $fx = eraseFixture();

    $printed = (string) $this->actingAs($fx['admin'], 'web_admin')->getJson(ERASE_URL.'/'.$fx['ulid'].'/plan')->getContent();

    expect($printed)->not->toContain($fx['graph']->email)
        ->and($printed)->not->toContain('Subject a')
        ->and($printed)->not->toContain('Display a')
        ->and($printed)->not->toContain('creators/');
});

it('says why an account is not ready, as codes: the gate\'s legs and the guards\' blockers, never a sentence', function (string $leg, array $gate, array $blockers): void {
    $fx = eraseFixture();
    $user = $fx['graph']->user;
    match ($leg) {
        'grace running' => $user->forceFill(['deletion_scheduled_for' => now()->addDay()])->saveQuietly(),
        'never asked' => $user->forceFill(['deletion_requested_at' => null, 'deletion_scheduled_for' => null])->saveQuietly(),
        'held' => $user->forceFill(['erasure_held_at' => now(), 'erasure_held_by_user_id' => $fx['admin']->id])->saveQuietly(),
        'payment' => DB::table('campaign_assignments')->where('creator_id', $fx['graph']->creator->id)->update(['status' => 'payment_held']),
        default => throw new InvalidArgumentException($leg),
    };

    $response = $this->actingAs($fx['admin'], 'web_admin')->getJson(ERASE_URL.'/'.$fx['ulid'].'/plan')->assertOk();

    expect($response->json('data.attributes.ready'))->toBeFalse()
        ->and($response->json('data.attributes.gate'))->toBe($gate)
        ->and($response->json('data.attributes.blockers'))->toBe($blockers)
        ->and((string) $response->getContent())->not->toContain('payment state')
        ->and((string) $response->getContent())->not->toContain('refus');
})->with([
    'grace running' => ['grace running', ['grace_running'], []],
    'never asked' => ['never asked', ['not_requested'], []],
    'held' => ['held', ['held'], []],
    'payment' => ['payment', [], ['payment']],
]);

it('plans a claimed account again, with the gate no longer asked and its run shown', function (): void {
    $fx = eraseFixture();
    $fx['graph']->user->forceFill(['erasure_started_at' => now(), 'erasure_held_at' => now()])->saveQuietly();

    $data = $this->actingAs($fx['admin'], 'web_admin')->getJson(ERASE_URL.'/'.$fx['ulid'].'/plan')->assertOk()->json('data.attributes');

    expect($data['claimed'])->toBeTrue()
        ->and($data['gate'])->toBe([])
        ->and($data['run'])->toBe(['state' => 'waiting', 'stuck' => false, 'can_retry' => false]);
});

it('422s the plan of an erased account', function (): void {
    $fx = eraseFixture();
    $fx['graph']->user->forceFill(['erased_at' => now()])->saveQuietly();

    $this->actingAs($fx['admin'], 'web_admin')->getJson(ERASE_URL.'/'.$fx['ulid'].'/plan')
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'admin.erasure.plan.not_available');
});

it('limits the plan to six a minute per admin, with a code the page can word', function (): void {
    $fx = eraseFixture();
    $other = User::factory()->platformAdmin()->createOne(['two_factor_confirmed_at' => now()]);

    for ($i = 0; $i < 6; $i++) {
        $this->actingAs($fx['admin'], 'web_admin')->getJson(ERASE_URL.'/'.$fx['ulid'].'/plan')->assertOk();
    }

    $this->actingAs($fx['admin'], 'web_admin')->getJson(ERASE_URL.'/'.$fx['ulid'].'/plan')
        ->assertStatus(429)
        ->assertJsonPath('errors.0.code', 'admin.erasure.plan.rate_limited')
        ->assertHeader('Retry-After');
    // Per admin, not per account or per address.
    $this->actingAs($other, 'web_admin')->getJson(ERASE_URL.'/'.$fx['ulid'].'/plan')->assertOk();
});

// ── The status ──────────────────────────────────────────────────────────────

it('shows one row for the poll, with the run\'s state, and 404s an account that is not in the queue', function (): void {
    $fx = eraseFixture();
    $user = $fx['graph']->user;

    $this->actingAs($fx['admin'], 'web_admin')->getJson(ERASE_URL.'/'.$fx['ulid'])
        ->assertOk()
        ->assertJsonPath('data.attributes.state', 'ready')
        ->assertJsonPath('data.attributes.run', null);

    $user->forceFill(['erasure_started_at' => now()])->saveQuietly();
    $this->actingAs($fx['admin'], 'web_admin')->getJson(ERASE_URL.'/'.$fx['ulid'])
        ->assertJsonPath('data.attributes.state', 'in_progress')
        ->assertJsonPath('data.attributes.run.state', 'waiting');

    $user->forceFill(['erasure_started_at' => null, 'deletion_requested_at' => null, 'deletion_scheduled_for' => null])->saveQuietly();
    $this->actingAs($fx['admin'], 'web_admin')->getJson(ERASE_URL.'/'.$fx['ulid'])->assertStatus(404);
});

/** Binds an audit-log step that cannot run here (the plan calls this a blocker; the job would refuse). */
function eraseWithoutAuditScrub(): void
{
    $scrub = new FakeAuditScrub;
    $scrub->available = false;
    app()->instance(AuditScrub::class, $scrub);
}

// ── Every refusal claims nothing and queues nothing ─────────────────────────

it('refuses, claims nothing and queues nothing, on every gate', function (string $case): void {
    $fx = eraseFixture();
    Feature::activate(AccountErasureExecuteEnabled::NAME);
    $user = $fx['graph']->user;
    $over = [];
    $status = 422;
    $code = '';

    switch ($case) {
        case 'flag off':
            Feature::deactivate(AccountErasureExecuteEnabled::NAME);
            [$status, $code] = [409, 'admin.erasure.execute.disabled'];
            break;
        case 'no code':
            $over = ['mfa_code' => null];
            [$status, $code] = [403, 'admin.erasure.execute.mfa_required'];
            break;
        case 'wrong code':
            $over = ['mfa_code' => '000000'];
            [$status, $code] = [403, 'admin.erasure.execute.mfa_invalid'];
            break;
        case 'wrong ulid':
            $over = ['ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'];
            [$status, $code] = [422, 'admin.erasure.execute.ulid_mismatch'];
            break;
        case 'no reason':
            $over = ['reason' => ''];
            [$status, $code] = [422, 'validation.failed'];
            break;
        case 'short reason':
            $over = ['reason' => 'because'];
            [$status, $code] = [422, 'validation.failed'];
            break;
        case 'malformed digest':
            $over = ['digest' => 'not-a-digest'];
            [$status, $code] = [422, 'validation.failed'];
            break;
        case 'not due':
            $user->forceFill(['deletion_scheduled_for' => now()->addDay()])->saveQuietly();
            [$status, $code] = [422, 'admin.erasure.execute.not_ready'];
            break;
        case 'never asked':
            $user->forceFill(['deletion_requested_at' => null, 'deletion_scheduled_for' => null])->saveQuietly();
            [$status, $code] = [422, 'admin.erasure.execute.not_ready'];
            break;
        case 'held':
            $user->forceFill(['erasure_held_at' => now(), 'erasure_held_by_user_id' => $fx['admin']->id])->saveQuietly();
            [$status, $code] = [422, 'admin.erasure.execute.not_ready'];
            break;
        case 'blocked':
            DB::table('campaign_assignments')->where('creator_id', $fx['graph']->creator->id)->update(['status' => 'payment_held']);
            [$status, $code] = [422, 'admin.erasure.execute.not_ready'];
            break;
        case 'already erased':
            $user->forceFill(['erased_at' => now()])->saveQuietly();
            [$status, $code] = [422, 'admin.erasure.execute.not_ready'];
            break;
        case 'audit-log step unavailable':
            eraseWithoutAuditScrub();
            [$status, $code] = [422, 'admin.erasure.execute.not_ready'];
            break;
        case 'admin without 2FA':
            $fx['admin']->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->saveQuietly();
            $over = ['mfa_code' => '123456'];
            // The MFA middleware answers first for an admin with no second factor; the controller's own
            // NotEnrolled branch is defence in depth and is pinned at the service (SecondFactorStepUpTest).
            [$status, $code] = [403, 'auth.mfa.enrollment_required'];
            break;
        default:
            throw new InvalidArgumentException($case);
    }

    $body = $over + [
        'ulid' => $fx['ulid'],
        'reason' => 'The grace ended and the creator confirmed',
        'digest' => str_repeat('a', 64),
        'mfa_code' => eraseTotp($fx['secret']),
    ];
    Queue::fake();
    $before = ErasureSnapshot::tables(ERASE_NOISE);

    $response = $this->actingAs($fx['admin']->refresh(), 'web_admin')->postJson(ERASE_URL.'/'.$fx['ulid'].'/erase', $body);

    expect($response->status())->toBe($status)
        ->and($response->json('errors.0.code'))->toBe($code)
        ->and(eraseClaimStamp($fx['ulid']))->toBeNull()
        ->and(eraseStartedAudit())->toBe(0)
        ->and(ErasureLedger::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    // Nothing moved but, at most, the second-factor throttle's cache entry (excluded as noise).
    expect(ErasureSnapshot::tables(ERASE_NOISE))->toBe($before);
})->with([
    'flag off', 'no code', 'wrong code', 'wrong ulid', 'no reason', 'short reason', 'malformed digest',
    'not due', 'never asked', 'held', 'blocked', 'already erased', 'audit-log step unavailable', 'admin without 2FA',
]);

it('says which codes made the account not ready, in the refusal', function (): void {
    $fx = eraseFixture();
    Feature::activate(AccountErasureExecuteEnabled::NAME);
    $fx['graph']->user->forceFill(['erasure_held_at' => now(), 'erasure_held_by_user_id' => $fx['admin']->id])->saveQuietly();
    DB::table('campaign_assignments')->where('creator_id', $fx['graph']->creator->id)->update(['status' => 'payment_held']);

    $response = eraseClick($fx)->assertStatus(422);

    expect($response->json('errors.0.meta.codes'))->toBe(['held', 'payment'])
        ->and((string) $response->getContent())->not->toContain('payment state');
});

it('names the audit-log step as the reason, when that is what stops the click', function (): void {
    $fx = eraseFixture();
    Feature::activate(AccountErasureExecuteEnabled::NAME);
    Queue::fake();
    eraseWithoutAuditScrub();

    $response = eraseClick($fx)->assertStatus(422);

    expect($response->json('errors.0.code'))->toBe('admin.erasure.execute.not_ready')
        ->and($response->json('errors.0.meta.codes'))->toBe(['audit_scrub_unavailable'])
        ->and(eraseClaimStamp($fx['ulid']))->toBeNull()
        ->and(eraseStartedAudit())->toBe(0);
    Queue::assertNothingPushed();
});

it('does not claim an account for a retry either, when the audit-log step is not available', function (): void {
    $fx = eraseFixture();
    Feature::activate(AccountErasureExecuteEnabled::NAME);
    Queue::fake();
    // Bound before the first request (the router keeps the controller, and so its collaborators, between
    // requests of one test); the step goes away between the two clicks.
    $scrub = new FakeAuditScrub;
    app()->instance(AuditScrub::class, $scrub);
    eraseClick($fx)->assertStatus(202);
    ErasureLedger::query()->create(['user_ulid' => $fx['ulid'], 'state' => ErasureLedgerState::Incomplete, 'digest' => 'x', 'started_at' => now()]);
    $scrub->available = false;

    $response = $this->actingAs($fx['admin']->refresh(), 'web_admin')->postJson(ERASE_URL.'/'.$fx['ulid'].'/erase', [
        'ulid' => $fx['ulid'], 'reason' => 'Plan reviewed again, confirming', 'digest' => str_repeat('a', 64), 'mfa_code' => $fx['codes'][0],
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.0.meta.codes'))->toBe(['audit_scrub_unavailable'])
        ->and(eraseStartedAudit())->toBe(1)
        ->and($fx['admin']->refresh()->two_factor_recovery_codes)->toHaveCount(count($fx['codes']));
    Queue::assertPushed(RunErasureJob::class, 1);
});

it('refuses at the claim itself when the audit-log step went away after the preflight', function (): void {
    $fx = eraseFixture();
    Queue::fake();
    eraseWithoutAuditScrub();

    expect(fn () => app(EraseStarter::class)->start($fx['graph']->user->refresh(), $fx['admin'], 'Subject asked, ticket on file', str_repeat('a', 64), false))
        ->toThrow(EraseNotReady::class);

    expect(eraseClaimStamp($fx['ulid']))->toBeNull();
    Queue::assertNothingPushed();
});

it('does not spend a recovery code, nor feed the throttle, when the click was always going to be refused', function (string $case): void {
    $fx = eraseFixture();
    Feature::activate(AccountErasureExecuteEnabled::NAME);
    $body = ['digest' => str_repeat('a', 64), 'mfa_code' => $fx['codes'][0]];
    if ($case === 'wrong ulid') {
        $body['ulid'] = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    } elseif ($case === 'held') {
        $fx['graph']->user->forceFill(['erasure_held_at' => now()])->saveQuietly();
    } elseif ($case === 'audit-log step unavailable') {
        eraseWithoutAuditScrub();
    } else {
        $fx['graph']->user->forceFill(['deletion_scheduled_for' => now()->addDay()])->saveQuietly();
    }

    $this->actingAs($fx['admin'], 'web_admin')->postJson(ERASE_URL.'/'.$fx['ulid'].'/erase', $body + ['ulid' => $fx['ulid'], 'reason' => 'The grace ended and the creator confirmed'])
        ->assertStatus(422);

    expect($fx['admin']->refresh()->two_factor_recovery_codes)->toHaveCount(count($fx['codes']))
        ->and(app(TwoFactorVerificationThrottle::class)->isSoftLocked($fx['admin']))->toBeFalse();
})->with(['wrong ulid', 'held', 'not due', 'audit-log step unavailable']);

// ── The second factor ───────────────────────────────────────────────────────

it('never feeds a sign-in lockout counter with a wrong code, and respects the second-factor throttle', function (): void {
    $fx = eraseFixture();
    Feature::activate(AccountErasureExecuteEnabled::NAME);
    Queue::fake();
    $digest = eraseDigest($fx['admin'], $fx['ulid']);
    $post = fn (string $code) => $this->actingAs($fx['admin']->refresh(), 'web_admin')->postJson(ERASE_URL.'/'.$fx['ulid'].'/erase', [
        'ulid' => $fx['ulid'], 'reason' => 'The grace ended and the creator confirmed', 'digest' => $digest, 'mfa_code' => $code,
    ]);

    for ($i = 0; $i < TwoFactorVerificationThrottle::SOFT_THRESHOLD; $i++) {
        $post('000000')->assertStatus(403)->assertJsonPath('errors.0.code', 'admin.erasure.execute.mfa_invalid');
    }

    // Soft-locked: even the RIGHT code is refused now, with Retry-After.
    $post(eraseTotp($fx['secret']))
        ->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'admin.erasure.execute.mfa_rate_limited')
        ->assertHeader('Retry-After');

    Event::assertNotDispatched(LoginFailed::class); // the lockout counters hang on this event
    expect(app(FailedLoginTracker::class)->shortWindowCount($fx['admin']->email))->toBe(0)
        ->and(eraseClaimStamp($fx['ulid']))->toBeNull();
    Queue::assertNothingPushed();

    // A suspended enrolment answers as suspended, even for the right code.
    $fx['admin']->forceFill(['two_factor_enrollment_suspended_at' => now()])->saveQuietly();
    Cache::forget('identity:2fa:throttle:'.$fx['admin']->id);
    $post(eraseTotp($fx['secret']))->assertStatus(423)->assertJsonPath('errors.0.code', 'admin.erasure.execute.mfa_suspended');
    Queue::assertNothingPushed();
});

it('limits the click itself to ten an hour per admin', function (): void {
    $fx = eraseFixture();
    Feature::activate(AccountErasureExecuteEnabled::NAME);
    Queue::fake();
    $post = fn () => $this->actingAs($fx['admin'], 'web_admin')->postJson(ERASE_URL.'/'.$fx['ulid'].'/erase', [
        'ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'reason' => 'The grace ended and the creator confirmed', 'digest' => str_repeat('a', 64),
    ]);

    for ($i = 0; $i < 10; $i++) {
        $post()->assertStatus(422);
    }

    $post()->assertStatus(429)->assertJsonPath('errors.0.code', 'admin.erasure.execute.rate_limited')->assertHeader('Retry-After');
});

it('accepts a TOTP, and accepts a recovery code once and burns it', function (): void {
    $fx = eraseFixture();
    Feature::activate(AccountErasureExecuteEnabled::NAME);
    Queue::fake();

    eraseClick($fx, ['mfa_code' => $fx['codes'][0]])->assertStatus(202);

    expect($fx['admin']->refresh()->two_factor_recovery_codes)->toHaveCount(count($fx['codes']) - 1);
});

it('takes a TOTP at the click', function (): void {
    $fx = eraseFixture();
    Feature::activate(AccountErasureExecuteEnabled::NAME);
    Queue::fake();

    eraseClick($fx)->assertStatus(202);

    expect(eraseClaimStamp($fx['ulid']))->not->toBeNull();
});

// ── The click that goes through ─────────────────────────────────────────────

it('claims once, queues once on the erasure queue and connection, answers 202 with the row and the status url', function (): void {
    $fx = eraseFixture();
    Feature::activate(AccountErasureExecuteEnabled::NAME);
    Queue::fake();

    $response = eraseClick($fx)->assertStatus(202);

    $audit = AuditLog::query()->where('action', AuditAction::AccountErasureStarted)->sole();
    expect(eraseClaimStamp($fx['ulid']))->not->toBeNull()
        ->and($response->json('data.attributes.state'))->toBe('in_progress')
        ->and($response->json('data.attributes.run.state'))->toBe('waiting')
        ->and($response->json('meta.queued'))->toBeTrue()
        ->and($response->json('meta.retry'))->toBeFalse()
        ->and($response->json('meta.status_url'))->toBe('/api/v1/admin/compliance/erasure-queue/'.$fx['ulid'])
        ->and($audit->actor_id)->toBe($fx['admin']->id)
        ->and($audit->subject_ulid)->toBe($fx['ulid'])
        ->and($audit->reason)->toBe('The grace ended and the creator confirmed');
    Queue::assertPushed(RunErasureJob::class, 1);
    Queue::assertPushedOn('erasure', RunErasureJob::class, fn (RunErasureJob $job): bool => $job->connection === 'database_erasure'
        && $job->userUlid === $fx['ulid']
        && $job->operatorId === $fx['admin']->id
        && preg_match('/^[a-f0-9]{64}$/', $job->digest) === 1);
});

it('signs the creator out of the account at the click: it answers as an unknown account from the claim', function (): void {
    $fx = eraseFixture();
    Feature::activate(AccountErasureExecuteEnabled::NAME);
    Queue::fake();

    eraseClick($fx)->assertStatus(202);

    expect($fx['graph']->user->refresh()->isErasureClaimed())->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $fx['graph']->user->id)->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $fx['graph']->user->id)->count())->toBe(0);
});

it('does not claim or queue a second time on a second click while the run is queued, and spends no code on it', function (): void {
    $fx = eraseFixture();
    Feature::activate(AccountErasureExecuteEnabled::NAME);
    Queue::fake();
    eraseClick($fx)->assertStatus(202);
    $stamp = eraseClaimStamp($fx['ulid']);

    $second = $this->actingAs($fx['admin'], 'web_admin')->postJson(ERASE_URL.'/'.$fx['ulid'].'/erase', [
        'ulid' => $fx['ulid'], 'reason' => 'Clicked again by mistake', 'digest' => str_repeat('a', 64), 'mfa_code' => $fx['codes'][0],
    ]);

    $second->assertStatus(409)->assertJsonPath('errors.0.code', 'admin.erasure.execute.in_progress')->assertJsonPath('errors.0.meta.run.state', 'waiting');
    expect(eraseClaimStamp($fx['ulid']))->toBe($stamp)
        ->and(eraseStartedAudit())->toBe(1)
        ->and($fx['admin']->refresh()->two_factor_recovery_codes)->toHaveCount(count($fx['codes']));
    Queue::assertPushed(RunErasureJob::class, 1);
});

it('lets a retry through only for an incomplete run, or a run past the stale window, and says so', function (string $case, bool $allowed): void {
    $fx = eraseFixture();
    Feature::activate(AccountErasureExecuteEnabled::NAME);
    Queue::fake();
    eraseClick($fx)->assertStatus(202);

    match ($case) {
        'incomplete' => ErasureLedger::query()->create(['user_ulid' => $fx['ulid'], 'state' => ErasureLedgerState::Incomplete, 'digest' => 'x', 'started_at' => now()]),
        'running' => ErasureLedger::query()->create(['user_ulid' => $fx['ulid'], 'state' => ErasureLedgerState::Started, 'digest' => 'x', 'started_at' => now()->subMinutes(10)]),
        'running, stale' => ErasureLedger::query()->create(['user_ulid' => $fx['ulid'], 'state' => ErasureLedgerState::Started, 'digest' => 'x', 'started_at' => now()->subSeconds(3901)]),
        'waiting, stale' => DB::table('users')->where('ulid', $fx['ulid'])->update(['erasure_started_at' => now()->subSeconds(3901)]),
        'waiting, almost stale' => DB::table('users')->where('ulid', $fx['ulid'])->update(['erasure_started_at' => now()->subSeconds(3899)]),
        default => throw new InvalidArgumentException($case),
    };

    $response = $this->actingAs($fx['admin']->refresh(), 'web_admin')->postJson(ERASE_URL.'/'.$fx['ulid'].'/erase', [
        'ulid' => $fx['ulid'], 'reason' => 'Plan reviewed again, confirming', 'digest' => eraseDigest($fx['admin'], $fx['ulid']), 'mfa_code' => eraseTotp($fx['secret']),
    ]);

    expect($response->status())->toBe($allowed ? 202 : 409);
    Queue::assertPushed(RunErasureJob::class, $allowed ? 2 : 1);
    if ($allowed) {
        $row = AuditLog::query()->where('action', AuditAction::AccountErasureStarted)->latest('id')->firstOrFail();
        expect($response->json('meta.retry'))->toBeTrue()
            ->and($row->actor_id)->toBe($fx['admin']->id)
            ->and((array) $row->metadata)->toBe(['mode' => 'retry'])
            ->and($row->reason)->toBe('Plan reviewed again, confirming');
    }
})->with([
    'incomplete' => ['incomplete', true],
    'running' => ['running', false],
    'running, stale' => ['running, stale', true],
    'waiting, stale' => ['waiting, stale', true],
    'waiting, almost stale' => ['waiting, almost stale', false],
]);

it('queues nothing when the gate refuses under the lock, after the second factor already passed', function (): void {
    $fx = eraseFixture();
    Feature::activate(AccountErasureExecuteEnabled::NAME);
    Queue::fake();
    // A recovery code being consumed is the one event between the preflight and the claim: a hold lands there.
    Event::listen(TwoFactorRecoveryCodeConsumed::class, function () use ($fx): void {
        DB::table('users')->where('ulid', $fx['ulid'])->update(['erasure_held_at' => now()]);
    });

    $response = eraseClick($fx, ['mfa_code' => $fx['codes'][0]]);

    $response->assertStatus(422)->assertJsonPath('errors.0.code', 'admin.erasure.execute.not_ready');
    expect($response->json('errors.0.meta.codes'))->toBe(['held'])
        ->and(eraseClaimStamp($fx['ulid']))->toBeNull()
        ->and(eraseStartedAudit())->toBe(0);
    Queue::assertNothingPushed();
});

it('queues only after the transaction it was called in has committed (a real queue table, not the fake: the fake ignores commits)', function (): void {
    $fx = eraseFixture();
    $user = $fx['graph']->user;
    $starter = app(EraseStarter::class);
    $inside = null;

    DB::transaction(function () use ($starter, $user, $fx, &$inside): void {
        $starter->start($user, $fx['admin'], 'The grace ended and the creator confirmed', str_repeat('a', 64), false);
        $inside = DB::table('jobs')->count();
    });

    expect($inside)->toBe(0)
        ->and(DB::table('jobs')->where('queue', 'erasure')->count())->toBe(1);
});

// ── End to end: the click, then the job on the worker ───────────────────────

it('erases end to end: the click, then the job; the creator is erased, the agency told, the ledger completed, the claim kept, the operator recorded', function (): void {
    $fx = eraseFixture();
    Feature::activate(AccountErasureExecuteEnabled::NAME);
    Queue::fake();
    eraseClick($fx)->assertStatus(202);

    Queue::assertPushed(RunErasureJob::class, function (RunErasureJob $job): bool {
        $job->handle(app(ErasureRun::class), app(ErasureClaimer::class));

        return true;
    });

    $user = User::withTrashed()->findOrFail($fx['graph']->user->id);
    $ledger = ErasureLedger::query()->sole();
    expect($user->isErased())->toBeTrue()
        ->and($user->erasure_started_at)->not->toBeNull()
        ->and($ledger->state)->toBe(ErasureLedgerState::Completed)
        ->and($ledger->run_by_user_id)->toBe($fx['admin']->id)
        ->and(AuditLog::query()->where('action', AuditAction::AccountErased)->sole()->actor_id)->toBe($fx['admin']->id)
        ->and(AuditLog::query()->where('action', AuditAction::AccountErasureStarted)->sole()->actor_id)->toBe($fx['admin']->id)
        ->and(AuditLog::query()->where('action', AuditAction::AccountErasureClaimReleased)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::AssignmentCancelled)->count())->toBe(1);

    // The status endpoint, after: an erased and completed account is no longer a queue row.
    $this->actingAs($fx['admin'], 'web_admin')->getJson(ERASE_URL.'/'.$fx['ulid'])->assertStatus(404);
    $erased = $this->actingAs($fx['admin'], 'web_admin')->getJson(ERASE_URL.'?tab=erased')->json('data');
    expect(array_column($erased, 'id'))->toBe([$fx['ulid']]);
});

it('says the plan is not ready when the audit-log step is not available, and why', function (): void {
    $fx = eraseFixture();
    $scrub = new FakeAuditScrub;
    $scrub->available = false;
    app()->instance(AuditScrub::class, $scrub);

    $plan = $this->actingAs($fx['admin'], 'web_admin')->getJson(ERASE_URL.'/'.$fx['ulid'].'/plan')->assertOk();

    expect($plan->json('data.attributes.ready'))->toBeFalse()
        ->and($plan->json('data.attributes.blockers'))->toBe(['audit_scrub_unavailable'])
        ->and($plan->json('data.attributes.audit_scrub_available'))->toBeFalse();
});

it('queues nothing for a second click that reaches the claim after the first made it (two clicks past the preflight together)', function (): void {
    $fx = eraseFixture();
    Queue::fake();
    $starter = app(EraseStarter::class);

    $first = $starter->start($fx['graph']->user->refresh(), $fx['admin'], 'Subject asked, ticket on file', str_repeat('a', 64), false);
    $second = $starter->start($fx['graph']->user->refresh(), $fx['admin'], 'Clicked twice at the same time', str_repeat('a', 64), false);

    expect($first->queued)->toBeTrue()
        ->and($second->queued)->toBeFalse()
        ->and($second->retry)->toBeFalse();
    Queue::assertPushed(RunErasureJob::class, 1);
});
