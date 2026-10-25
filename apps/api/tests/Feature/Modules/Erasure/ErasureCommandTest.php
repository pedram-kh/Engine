<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Erasure\Execution\AuditScrub;
use App\Modules\Erasure\Models\ErasureLedger;
use App\Modules\Identity\Http\Middleware\EnsureMfaForAdmins;
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
    app()->instance(AuditScrub::class, tap(new FakeAuditScrub, fn (FakeAuditScrub $f) => $f->available = $scrubAvailable));
    $graph = ErasureGraph::seedSubject('a');
    $graph->putFiles();
    // AH-104c: the shell asks the same gate as the button. The fixture is the ordinary case: a creator
    // who asked to be deleted and whose grace ended yesterday. The gate has its own specs.
    $graph->user->forceFill(['deletion_requested_at' => now()->subDays(15), 'deletion_scheduled_for' => now()->subDay()])->saveQuietly();

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
    'a wrong digest' => [['args' => ['--execute' => true, '--confirm-digest' => 'abc', '--confirm-ulid' => 'WHATEVER']], 'does not match'],
    'an unknown subject' => [['subject' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ'], 'No user has that ulid'],
    'an unknown operator' => [['args' => ['--operator' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ']], 'operator ulid'],
]);

it('prints the exception class, not its message, when a step fails (a database message can carry the bound values)', function (): void {
    [$graph, $ulid] = erasureCommandFixture();
    Artisan::call('erasure:run', ['user_ulid' => $ulid]);
    $digest = erasureDigestFrom(Artisan::output());
    // Fails at the closing `account.erased` row (inside the database stage), after the claim was made.
    Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $log) use ($graph): void {
        if ($log->action === AuditAction::AccountErased) {
            throw new RuntimeException('could not write for '.$graph->email);
        }
    });

    $code = Artisan::call('erasure:run', ['user_ulid' => $ulid, '--execute' => true, '--confirm-digest' => $digest, '--confirm-ulid' => $ulid]);
    $out = Artisan::output();

    expect($code)->toBe(1)
        ->and($out)->toContain('RuntimeException')
        ->and($out)->not->toContain($graph->email)
        ->and(User::withTrashed()->findOrFail($graph->user->id)->isErased())->toBeFalse()
        ->and(ErasureLedger::query()->sole()->state->value)->toBe('incomplete')
        // A run that began stays claimed: it is retried, never reopened to the creator (AH-104c).
        ->and(User::withTrashed()->findOrFail($graph->user->id)->isErasureClaimed())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::AccountErasureClaimReleased)->count())->toBe(0);
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

it('is never scheduled; the only route that queues an erasure is the admin Erase click, behind the admin and MFA gates and a throttle', function (): void {
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

    // AH-104c C3: exactly one route starts an erasure, and it is the admin's POST.
    $erase = array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn ($route): bool => str_ends_with($route->getActionName(), 'AdminErasureQueueController@erase'),
    ));
    expect($erase)->toHaveCount(1)
        ->and($erase[0]->methods())->toBe(['POST'])
        ->and($erase[0]->uri())->toBe('api/v1/admin/compliance/erasure-queue/{user}/erase')
        ->and($erase[0]->gatherMiddleware())->toContain('auth:web_admin')
        ->and($erase[0]->gatherMiddleware())->toContain(EnsureMfaForAdmins::class)
        ->and($erase[0]->gatherMiddleware())->toContain('throttle:admin-erasure-erase');
});

it('is reached only from inside the Erasure module: no other app code imports the run, the reapply, the engine, the steps or the ledger mirror', function (): void {
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

/*
|--------------------------------------------------------------------------
| AH-104c C1-6 — the shell asks the same gate as the button
|--------------------------------------------------------------------------
*/

it('prints the gate\'s verdict in the dry-run, with the date when the account is not due, and exits non-zero', function (string $case, string $needle): void {
    [$graph, $ulid] = erasureCommandFixture();
    match ($case) {
        'not due' => $graph->user->forceFill(['deletion_scheduled_for' => now()->addDays(3)])->saveQuietly(),
        'never asked' => $graph->user->forceFill(['deletion_requested_at' => null, 'deletion_scheduled_for' => null])->saveQuietly(),
        'held' => $graph->user->forceFill(['erasure_held_at' => now()])->saveQuietly(),
        default => throw new InvalidArgumentException($case),
    };
    $before = ErasureSnapshot::tables();

    $code = Artisan::call('erasure:run', ['user_ulid' => $ulid]);
    $out = Artisan::output();

    expect($code)->toBe(1)
        ->and($out)->toContain('NOT READY')->toContain($needle)->toContain('An execute would be refused')
        ->and($out)->not->toContain($graph->email)
        ->and(ErasureSnapshot::tables())->toBe($before);
})->with([
    'not due' => ['not due', 'the grace has not ended: the account is not due until '],
    'never asked' => ['never asked', 'has not asked to be deleted'],
    'held' => ['held', 'on hold'],
]);

it('refuses to execute an account that is not due: nothing claimed, no ledger row, no audit row', function (): void {
    [$graph, $ulid] = erasureCommandFixture();
    $graph->user->forceFill(['deletion_scheduled_for' => now()->addDays(3)])->saveQuietly();
    $digest = erasureDigestFrom((function () use ($ulid): string {
        Artisan::call('erasure:run', ['user_ulid' => $ulid]);

        return Artisan::output();
    })());
    $before = ErasureSnapshot::tables();

    $code = Artisan::call('erasure:run', ['user_ulid' => $ulid, '--execute' => true, '--confirm-digest' => $digest, '--confirm-ulid' => $ulid]);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('the grace has not ended')
        ->and(ErasureSnapshot::tables())->toBe($before)
        ->and(ErasureLedger::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::AccountErasureStarted)->count())->toBe(0);
});

it('claims the account when it executes: one audit row with the fixed shell reason, the operator in the ledger', function (): void {
    [$graph, $ulid] = erasureCommandFixture();
    Artisan::call('erasure:run', ['user_ulid' => $ulid]);
    $digest = erasureDigestFrom(Artisan::output());
    $operator = User::factory()->platformAdmin()->createOne();

    $code = Artisan::call('erasure:run', ['user_ulid' => $ulid, '--execute' => true, '--confirm-digest' => $digest, '--confirm-ulid' => $ulid, '--operator' => $operator->ulid]);
    $row = AuditLog::query()->where('action', AuditAction::AccountErasureStarted)->sole();

    expect($code)->toBe(0)
        ->and($row->reason)->toBe('shell erasure: the grace has ended')
        ->and($row->actor_id)->toBe($operator->id)
        ->and($row->metadata)->toBe(['mode' => 'normal'])
        ->and(ErasureLedger::query()->sole()->run_by_user_id)->toBe($operator->id);
});

it('forces an account that never asked: needs --operator and --reason, writes its own audit reason, erases', function (): void {
    [$graph, $ulid] = erasureCommandFixture();
    $graph->user->forceFill(['deletion_requested_at' => null, 'deletion_scheduled_for' => null])->saveQuietly();
    $operator = User::factory()->platformAdmin()->createOne();
    $force = ['--force-not-requested' => true];

    // Without the operator or the reason: refused before anything is read.
    $before = ErasureSnapshot::tables();
    $noOperator = Artisan::call('erasure:run', ['user_ulid' => $ulid] + $force + ['--reason' => 'legal request 4471']);
    $noOperatorOut = Artisan::output();
    $noReason = Artisan::call('erasure:run', ['user_ulid' => $ulid] + $force + ['--operator' => $operator->ulid]);

    expect($noOperator)->toBe(1)->and($noOperatorOut)->toContain('needs --operator and --reason')
        ->and($noReason)->toBe(1)
        ->and(ErasureSnapshot::tables())->toBe($before);

    // The dry-run with force passes the gate and names the way to execute.
    $dry = Artisan::call('erasure:run', ['user_ulid' => $ulid] + $force + ['--operator' => $operator->ulid, '--reason' => 'legal request 4471']);
    $dryOut = Artisan::output();
    $digest = erasureDigestFrom($dryOut);

    expect($dry)->toBe(0)->and($dryOut)->toContain('--force-not-requested')
        ->and(str_contains($dryOut, 'NOT READY'))->toBeFalse();

    $code = Artisan::call('erasure:run', ['user_ulid' => $ulid, '--execute' => true, '--confirm-digest' => $digest, '--confirm-ulid' => $ulid, '--operator' => $operator->ulid, '--reason' => 'legal request 4471'] + $force);
    $row = AuditLog::query()->where('action', AuditAction::AccountErasureStarted)->sole();

    expect($code)->toBe(0)
        ->and($row->reason)->toBe('forced, not requested: legal request 4471')
        ->and($row->metadata)->toBe(['mode' => 'force_not_requested'])
        ->and(User::withTrashed()->findOrFail($graph->user->id)->isErased())->toBeTrue();
});

it('never forces past a hold or a guard', function (string $obstacle): void {
    [$graph, $ulid] = erasureCommandFixture();
    $graph->user->forceFill(['deletion_requested_at' => null, 'deletion_scheduled_for' => null])->saveQuietly();
    $operator = User::factory()->platformAdmin()->createOne();
    if ($obstacle === 'hold') {
        $graph->user->forceFill(['erasure_held_at' => now()])->saveQuietly();
    } else {
        DB::table('campaign_assignments')->where('creator_id', $graph->creator->id)->update(['status' => 'live_verified']);
    }
    $args = ['user_ulid' => $ulid, '--force-not-requested' => true, '--operator' => $operator->ulid, '--reason' => 'legal request 4471'];
    Artisan::call('erasure:run', $args);
    $digest = erasureDigestFrom(Artisan::output());
    $before = ErasureSnapshot::tables();

    $code = Artisan::call('erasure:run', $args + ['--execute' => true, '--confirm-digest' => $digest, '--confirm-ulid' => $ulid]);

    expect($code)->toBe(1)
        ->and(ErasureSnapshot::tables())->toBe($before)
        ->and(ErasureLedger::query()->count())->toBe(0);
})->with(['hold', 'delivered work']);

it('hands the account back when the run is refused before it began, and says so in the audit trail', function (): void {
    [$graph, $ulid] = erasureCommandFixture(scrubAvailable: false);
    $digest = erasureDigestFrom((function () use ($ulid): string {
        Artisan::call('erasure:run', ['user_ulid' => $ulid]);

        return Artisan::output();
    })());

    // The audit scrub is not a gate leg of the claim, so the claim is made and the RUN then refuses.
    $code = Artisan::call('erasure:run', ['user_ulid' => $ulid, '--execute' => true, '--confirm-digest' => $digest, '--confirm-ulid' => $ulid]);
    $user = User::withTrashed()->findOrFail($graph->user->id);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('audit scrub')
        ->and($user->isErasureClaimed())->toBeFalse()
        ->and($user->deletion_requested_at)->not->toBeNull()
        ->and(ErasureLedger::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::AccountErasureStarted)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditAction::AccountErasureClaimReleased)->sole()->metadata)->toBe(['cause' => 'refused']);
});

it('says in the dry-run that an account is already claimed, and does not ask the gate again', function (): void {
    [$graph, $ulid] = erasureCommandFixture();
    $graph->user->forceFill(['erasure_started_at' => now(), 'deletion_scheduled_for' => now()->addDays(3)])->saveQuietly();

    $code = Artisan::call('erasure:run', ['user_ulid' => $ulid]);

    expect($code)->toBe(0)->and(Artisan::output())->toContain('Claimed: yes');
});
