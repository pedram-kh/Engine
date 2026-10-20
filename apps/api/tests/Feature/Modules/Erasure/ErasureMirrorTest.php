<?php

declare(strict_types=1);

use App\Modules\Erasure\Enums\ErasureLedgerState;
use App\Modules\Erasure\Execution\AuditScrub;
use App\Modules\Erasure\Execution\UnavailableAuditScrub;
use App\Modules\Erasure\Guards\ErasureRefused;
use App\Modules\Erasure\Ledger\DiskLedgerMirror;
use App\Modules\Erasure\Ledger\LedgerMirror;
use App\Modules\Erasure\Ledger\MirrorWriteFailed;
use App\Modules\Erasure\Models\ErasureLedger;
use App\Modules\Erasure\Run\ErasureReapply;
use App\Modules\Erasure\Run\ErasureRun;
use App\Modules\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ErasureGraph;
use Tests\Support\ErasureSnapshot;
use Tests\Support\FakeAuditScrub;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function mirrorSetup(): void
{
    Storage::fake('media');
    app()->instance(AuditScrub::class, new FakeAuditScrub);
}

/** Erase one subject through the real run, so its mirror entry is written the real way. */
function mirrorErase(ErasureGraph $graph): ErasureLedger
{
    $run = app(ErasureRun::class);

    return $run->execute($graph->user, $run->plan($graph->user)->digest(), $graph->user->ulid);
}

/** @return list<string> */
function mirrorFiles(): array
{
    return array_values(array_map(strval(...), Storage::disk('media')->files(DiskLedgerMirror::PREFIX)));
}

it('writes one entry per completed erasure, holding the ulid and a timestamp and nothing else', function (): void {
    mirrorSetup();
    $graph = ErasureGraph::seedSubject('a');

    $ledger = mirrorErase($graph);
    $key = 'erasure-ledger/'.$graph->user->ulid.'.json';
    $content = (string) Storage::disk('media')->get($key);
    $decoded = json_decode($content, true);

    expect(mirrorFiles())->toBe([$key])
        ->and(array_keys((array) $decoded))->toBe(['user_ulid', 'completed_at'])
        ->and($decoded['user_ulid'])->toBe($graph->user->ulid)
        ->and($decoded['completed_at'])->toBe($ledger->completed_at?->toIso8601String())
        ->and($content)->not->toContain($graph->email)
        ->and($content)->not->toContain('Subject a');
});

it('writes the entry before the ledger says completed, and never claims completed without it', function (): void {
    mirrorSetup();
    $graph = ErasureGraph::seedSubject('a');
    $failing = new class implements LedgerMirror
    {
        public int $calls = 0;

        public function record(string $userUlid, CarbonInterface $completedAt): void
        {
            $this->calls++;
            throw MirrorWriteFailed::forEntry();
        }

        public function entries(): array
        {
            return [];
        }
    };
    app()->instance(LedgerMirror::class, $failing);
    $run = app(ErasureRun::class);
    $digest = $run->plan($graph->user)->digest();

    expect(fn () => $run->execute($graph->user, $digest, $graph->user->ulid))->toThrow(MirrorWriteFailed::class);

    $ledger = ErasureLedger::query()->sole();

    expect($ledger->state)->toBe(ErasureLedgerState::DbDone)
        ->and($ledger->completed_at)->toBeNull()
        ->and(User::withTrashed()->findOrFail($graph->user->id)->isErased())->toBeTrue();

    // The mirror comes back; resume finishes from the ledger alone.
    app()->instance(LedgerMirror::class, new DiskLedgerMirror);
    $resumed = app(ErasureRun::class)->resume(User::withTrashed()->findOrFail($graph->user->id));

    expect($resumed->state)->toBe(ErasureLedgerState::Completed)
        ->and(mirrorFiles())->toBe([DiskLedgerMirror::PREFIX.'/'.$graph->user->ulid.'.json']);
});

it('keeps the first completion time: recording again never overwrites an entry', function (): void {
    mirrorSetup();
    $mirror = new DiskLedgerMirror;
    $ulid = '01JABCDEFGHJKMNPQRSTVWXYZ0';

    $mirror->record($ulid, now()->subYear());
    $first = (string) Storage::disk('media')->get(DiskLedgerMirror::PREFIX.'/'.$ulid.'.json');
    $mirror->record($ulid, now());

    expect((string) Storage::disk('media')->get(DiskLedgerMirror::PREFIX.'/'.$ulid.'.json'))->toBe($first);
});

it('refuses to write an entry for something that is not a ulid (a path cannot be smuggled in)', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andReturn(false);
    $disk->shouldNotReceive('put');
    Storage::set('media', $disk);

    expect(fn () => (new DiskLedgerMirror)->record('../../creators/x', now()))->toThrow(MirrorWriteFailed::class);
});

it('treats a store that says no, or throws, as a failed write (never a silent success)', function (string $how): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andReturn(false);
    if ($how === 'returns false') {
        $disk->shouldReceive('put')->andReturn(false);
    } else {
        $disk->shouldReceive('put')->andThrow(new RuntimeException('bucket said no'));
    }
    Storage::set('media', $disk);

    expect(fn () => (new DiskLedgerMirror)->record('01JABCDEFGHJKMNPQRSTVWXYZ0', now()))->toThrow(MirrorWriteFailed::class);
})->with(['returns false', 'throws']);

it('has no way to remove an entry: the mirror class and its directory contain no delete', function (): void {
    $source = '';
    foreach (glob(app_path('Modules/Erasure/Ledger/*.php')) ?: [] as $file) {
        $source .= (string) file_get_contents($file);
    }

    expect(preg_match('/->delete\(|->deleteDirectory\(|function (delete|remove|forget)/i', $source))->toBe(0)
        ->and(array_values(array_filter(get_class_methods(DiskLedgerMirror::class), fn (string $m): bool => preg_match('/delete|remove|forget/i', $m) === 1)))->toBe([]);
});

it('reads only entries whose name and content agree and whose name is a ulid', function (): void {
    mirrorSetup();
    $mirror = new DiskLedgerMirror;
    $good = '01JABCDEFGHJKMNPQRSTVWXYZ0';
    $mirror->record($good, now());
    Storage::disk('media')->put(DiskLedgerMirror::PREFIX.'/notes.txt', 'hello');
    Storage::disk('media')->put(DiskLedgerMirror::PREFIX.'/not-a-ulid.json', json_encode(['user_ulid' => 'not-a-ulid', 'completed_at' => 'x'], JSON_THROW_ON_ERROR));
    Storage::disk('media')->put(DiskLedgerMirror::PREFIX.'/01JZZZZZZZZZZZZZZZZZZZZZZ9.json', json_encode(['user_ulid' => '01JTAMPEXXXXXXXXXXXXXXXXX1', 'completed_at' => 'x'], JSON_THROW_ON_ERROR));
    Storage::disk('media')->put(DiskLedgerMirror::PREFIX.'/01JYYYYYYYYYYYYYYYYYYYYYY8.json', '{broken');

    expect(array_map(fn ($e): string => $e->userUlid, $mirror->entries()))->toBe([$good]);
});

/**
 * Simulates a restore: the mirror says `a` was erased, the restored database
 * still has `a` whole (and a bystander `b`, and a creator `c` that is erased
 * in the restored data too).
 *
 * @return array{0: ErasureGraph, 1: ErasureGraph, 2: ErasureGraph}
 */
function mirrorRestoreScenario(): array
{
    mirrorSetup();
    $a = ErasureGraph::seedSubject('a');
    $b = ErasureGraph::seedSubject('b');
    $c = ErasureGraph::seedSubject('c');
    $a->putFiles();
    $b->putFiles();
    mirrorErase($c);

    $mirror = new DiskLedgerMirror;
    $mirror->record($a->user->ulid, now()->subDay());
    $mirror->record('01JKMNPQRSTVWXYZ0123456789', now()->subDays(2));

    return [$a, $b, $c];
}

it('lists, after a restore, exactly the people the mirror says were erased and the database lost', function (): void {
    [$a, $b] = mirrorRestoreScenario();
    $before = ErasureSnapshot::tables();

    $plan = app(ErasureReapply::class)->plan();

    expect($plan->mirrorEntries)->toBe(3)
        ->and($plan->alreadyErased)->toBe(1)
        ->and($plan->unknownUsers)->toBe(1)
        ->and(array_map(fn (array $s): string => $s['user']->ulid, $plan->subjects))->toBe([$a->user->ulid])
        ->and($plan->subjects[0]['user']->ulid)->not->toBe($b->user->ulid)
        ->and(ErasureSnapshot::tables())->toBe($before);
});

it('re-erases them with the same engine and leaves everyone else alone', function (): void {
    [$a, $b] = mirrorRestoreScenario();
    $reapply = app(ErasureReapply::class);
    $plan = $reapply->plan();
    $bBefore = (array) DB::table('users')->where('id', $b->user->id)->first();

    $done = $reapply->execute($plan->digest(), 1);

    expect($done)->toBe([$a->user->ulid])
        ->and(User::withTrashed()->findOrFail($a->user->id)->isErased())->toBeTrue()
        ->and((array) DB::table('users')->where('id', $b->user->id)->first())->toBe($bBefore)
        ->and(array_values(array_filter($a->files['delete'], fn (string $k): bool => Storage::disk('media')->exists($k))))->toBe([])
        ->and(array_values(array_filter($b->files['delete'], fn (string $k): bool => ! Storage::disk('media')->exists($k))))->toBe([])
        ->and(ErasureLedger::query()->where('user_ulid', $a->user->ulid)->sole()->state)->toBe(ErasureLedgerState::Completed)
        ->and($reapply->plan()->subjects)->toBe([]);
});

it('refuses the whole batch when any one person is refused, so nobody is half-done', function (): void {
    mirrorSetup();
    $x = ErasureGraph::seedSubject('a');
    $y = ErasureGraph::seedSubject('b');
    $mirror = new DiskLedgerMirror;
    $mirror->record($x->user->ulid, now());
    $mirror->record($y->user->ulid, now());
    // Entries are processed in ulid order: block the one that would be last, so the first would otherwise go through.
    [$first, $last] = strcmp($x->user->ulid, $y->user->ulid) < 0 ? [$x, $y] : [$y, $x];
    DB::table('campaign_assignments')->where('creator_id', $last->creator->id)->update(['status' => 'payment_held']);
    $reapply = app(ErasureReapply::class);

    expect(fn () => $reapply->execute($reapply->plan()->digest(), 2))->toThrow(ErasureRefused::class)
        ->and(User::withTrashed()->findOrFail($first->user->id)->isErased())->toBeFalse()
        ->and(ErasureLedger::query()->count())->toBe(0);
});

it('records who ran the reapply on each ledger row', function (): void {
    [$a] = mirrorRestoreScenario();
    $operator = User::factory()->create();
    $reapply = app(ErasureReapply::class);

    $reapply->execute($reapply->plan()->digest(), 1, $operator);

    expect(ErasureLedger::query()->where('user_ulid', $a->user->ulid)->sole()->run_by_user_id)->toBe($operator->id);
});

it('refuses a digest from before the data changed (a stale list is not approved)', function (): void {
    [$a] = mirrorRestoreScenario();
    $reapply = app(ErasureReapply::class);
    $stale = $reapply->plan()->digest();
    DB::table('creator_payout_methods')->where('creator_id', $a->creator->id)->delete();

    expect(fn () => $reapply->execute($stale, 1))->toThrow(ErasureRefused::class)
        ->and(User::withTrashed()->findOrFail($a->user->id)->isErased())->toBeFalse();
});

it('does not skip an entry: every person in the plan is re-erased, in one pass', function (): void {
    mirrorSetup();
    $a = ErasureGraph::seedSubject('a');
    $b = ErasureGraph::seedSubject('b');
    $mirror = new DiskLedgerMirror;
    $mirror->record($a->user->ulid, now()->subDay());
    $mirror->record($b->user->ulid, now()->subDay());
    $reapply = app(ErasureReapply::class);

    $done = $reapply->execute($reapply->plan()->digest(), 2);

    expect($done)->toHaveCount(2)
        ->and(User::withTrashed()->findOrFail($a->user->id)->isErased())->toBeTrue()
        ->and(User::withTrashed()->findOrFail($b->user->id)->isErased())->toBeTrue();
});

it('refuses a reapply, and writes nothing, on a wrong digest, a wrong count, a refusal or a missing audit scrub', function (string $case): void {
    [$a] = mirrorRestoreScenario();
    $reapply = app(ErasureReapply::class);
    $digest = $reapply->plan()->digest();
    $count = 1;

    if ($case === 'wrong digest') {
        $digest = str_repeat('0', 64);
    } elseif ($case === 'wrong count') {
        $count = 2;
    } elseif ($case === 'payment held') {
        DB::table('campaign_assignments')->where('creator_id', $a->creator->id)->update(['status' => 'payment_held']);
        $digest = $reapply->plan()->digest();
    } else {
        app()->instance(AuditScrub::class, new UnavailableAuditScrub);
        $reapply = app(ErasureReapply::class);
        $digest = $reapply->plan()->digest();
    }
    $before = ErasureSnapshot::tables();
    $disk = Storage::disk('media')->allFiles();

    expect(fn () => $reapply->execute($digest, $count))->toThrow(ErasureRefused::class)
        ->and(ErasureSnapshot::tables())->toBe($before)
        ->and(Storage::disk('media')->allFiles())->toBe($disk);
})->with(['wrong digest', 'wrong count', 'payment held', 'scrub unavailable']);

it('prints ulids and counts only, and writes nothing on a dry-run', function (): void {
    [$a] = mirrorRestoreScenario();
    $before = ErasureSnapshot::tables();

    $code = Artisan::call('erasure:reapply');
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain($a->user->ulid)->toContain('1 to re-erase')->toContain('Digest:')
        ->and($out)->not->toContain($a->email)
        ->and($out)->not->toContain('Subject a')
        ->and($out)->not->toContain('creators/')
        ->and(ErasureSnapshot::tables())->toBe($before);
});

it('re-erases from the command with the digest and the count, and refuses without them', function (): void {
    [$a] = mirrorRestoreScenario();
    Artisan::call('erasure:reapply');
    $digest = preg_match('/Digest: ([a-f0-9]{64})/', Artisan::output(), $m) === 1 ? $m[1] : '';

    $bare = Artisan::call('erasure:reapply', ['--execute' => true]);
    $bareOut = Artisan::output();
    $wrong = Artisan::call('erasure:reapply', ['--execute' => true, '--confirm-digest' => $digest, '--confirm-count' => '5']);
    $ok = Artisan::call('erasure:reapply', ['--execute' => true, '--confirm-digest' => $digest, '--confirm-count' => '1']);

    expect($bare)->toBe(1)->and($bareOut)->toContain('needs --confirm-digest and --confirm-count')
        ->and($wrong)->toBe(1)
        ->and($ok)->toBe(0)->and(Artisan::output())->toContain('Re-erased 1')
        ->and(User::withTrashed()->findOrFail($a->user->id)->isErased())->toBeTrue();
});

it('is never scheduled and has no route, like the run', function (): void {
    $mine = '/erasure:reapply|ErasureReapply/i';
    $files = '';
    foreach (['routes/web.php', 'routes/api.php', 'routes/console.php', 'bootstrap/app.php'] as $file) {
        $files .= (string) file_get_contents(base_path($file));
    }

    expect(preg_match($mine, $files))->toBe(0);
});
