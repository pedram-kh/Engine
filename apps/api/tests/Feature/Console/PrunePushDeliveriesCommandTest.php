<?php

declare(strict_types=1);

use App\Console\Commands\PrunePushDeliveries;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\PushDelivery;
use App\Modules\Notifications\Models\UserPushToken;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-102b C1.4 — `php artisan push:prune-deliveries`
|--------------------------------------------------------------------------
|
| Retention for the delivery attempt log. §5.34 over-reach negatives: a row
| inside the window survives, a row exactly at the cutoff survives, no other
| table is touched, --dry-run deletes nothing, --limit is honoured, bad input
| deletes nothing, and the schedule registration is pinned.
*/

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-12 12:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @param  array<string, mixed>  $params */
function ppdRun(array $params = []): array
{
    $code = Artisan::call('push:prune-deliveries', $params);

    return [$code, Artisan::output()];
}

function ppdUser(): User
{
    return User::factory()->creator()->createOne();
}

function ppdRow(User $user, Carbon $at): PushDelivery
{
    return PushDelivery::factory()->create(['user_id' => $user->id, 'created_at' => $at]);
}

/** @return list<int> remaining ids, ascending */
function ppdIds(): array
{
    return array_values(PushDelivery::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all());
}

it('deletes rows older than 90 days by default and keeps those inside the window', function (): void {
    $user = ppdUser();
    $old = ppdRow($user, now()->subDays(91));
    $inside = ppdRow($user, now()->subDays(89));
    $fresh = ppdRow($user, now());

    [$code, $output] = ppdRun();

    expect($code)->toBe(0)
        ->and(ppdIds())->toBe([$inside->id, $fresh->id])
        ->and(PushDelivery::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and($output)->toContain('Deleted 1 row(s)')
        ->and($output)->toContain('Remaining: 2 row(s)');
});

it('OVER-REACH: a row exactly at the cutoff survives; one second older goes', function (): void {
    $user = ppdUser();
    $atCutoff = ppdRow($user, now()->subDays(90));
    $justOlder = ppdRow($user, now()->subDays(90)->subSecond());

    ppdRun();

    expect(ppdIds())->toBe([$atCutoff->id])
        ->and(PushDelivery::query()->whereKey($justOlder->id)->exists())->toBeFalse();
});

it('OVER-REACH: --days is honoured (a 20-day-old row goes at --days=10 and stays at --days=30)', function (): void {
    $user = ppdUser();
    $row = ppdRow($user, now()->subDays(20));

    ppdRun(['--days' => 30]);
    expect(ppdIds())->toBe([$row->id]);

    ppdRun(['--days' => 10]);
    expect(ppdIds())->toBe([]);
});

it('OVER-REACH: touches no other table (every other table\'s row count is identical before and after)', function (): void {
    $user = ppdUser();
    UserPushToken::factory()->create(['user_id' => $user->id]);
    ppdRow($user, now()->subDays(200));
    ppdRow($user, now()->subDays(1));

    $snapshot = function (): array {
        $counts = [];
        foreach (array_column(Schema::getTables(), 'name') as $table) {
            if ($table === 'push_deliveries') {
                continue;
            }
            $counts[$table] = DB::table($table)->count();
        }
        ksort($counts);

        return $counts;
    };
    $before = $snapshot();
    expect($before['users'])->toBeGreaterThan(0)
        ->and($before['user_push_tokens'])->toBe(1)
        ->and($before['personal_access_tokens'])->toBeGreaterThan(0);

    ppdRun();

    expect($snapshot())->toBe($before)
        ->and(PushDelivery::query()->count())->toBe(1);
});

it('--dry-run deletes nothing and reports what would go', function (): void {
    $user = ppdUser();
    ppdRow($user, now()->subDays(100));
    ppdRow($user, now()->subDays(120));
    ppdRow($user, now()->subDays(5));

    [$code, $output] = ppdRun(['--dry-run' => true]);

    expect($code)->toBe(0)
        ->and(PushDelivery::query()->count())->toBe(3)
        ->and($output)->toContain('Dry run: would delete 2 row(s)');
});

it('--dry-run reports at most --limit', function (): void {
    $user = ppdUser();
    foreach ([100, 110, 120] as $days) {
        ppdRow($user, now()->subDays($days));
    }

    [, $output] = ppdRun(['--dry-run' => true, '--limit' => 2]);

    expect($output)->toContain('would delete 2 row(s)')
        ->and(PushDelivery::query()->count())->toBe(3);
});

it('--limit is honoured and drains oldest-first; the next run finishes the backlog', function (): void {
    $user = ppdUser();
    $oldest = ppdRow($user, now()->subDays(500));
    $second = ppdRow($user, now()->subDays(400));
    $third = ppdRow($user, now()->subDays(300));
    $fourth = ppdRow($user, now()->subDays(200));
    $fresh = ppdRow($user, now()->subDays(1));

    [, $output] = ppdRun(['--limit' => 3]);
    expect($output)->toContain('Deleted 3 row(s)')
        ->and(ppdIds())->toBe([$fourth->id, $fresh->id])
        ->and(PushDelivery::query()->whereIn('id', [$oldest->id, $second->id, $third->id])->count())->toBe(0);

    ppdRun(['--limit' => 3]);
    expect(ppdIds())->toBe([$fresh->id]);
});

it('is idempotent: a second run immediately after deletes nothing', function (): void {
    $user = ppdUser();
    ppdRow($user, now()->subDays(100));
    $keep = ppdRow($user, now());

    ppdRun();
    [$code, $output] = ppdRun();

    expect($code)->toBe(0)
        ->and($output)->toContain('Deleted 0 row(s)')
        ->and(ppdIds())->toBe([$keep->id]);
});

it('reports an empty table plainly (oldest: none)', function (): void {
    [$code, $output] = ppdRun();

    expect($code)->toBe(0)
        ->and($output)->toContain('Deleted 0 row(s)')
        ->and($output)->toContain('Remaining: 0 row(s); oldest: none.');
});

it('chunks its deletes: 5,200 old rows go in exactly two DELETE statements of at most 5,000, and the fresh row survives', function (): void {
    $user = ppdUser();
    $old = now()->subDays(200)->format('Y-m-d H:i:s');
    $rows = [];
    for ($i = 0; $i < 5200; $i++) {
        $rows[] = ['user_id' => $user->id, 'platform' => 'android', 'notification_type' => 'assignment.invited', 'outcome' => 'delivered', 'attempt' => 1, 'created_at' => $old];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('push_deliveries')->insert($chunk);
    }
    $fresh = ppdRow($user, now());

    $deletes = [];
    DB::listen(function ($query) use (&$deletes): void {
        if (str_starts_with(strtolower($query->sql), 'delete from "push_deliveries"')) {
            $deletes[] = $query->sql;
        }
    });

    [, $output] = ppdRun();

    expect($output)->toContain('Deleted 5200 row(s)')
        ->and($deletes)->toHaveCount(2)
        ->and(ppdIds())->toBe([$fresh->id]);
});

it('is LOUD on bad input and deletes nothing', function (array $params): void {
    $user = ppdUser();
    ppdRow($user, now()->subDays(1000));

    [$code, $output] = ppdRun($params);

    expect($code)->toBe(PrunePushDeliveries::INVALID)
        ->and($output)->toContain('nothing was deleted')
        ->and(PushDelivery::query()->count())->toBe(1);
})->with([
    'days below the floor' => [['--days' => 6]],
    'days zero' => [['--days' => 0]],
    'days negative' => [['--days' => -5]],
    'days not a number' => [['--days' => 'abc']],
    'days fractional' => [['--days' => '90.5']],
    'days above the ceiling' => [['--days' => 3651]],
    'limit zero' => [['--limit' => 0]],
    'limit negative' => [['--limit' => -1]],
    'limit not a number' => [['--limit' => 'x']],
]);

it('accepts the floor (--days=7) and the ceiling (--days=3650)', function (int $days): void {
    [$code] = ppdRun(['--days' => $days]);

    expect($code)->toBe(0);
})->with([7, 3650]);

it('is registered in the scheduler as a daily command', function (): void {
    // schedule:list bootstraps the console kernel's schedule (the withSchedule callback in bootstrap/app.php).
    Artisan::call('schedule:list');
    $lines = array_values(array_filter(
        explode("\n", Artisan::output()),
        fn (string $line): bool => str_contains($line, 'push:prune-deliveries'),
    ));

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toContain('0 0 * * *');
});
