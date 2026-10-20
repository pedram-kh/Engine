<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Erasure\Execution\AuditScrub;
use App\Modules\Erasure\Execution\UnavailableAuditScrub;
use App\Modules\Erasure\Models\ErasureLedger;
use App\Modules\Identity\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ErasureGraph;
use Tests\Support\ErasureSnapshot;
use Tests\Support\FakeAuditScrub;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @return array{0: ErasureGraph, 1: string} */
function erasureCommandFixture(bool $scrubAvailable = true): array
{
    Storage::fake('media');
    app()->instance(AuditScrub::class, $scrubAvailable ? new FakeAuditScrub : new UnavailableAuditScrub);
    $graph = ErasureGraph::seedSubject('a');
    $graph->putFiles();

    return [$graph, $graph->user->ulid];
}

function erasureDigestFrom(string $output): string
{
    preg_match('/Digest: ([a-f0-9]{64})/', $output, $m);

    return $m[1] ?? '';
}

it('is a dry-run by default: prints the plan and a digest, changes nothing, and names nobody', function (): void {
    [$graph, $ulid] = erasureCommandFixture();
    $before = ErasureSnapshot::tables();
    $disk = Storage::disk('media')->allFiles();

    $code = Artisan::call('erasure:run', ['user_ulid' => $ulid]);
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and(erasureDigestFrom($out))->toMatch('/^[a-f0-9]{64}$/')
        ->and($out)->toContain('sessions')->toContain('Objects to delete: 8')->toContain('Vendor calls: 0')->toContain('Dry-run only')
        ->and($out)->not->toContain($graph->email)
        ->and($out)->not->toContain('Subject a')
        ->and($out)->not->toContain('Display a')
        ->and($out)->not->toContain((string) $graph->creator->phone)
        ->and($out)->not->toContain('creators/')
        ->and($out)->not->toContain('messages/')
        ->and(ErasureSnapshot::tables())->toBe($before)
        ->and(Storage::disk('media')->allFiles())->toBe($disk);
});

it('exits non-zero on a dry-run that an execute would refuse, and says why', function (): void {
    [, $ulid] = erasureCommandFixture(scrubAvailable: false);

    $code = Artisan::call('erasure:run', ['user_ulid' => $ulid]);
    $out = Artisan::output();

    expect($code)->toBe(1)
        ->and($out)->toContain('REFUSED')->toContain('audit scrub')->toContain('NOT AVAILABLE');
});

it('executes only with the digest and the typed ulid, and then erases', function (): void {
    [$graph, $ulid] = erasureCommandFixture();
    Artisan::call('erasure:run', ['user_ulid' => $ulid]);
    $digest = erasureDigestFrom(Artisan::output());
    $operator = User::factory()->createOne();

    $code = Artisan::call('erasure:run', ['user_ulid' => $ulid, '--execute' => true, '--confirm-digest' => $digest, '--confirm-ulid' => $ulid, '--operator' => $operator->ulid]);
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain('Erased')->toContain('completed')
        ->and($out)->not->toContain($graph->email)
        ->and(User::withTrashed()->findOrFail($graph->user->id)->isErased())->toBeTrue()
        ->and(ErasureLedger::query()->sole()->run_by_user_id)->toBe($operator->id);
});

it('refuses an execute without both confirmations, with a wrong digest or ulid, or with an unknown operator or subject', function (array $options, string $needle): void {
    [$graph, $ulid] = erasureCommandFixture();
    $before = ErasureSnapshot::tables();

    $code = Artisan::call('erasure:run', ['user_ulid' => $options['subject'] ?? $ulid] + ($options['args'] ?? []));
    $out = Artisan::output();

    expect($code)->toBe(1)
        ->and($out)->toContain($needle)
        ->and(ErasureSnapshot::tables())->toBe($before)
        ->and(ErasureLedger::query()->count())->toBe(0);
})->with([
    'no confirmations' => [['args' => ['--execute' => true]], 'needs --confirm-digest'],
    'only a digest' => [['args' => ['--execute' => true, '--confirm-digest' => 'abc']], 'needs --confirm-digest'],
    'a wrong digest' => [['args' => ['--execute' => true, '--confirm-digest' => 'abc', '--confirm-ulid' => 'WHATEVER']], 'Erasure refused'],
    'an unknown subject' => [['subject' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ'], 'No user has that ulid'],
    'an unknown operator' => [['args' => ['--operator' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ']], 'operator ulid'],
]);

it('prints the exception class, not its message, when a step fails (a database message can carry the bound values)', function (): void {
    [$graph, $ulid] = erasureCommandFixture();
    Artisan::call('erasure:run', ['user_ulid' => $ulid]);
    $digest = erasureDigestFrom(Artisan::output());
    Event::listen('eloquent.creating: '.AuditLog::class, function () use ($graph): void {
        throw new RuntimeException('could not write for '.$graph->email);
    });

    $code = Artisan::call('erasure:run', ['user_ulid' => $ulid, '--execute' => true, '--confirm-digest' => $digest, '--confirm-ulid' => $ulid]);
    $out = Artisan::output();

    expect($code)->toBe(1)
        ->and($out)->toContain('RuntimeException')
        ->and($out)->not->toContain($graph->email)
        ->and(User::withTrashed()->findOrFail($graph->user->id)->isErased())->toBeFalse()
        ->and(ErasureLedger::query()->sole()->state->value)->toBe('incomplete');
});

it('reports an already erased subject, and resumes only a run that stopped after the database stage', function (): void {
    [$graph, $ulid] = erasureCommandFixture();
    Artisan::call('erasure:run', ['user_ulid' => $ulid]);
    $digest = erasureDigestFrom(Artisan::output());
    Artisan::call('erasure:run', ['user_ulid' => $ulid, '--execute' => true, '--confirm-digest' => $digest, '--confirm-ulid' => $ulid]);

    $dry = Artisan::call('erasure:run', ['user_ulid' => $ulid]);
    $dryOut = Artisan::output();
    $nothing = Artisan::call('erasure:run', ['user_ulid' => $ulid, '--execute' => true]);
    $nothingOut = Artisan::output();

    ErasureLedger::query()->sole()->update(['state' => 'db_done', 'completed_at' => null]);
    $resumed = Artisan::call('erasure:run', ['user_ulid' => $ulid, '--execute' => true]);

    expect($dry)->toBe(1)->and($dryOut)->toContain('already erased')
        ->and($nothing)->toBe(1)->and($nothingOut)->toContain('nothing to resume')
        ->and($resumed)->toBe(0)->and(Artisan::output())->toContain('Resumed')
        ->and(ErasureLedger::query()->sole()->state->value)->toBe('completed');
});

it('warns when stored object versions will not be purged', function (): void {
    [, $ulid] = erasureCommandFixture();
    config(['erasure.purge_object_versions' => false]);

    Artisan::call('erasure:run', ['user_ulid' => $ulid]);

    expect(Artisan::output())->toContain('purge_object_versions is OFF');
});

it('is never scheduled and never reachable from a route', function (): void {
    $scheduled = array_map(fn ($event): string => (string) ($event->command ?? $event->description ?? ''), app(Schedule::class)->events());
    $routes = array_map(fn ($route): string => $route->uri().' '.$route->getActionName(), Route::getRoutes()->getRoutes());

    $files = '';
    foreach (['routes/web.php', 'routes/api.php', 'routes/console.php', 'bootstrap/app.php'] as $file) {
        $files .= (string) file_get_contents(base_path($file));
    }

    // The admin compliance page has its own, unrelated `erasure-queue` route (a list, not this engine).
    $mine = '/Modules\\\\Erasure|erasure:(run|reapply)|ErasureRun|ErasureEngine/i';

    expect(array_values(array_filter($scheduled, fn (string $c): bool => preg_match($mine, $c) === 1 || stripos($c, 'erasure') !== false)))->toBe([])
        ->and(array_values(array_filter($routes, fn (string $r): bool => preg_match($mine, $r) === 1)))->toBe([])
        ->and(preg_match($mine, $files))->toBe(0);
});

it('is reached only from inside the Erasure module: no other app code imports the run, the engine or the steps', function (): void {
    $offenders = [];
    $module = app_path('Modules/Erasure').DIRECTORY_SEPARATOR;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php' || str_starts_with($file->getPathname(), $module)) {
            continue;
        }
        if (preg_match('/Modules\\\\Erasure\\\\(Run|Execution|Console|Files)\\\\/', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});

it('leaves the database exactly as it was on a refused execute (no ledger row, no audit row)', function (): void {
    [$graph, $ulid] = erasureCommandFixture();
    DB::table('campaign_assignments')->where('creator_id', $graph->creator->id)->update(['status' => 'payment_held']);
    Artisan::call('erasure:run', ['user_ulid' => $ulid]);
    $out = Artisan::output();
    $before = ErasureSnapshot::tables();

    $code = Artisan::call('erasure:run', ['user_ulid' => $ulid, '--execute' => true, '--confirm-digest' => erasureDigestFrom($out), '--confirm-ulid' => $ulid]);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('payment state')
        ->and(ErasureSnapshot::tables())->toBe($before)
        ->and(ErasureLedger::query()->count())->toBe(0);
});
