<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Erasure\Enums\ErasureLedgerState;
use App\Modules\Erasure\Execution\AuditScrub;
use App\Modules\Erasure\Execution\UnavailableAuditScrub;
use App\Modules\Erasure\Files\MediaDiskObjectStore;
use App\Modules\Erasure\Files\ObjectDeletionFailed;
use App\Modules\Erasure\Files\ObjectStore;
use App\Modules\Erasure\Guards\ErasureRefused;
use App\Modules\Erasure\Models\ErasureLedger;
use App\Modules\Erasure\Run\ErasureRun;
use App\Modules\Erasure\Subject\SubjectNotErasable;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ErasureEvents;
use Tests\Support\ErasureGraph;
use Tests\Support\ErasureSnapshot;
use Tests\Support\FakeAuditScrub;
use Tests\Support\RecordingObjectStore;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{0: ErasureGraph, 1: ErasureRun, 2: FakeAuditScrub, 3: RecordingObjectStore, 4: ErasureEvents}
 */
function erasureRunFixture(string $tag = 'a'): array
{
    Storage::fake('media');
    $events = new ErasureEvents;
    $scrub = new FakeAuditScrub($events);
    $store = new RecordingObjectStore(new MediaDiskObjectStore, $events);
    app()->instance(AuditScrub::class, $scrub);
    app()->instance(ObjectStore::class, $store);

    $graph = ErasureGraph::seedSubject($tag);
    $graph->putFiles();

    return [$graph, app(ErasureRun::class), $scrub, $store, $events];
}

function erasureDisk(): array
{
    $files = Storage::disk('media')->allFiles();
    sort($files);

    return $files;
}

it('plans without writing: counts, objects, a digest, and the refusals as data', function (): void {
    [$graph, $run, $scrub] = erasureRunFixture();
    ErasureGraph::seedSubject('b');
    $before = ErasureSnapshot::tables();
    $disk = erasureDisk();

    $plan = $run->plan($graph->user);

    expect($plan->refusals)->toBe([])
        ->and($plan->blockers())->toBe([])
        ->and($plan->files->keys)->toBe($graph->files['delete'])
        ->and($plan->digest())->toMatch('/^[a-f0-9]{64}$/')
        ->and($scrub->runs)->toBe(0)
        ->and(ErasureSnapshot::tables())->toBe($before)
        ->and(erasureDisk())->toBe($disk);
});

it('executes end to end: objects gone, database anonymised, ledger completed, one account.erased row of counts only', function (): void {
    [$graph, $run, $scrub] = erasureRunFixture();
    $other = ErasureGraph::seedSubject('b');
    $other->putFiles();
    $operator = User::factory()->createOne();
    $plan = $run->plan($graph->user);
    $agencyAuditBefore = AuditLog::query()->where('actor_id', $graph->agencyMember->id)->count();

    $ledger = $run->execute($graph->user, $plan->digest(), $graph->user->ulid, $operator);

    $row = AuditLog::query()->where('action', AuditAction::AccountErased)->sole();
    $printed = json_encode([$row->metadata, $row->reason, $row->before, $row->after, $row->ip, $row->user_agent], JSON_THROW_ON_ERROR);

    expect($ledger->state)->toBe(ErasureLedgerState::Completed)
        ->and($ledger->completed_at)->not->toBeNull()
        ->and($ledger->run_by_user_id)->toBe($operator->id)
        ->and($ledger->user_ulid)->toBe($graph->user->ulid)
        ->and($ledger->digest)->toBe($plan->digest())
        ->and($scrub->runs)->toBe(1)
        ->and(User::withTrashed()->findOrFail($graph->user->id)->isErased())->toBeTrue()
        ->and(User::withTrashed()->findOrFail($other->user->id)->isErased())->toBeFalse()
        ->and(array_values(array_filter($graph->files['delete'], fn (string $k): bool => Storage::disk('media')->exists($k))))->toBe([])
        ->and(array_values(array_filter([...$graph->files['keep'], ...$other->files['delete']], fn (string $k): bool => ! Storage::disk('media')->exists($k))))->toBe([])
        ->and($row->reason)->toBe('erasure '.$ledger->ulid)
        ->and($row->actor_id)->toBe($operator->id)
        ->and($row->subject_ulid)->toBe($graph->user->ulid)
        ->and(Arr::get((array) $row->metadata, 'ledger_ulid'))->toBe($ledger->ulid)
        ->and($printed)->not->toContain($graph->email)
        ->and($printed)->not->toContain($graph->user->name)
        ->and($printed)->not->toContain((string) $graph->creator->display_name)
        ->and(AuditLog::query()->where('actor_id', $graph->agencyMember->id)->count())->toBe($agencyAuditBefore);
});

it('puts the stages in order: audit scrub, then objects, then the database', function (): void {
    [$graph, $run, , , $events] = erasureRunFixture();
    $plan = $run->plan($graph->user);
    DB::listen(function ($query) use ($events): void {
        if (preg_match('/^\s*delete from "sessions"/i', $query->sql) === 1) {
            $events->add('database');
        }
    });

    $run->execute($graph->user, $plan->digest(), $graph->user->ulid);

    expect($events->collapsed())->toBe(['scrub', 'objects', 'database']);
});

it('refuses, and writes nothing, when a gate fails', function (string $case): void {
    [$graph, $run, $scrub, $store] = erasureRunFixture();
    $plan = $run->plan($graph->user);
    $digest = $plan->digest();
    $ulid = $graph->user->ulid;
    $why = null;

    if ($case === 'wrong digest') {
        $digest = str_repeat('0', 64);
        $why = 'digest';
    } elseif ($case === 'wrong ulid') {
        $ulid = 'NOTTHESUBJECT';
        $why = 'typed ulid';
    } elseif ($case === 'stale digest') {
        DB::table('sessions')->insert(['id' => 'late', 'user_id' => $graph->user->id, 'ip_address' => '203.0.113.9', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => time()]);
        $why = 'digest';
    } elseif ($case === 'payment held') {
        DB::table('campaign_assignments')->where('creator_id', $graph->creator->id)->update(['status' => 'payment_held']);
        $why = 'payment state';
        $digest = $run->plan($graph->user)->digest();
    } elseif ($case === 'real vendor') {
        DB::table('creator_payout_methods')->where('creator_id', $graph->creator->id)->update(['provider' => 'stripe']);
        $why = 'stripe';
        $digest = $run->plan($graph->user)->digest();
    }

    $before = ErasureSnapshot::tables();
    $disk = erasureDisk();

    $execute = fn () => $run->execute($graph->user->refresh(), $digest, $ulid);

    expect($execute)->toThrow(ErasureRefused::class, (string) $why)
        ->and(ErasureSnapshot::tables())->toBe($before)
        ->and(erasureDisk())->toBe($disk)
        ->and($scrub->runs)->toBe(0)
        ->and($store->deleted)->toBe([])
        ->and(ErasureLedger::query()->count())->toBe(0);
})->with(['wrong digest', 'wrong ulid', 'stale digest', 'payment held', 'real vendor']);

it('refuses to execute while the audit scrub is unavailable (stage 4e is not built): nothing is touched', function (): void {
    Storage::fake('media');
    app()->instance(AuditScrub::class, new UnavailableAuditScrub);
    $graph = ErasureGraph::seedSubject('a');
    $graph->putFiles();
    $run = app(ErasureRun::class);
    $plan = $run->plan($graph->user);
    $before = ErasureSnapshot::tables();
    $disk = erasureDisk();

    expect($plan->auditScrubAvailable)->toBeFalse()
        ->and($plan->blockers())->toHaveCount(1)
        ->and(fn () => $run->execute($graph->user, $plan->digest(), $graph->user->ulid))->toThrow(ErasureRefused::class, 'audit scrub')
        ->and(ErasureSnapshot::tables())->toBe($before)
        ->and(erasureDisk())->toBe($disk);
});

it('refuses a subject that is not a creator, and one that is already erased', function (): void {
    [$graph, $run] = erasureRunFixture();
    $plan = $run->plan($graph->user);
    $run->execute($graph->user, $plan->digest(), $graph->user->ulid);

    $erased = User::withTrashed()->findOrFail($graph->user->id);
    $agency = User::factory()->agencyAdmin()->createOne();

    expect(fn () => $run->execute($erased, $plan->digest(), $erased->ulid))->toThrow(SubjectNotErasable::class)
        ->and(fn () => $run->plan($agency))->toThrow(SubjectNotErasable::class);
});

it('is resumable after a failure in each stage: the account stays intact, the ledger says incomplete, a second run finishes', function (string $failure): void {
    [$graph, $run, $scrub, $store] = erasureRunFixture();
    ErasureGraph::seedSubject('b');
    $plan = $run->plan($graph->user);
    $auditWriteFails = new stdClass;
    $auditWriteFails->on = $failure === 'database transaction';

    if ($failure === 'audit scrub') {
        $scrub->fail = true;
    } elseif ($failure === 'objects') {
        $store->failOnDelete = 3;
    } else {
        // A failure inside the database transaction: the audit row of the run cannot be written.
        Event::listen('eloquent.creating: '.AuditLog::class, function () use ($auditWriteFails): void {
            if ($auditWriteFails->on) {
                throw new RuntimeException('disk full');
            }
        });
    }
    $tablesBefore = ErasureSnapshot::tables(['erasure_ledger']);

    expect(fn () => $run->execute($graph->user, $plan->digest(), $graph->user->ulid))->toThrow(RuntimeException::class);

    $first = ErasureLedger::query()->sole();
    $intact = ! User::withTrashed()->findOrFail($graph->user->id)->isErased();
    $dbUntouched = $failure === 'objects' || ErasureSnapshot::tables(['erasure_ledger']) === $tablesBefore;

    // Put the failing step right and run again with a fresh digest.
    $scrub->fail = false;
    $store->failOnDelete = null;
    $auditWriteFails->on = false;
    $second = $run->execute($graph->user->refresh(), $run->plan($graph->user)->digest(), $graph->user->ulid);

    expect($first->state)->toBe(ErasureLedgerState::Incomplete)
        ->and($intact)->toBeTrue()
        ->and($dbUntouched)->toBeTrue()
        ->and($second->state)->toBe(ErasureLedgerState::Completed)
        ->and(ErasureLedger::query()->count())->toBe(2)
        ->and(User::withTrashed()->findOrFail($graph->user->id)->isErased())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::AccountErased)->count())->toBe(1);
})->with(['audit scrub', 'objects', 'database transaction']);

it('finishes a run that stopped after the database committed, from the ledger alone', function (): void {
    [$graph, $run] = erasureRunFixture();
    $run->execute($graph->user, $run->plan($graph->user)->digest(), $graph->user->ulid);
    $ledger = ErasureLedger::query()->sole();
    $ledger->update(['state' => ErasureLedgerState::DbDone, 'completed_at' => null]);

    $resumed = $run->resume(User::withTrashed()->findOrFail($graph->user->id));

    expect($resumed->state)->toBe(ErasureLedgerState::Completed)
        ->and($resumed->completed_at)->not->toBeNull()
        ->and(fn () => $run->resume(User::withTrashed()->findOrFail($graph->user->id)))->toThrow(ErasureRefused::class);
});

it('keeps the ledger free of personal data: counts, ulids and a digest only', function (): void {
    [$graph, $run] = erasureRunFixture();
    $ledger = $run->execute($graph->user, $run->plan($graph->user)->digest(), $graph->user->ulid);

    $printed = json_encode(DB::table('erasure_ledger')->get()->all(), JSON_THROW_ON_ERROR);

    expect($printed)->not->toContain($graph->email)
        ->and($printed)->not->toContain('Subject a')
        ->and($printed)->not->toContain('creators/')
        ->and($ledger->counts)->toHaveKeys(['database', 'audit_scrub', 'files']);
});

it('refuses a second run for the same subject while one holds the lock', function (): void {
    [$graph, $run] = erasureRunFixture();
    $plan = $run->plan($graph->user);
    $lock = Cache::lock('erasure-run:'.$graph->user->ulid, 60);
    $lock->get();

    $attempt = fn () => $run->execute($graph->user, $plan->digest(), $graph->user->ulid);

    expect($attempt)->toThrow(ErasureRefused::class, 'in progress')
        ->and(ErasureLedger::query()->count())->toBe(0);
    $lock->release();
});

it('marks an earlier run that began and never finished as incomplete when a new one starts', function (): void {
    [$graph, $run] = erasureRunFixture();
    $stale = ErasureLedger::query()->create(['user_ulid' => $graph->user->ulid, 'state' => ErasureLedgerState::Started, 'started_at' => now()->subDay()]);

    $run->execute($graph->user, $run->plan($graph->user)->digest(), $graph->user->ulid);

    expect($stale->refresh()->state)->toBe(ErasureLedgerState::Incomplete);
});

it('raises the object failure itself, naming no key', function (): void {
    [$graph, $run, , $store] = erasureRunFixture();
    $plan = $run->plan($graph->user);
    $store->failOnDelete = 1;

    try {
        $run->execute($graph->user, $plan->digest(), $graph->user->ulid);
        $message = 'no exception';
    } catch (ObjectDeletionFailed $e) {
        $message = $e->getMessage();
    }

    expect($message)->not->toContain('creators/')->and($message)->not->toBe('no exception');
});

it('refuses when an object appeared after the dry-run: the digest covers the files, not only the rows', function (): void {
    [$graph, $run, $scrub, $store] = erasureRunFixture();
    $digest = $run->plan($graph->user)->digest();
    Storage::disk('media')->put('creators/'.$graph->creator->ulid.'/avatar/uploaded-after-the-dry-run.jpg', 'new');

    $execute = fn () => $run->execute($graph->user, $digest, $graph->user->ulid);

    expect($execute)->toThrow(ErasureRefused::class, 'digest')
        ->and($scrub->runs)->toBe(0)
        ->and($store->deleted)->toBe([]);
});
