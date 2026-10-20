<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-103d rider — `sanctum:prune-expired`, scheduled daily
|--------------------------------------------------------------------------
|
| Sanctum rejects an expired token but leaves the row. An offline reinstall
| (AH-103d) leaves such a row behind for up to the token lifetime, and after
| that nothing deleted it. Pins: the registration (daily, with the explicit
| 24 h argument), and the over-reach negatives (only rows expired MORE than
| 24 h ago go; a live token, a token that expired an hour ago, and a
| token with no expiry stay).
*/

function sanctumTokenRow(User $user, string $name, ?Carbon $expiresAt): int
{
    $token = $user->createToken($name);
    DB::table('personal_access_tokens')
        ->where('id', $token->accessToken->getKey())
        ->update(['expires_at' => $expiresAt]);

    return (int) $token->accessToken->getKey();
}

it('is registered in the scheduler as a daily command with the explicit 24 h window', function (): void {
    Artisan::call('schedule:list');
    // Distinct lines, not raw lines: in a fresh process the FIRST schedule:list lists every
    // `withSchedule` entry twice (the existing push:prune-deliveries scheduler test fails the
    // same way when run alone; recorded in tech-debt). One distinct line is what is pinned.
    $lines = array_values(array_unique(array_filter(
        explode("\n", Artisan::output()),
        fn (string $line): bool => str_contains($line, 'sanctum:prune-expired'),
    )));

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toContain('0 0 * * *')
        ->and($lines[0])->toContain('--hours=24');
});

it('deletes only tokens expired more than 24 hours ago', function (): void {
    $now = Carbon::parse('2026-10-20 12:00:00');
    Carbon::setTestNow($now);

    $user = User::factory()->create();
    $longDead = sanctumTokenRow($user, 'long-dead', $now->copy()->subDays(3));
    $justOver = sanctumTokenRow($user, 'just-over', $now->copy()->subHours(25));
    $recentlyExpired = sanctumTokenRow($user, 'recently-expired', $now->copy()->subHour());
    $live = sanctumTokenRow($user, 'live', $now->copy()->addDays(30));
    $noExpiry = sanctumTokenRow($user, 'no-expiry', null);

    Artisan::call('sanctum:prune-expired', ['--hours' => 24]);

    $left = DB::table('personal_access_tokens')->pluck('id')->map(fn ($id): int => (int) $id)->all();

    expect($left)->not->toContain($longDead)
        ->and($left)->not->toContain($justOver)
        ->and($left)->toContain($recentlyExpired)
        ->and($left)->toContain($live)
        ->and($left)->toContain($noExpiry);

    Carbon::setTestNow();
});

it('touches no table other than personal_access_tokens', function (): void {
    $user = User::factory()->create();
    sanctumTokenRow($user, 'long-dead', Carbon::now()->subDays(5));

    $before = [
        'users' => DB::table('users')->count(),
        'audit_logs' => DB::table('audit_logs')->count(),
    ];

    Artisan::call('sanctum:prune-expired', ['--hours' => 24]);

    expect(DB::table('users')->count())->toBe($before['users'])
        ->and(DB::table('audit_logs')->count())->toBe($before['audit_logs']);
});
