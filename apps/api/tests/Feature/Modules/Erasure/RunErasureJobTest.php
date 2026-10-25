<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Erasure\Claim\ClaimMode;
use App\Modules\Erasure\Claim\ErasureClaimer;
use App\Modules\Erasure\Enums\ErasureLedgerState;
use App\Modules\Erasure\Execution\AuditScrub;
use App\Modules\Erasure\Files\MediaDiskObjectStore;
use App\Modules\Erasure\Files\ObjectStore;
use App\Modules\Erasure\Jobs\RunErasureJob;
use App\Modules\Erasure\Models\ErasureLedger;
use App\Modules\Erasure\Queue\ErasureRunStatus;
use App\Modules\Erasure\Run\ErasureRun;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ErasureEvents;
use Tests\Support\ErasureGraph;
use Tests\Support\FakeAuditScrub;
use Tests\Support\RecordingObjectStore;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => $this->freezeTime());

/*
|--------------------------------------------------------------------------
| AH-104c C3-2 — RunErasureJob: where it runs and how each ending is handled
|--------------------------------------------------------------------------
|
| P1: the default `database` connection redelivers after 300 s; an erasure can run for most
| of an hour. The job lives on its own connection with a `retry_after` above its timeout, read
| only by a dedicated worker. P8: a moved digest or a refusal releases the claim; any other
| failure KEEPS it (the files may already be gone).
*/

/**
 * A claimed, due creator and the digest of the plan the admin "saw".
 *
 * @return array{graph: ErasureGraph, operator: User, digest: string, ulid: string, scrub: FakeAuditScrub, store: RecordingObjectStore}
 */
function jobFixture(bool $scrubAvailable = true): array
{
    Storage::fake('media');
    $events = new ErasureEvents;
    $scrub = new FakeAuditScrub($events);
    $scrub->available = $scrubAvailable;
    $store = new RecordingObjectStore(new MediaDiskObjectStore, $events);
    app()->instance(AuditScrub::class, $scrub);
    app()->instance(ObjectStore::class, $store);

    $graph = ErasureGraph::seedSubject('a');
    $graph->putFiles();
    $graph->user->forceFill(['deletion_requested_at' => now()->subDays(15), 'deletion_scheduled_for' => now()->subDay()])->saveQuietly();
    $operator = User::factory()->platformAdmin()->createOne(['two_factor_confirmed_at' => now()]);

    $digest = app(ErasureRun::class)->plan($graph->user->refresh())->digest();
    app(ErasureClaimer::class)->claim($graph->user->refresh(), $operator, ClaimMode::Normal, 'The grace ended and the creator confirmed');

    return ['graph' => $graph, 'operator' => $operator, 'digest' => $digest, 'ulid' => $graph->user->ulid, 'scrub' => $scrub, 'store' => $store];
}

/** @param  array{operator: User, digest: string, ulid: string}  $fx */
function jobHandle(array $fx, ?string $digest = null): void
{
    (new RunErasureJob($fx['ulid'], $fx['operator']->id, $digest ?? $fx['digest']))->handle(app(ErasureRun::class), app(ErasureClaimer::class));
}

function jobClaim(string $ulid): mixed
{
    return DB::table('users')->where('ulid', $ulid)->value('erasure_started_at');
}

function jobReleases(): int
{
    return AuditLog::query()->where('action', AuditAction::AccountErasureClaimReleased)->count();
}

// ── Where it runs (P1) ──────────────────────────────────────────────────────

it('is pinned to its own connection and queue, one try, an hour of timeout, failing on timeout', function (): void {
    $job = new RunErasureJob('01ARZ3NDEKTSV4RRFFQ69G5FAV', 1, str_repeat('a', 64));

    expect($job->connection)->toBe('database_erasure')
        ->and($job->queue)->toBe('erasure')
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(3600)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and(RunErasureJob::TIMEOUT)->toBe(3600);
});

it('has a retry_after above the job timeout: the spec that would have caught P1', function (): void {
    $erasure = config('queue.connections.database_erasure');
    $default = config('queue.connections.database');

    expect($erasure['retry_after'])->toBeGreaterThan(RunErasureJob::TIMEOUT)
        // The stale window of the Erase button sits above the timeout too: a run is never called stuck while it can still be running.
        ->and(ErasureRunStatus::STALE_AFTER_SECONDS)->toBeGreaterThan(RunErasureJob::TIMEOUT)
        // The same `jobs` table, the same database: only the queue name separates the workers.
        ->and($erasure['driver'])->toBe('database')
        ->and($erasure['table'])->toBe($default['table'])
        ->and($erasure['connection'])->toBe($default['connection'])
        // And the default connection is untouched: 300 s is right for its own jobs.
        ->and($default['retry_after'])->toBe(300);
});

it('is never read by the default worker: no default queue list, script or supervisor names `erasure`', function (): void {
    $horizon = (string) file_get_contents(config_path('horizon.php'));
    $composer = (string) file_get_contents(base_path('composer.json'));

    expect(config('queue.default'))->not->toBe('database_erasure')
        ->and(config('queue.connections.database.queue'))->toBe('default')
        ->and($horizon)->not->toContain('erasure')
        ->and($composer)->not->toContain('erasure');
});

it('is dispatched by the click and nowhere else in application code', function (): void {
    $dispatchers = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php' && preg_match('/RunErasureJob::dispatch|new RunErasureJob|dispatch\(\s*new RunErasureJob/', (string) file_get_contents($file->getPathname())) === 1) {
            $dispatchers[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }
    sort($dispatchers);

    expect($dispatchers)->toBe(['Modules/Erasure/Erase/EraseStarter.php']);
});

// ── Each ending ─────────────────────────────────────────────────────────────

it('completes: the creator erased, the ledger completed with the operator, the claim kept', function (): void {
    $fx = jobFixture();

    jobHandle($fx);

    $user = User::withTrashed()->findOrFail($fx['graph']->user->id);
    $ledger = ErasureLedger::query()->sole();
    expect($user->isErased())->toBeTrue()
        ->and($user->erasure_started_at)->not->toBeNull()
        ->and($ledger->state)->toBe(ErasureLedgerState::Completed)
        ->and($ledger->run_by_user_id)->toBe($fx['operator']->id)
        ->and(jobReleases())->toBe(0);
});

it('releases the claim, audited, when the data moved since the admin looked, and deletes and erases nothing', function (): void {
    $fx = jobFixture();
    $disk = Storage::disk('media')->allFiles();
    // A row the plan counted goes away between the plan and the run: the digest no longer matches.
    DB::table('creator_social_accounts')->where('creator_id', $fx['graph']->creator->id)->limit(1)->delete();

    jobHandle($fx);

    $row = AuditLog::query()->where('action', AuditAction::AccountErasureClaimReleased)->sole();
    expect(jobClaim($fx['ulid']))->toBeNull()
        ->and($row->actor_id)->toBe($fx['operator']->id)
        ->and($row->subject_ulid)->toBe($fx['ulid'])
        ->and((array) $row->metadata)->toBe(['cause' => 'data_changed'])
        ->and(User::withTrashed()->findOrFail($fx['graph']->user->id)->isErased())->toBeFalse()
        ->and(ErasureLedger::query()->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe($disk)
        ->and($fx['scrub']->runs)->toBe(0);
});

it('releases the claim as a refusal, not as moved data, when a guard now refuses', function (): void {
    $fx = jobFixture();
    DB::table('campaign_assignments')->where('creator_id', $fx['graph']->creator->id)->update(['status' => 'payment_held']);

    jobHandle($fx);

    expect(jobClaim($fx['ulid']))->toBeNull()
        ->and((array) AuditLog::query()->where('action', AuditAction::AccountErasureClaimReleased)->sole()->metadata)->toBe(['cause' => 'refused'])
        ->and(ErasureLedger::query()->count())->toBe(0);
});

it('releases the claim as a refusal when the audit scrub is not available, before anything is touched', function (): void {
    $fx = jobFixture(scrubAvailable: false);
    $disk = Storage::disk('media')->allFiles();

    jobHandle($fx);

    expect(jobClaim($fx['ulid']))->toBeNull()
        ->and((array) AuditLog::query()->where('action', AuditAction::AccountErasureClaimReleased)->sole()->metadata)->toBe(['cause' => 'refused'])
        ->and(Storage::disk('media')->allFiles())->toBe($disk);
});

it('does NOT release the claim on any other exception: the files are gone, the ledger says incomplete, the account stays claimed', function (): void {
    $fx = jobFixture();
    Event::listen('eloquent.creating: '.AuditLog::class, function (): void {
        throw new RuntimeException('disk full');
    });
    $disk = Storage::disk('media')->allFiles();

    expect(fn () => jobHandle($fx))->toThrow(RuntimeException::class, 'disk full');

    expect(jobClaim($fx['ulid']))->not->toBeNull()
        ->and(ErasureLedger::query()->sole()->state)->toBe(ErasureLedgerState::Incomplete)
        ->and(User::withTrashed()->findOrFail($fx['graph']->user->id)->isErased())->toBeFalse()
        ->and(Storage::disk('media')->allFiles())->not->toBe($disk) // the file step ran: this is exactly why the claim stays
        ->and(DB::table('audit_logs')->where('action', AuditAction::AccountErasureClaimReleased->value)->count())->toBe(0);
});

it('leaves the claim and logs only the exception class when the queue marks the job failed', function (): void {
    $fx = jobFixture();
    Log::shouldReceive('error')->once()->with('erasure.job.failed', ['user_ulid' => $fx['ulid'], 'exception' => RuntimeException::class]);

    (new RunErasureJob($fx['ulid'], $fx['operator']->id, $fx['digest']))->failed(new RuntimeException('contains subject-a@example.test'));

    expect(jobClaim($fx['ulid']))->not->toBeNull()
        ->and(jobReleases())->toBe(0);
});

it('does nothing on a second delivery after the run finished: no second ledger row, no second audit row', function (): void {
    $fx = jobFixture();
    jobHandle($fx);
    $audit = AuditLog::query()->count();

    jobHandle($fx);

    expect(ErasureLedger::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe($audit);
});

it('finishes a run that stopped after its database stage: the same job, delivered again, completes the ledger', function (): void {
    $fx = jobFixture();
    jobHandle($fx);
    ErasureLedger::query()->sole()->update(['state' => ErasureLedgerState::DbDone, 'completed_at' => null]);

    jobHandle($fx);

    expect(ErasureLedger::query()->sole()->state)->toBe(ErasureLedgerState::Completed);
});

it('skips an unknown subject without throwing, and says so', function (): void {
    Log::shouldReceive('warning')->once()->with('erasure.job.skipped', ['reason' => 'no_user', 'user_ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);
    $operator = User::factory()->platformAdmin()->createOne();

    (new RunErasureJob('01ARZ3NDEKTSV4RRFFQ69G5FAV', $operator->id, str_repeat('a', 64)))->handle(app(ErasureRun::class), app(ErasureClaimer::class));
});

it('does not run for an account that is not claimed', function (): void {
    $fx = jobFixture();
    DB::table('users')->where('ulid', $fx['ulid'])->update(['erasure_started_at' => null]);
    $disk = Storage::disk('media')->allFiles();

    jobHandle($fx);

    expect(User::withTrashed()->findOrFail($fx['graph']->user->id)->isErased())->toBeFalse()
        ->and(ErasureLedger::query()->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe($disk);
});
