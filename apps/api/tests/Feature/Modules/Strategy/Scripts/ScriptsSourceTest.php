<?php

declare(strict_types=1);

use App\Modules\Strategy\Enums\ShotType;
use App\Modules\Strategy\Services\ScriptHookService;
use App\Modules\Strategy\Services\ScriptService;
use App\Modules\Strategy\StrategyLimits;
use Tests\TestCase;

uses(TestCase::class);

/*
 * PL-3.2a G14 (source checks), the shot-type parity and the limits. No database.
 */

it('G14: every writer of a script table is inside ScriptGuard::transaction (source check); no script writer opens a bare DB::transaction', function (): void {
    $allowed = ['ScriptService.php', 'ScriptHookService.php', 'ScriptArchive.php', 'StrategyWaveMarketGuard.php'];
    $writers = [];
    foreach (glob(app_path('Modules/Strategy/*/*.php')) ?: [] as $file) {
        $src = (string) file_get_contents($file);
        $writes = preg_match('/WaveScript(Hook(Text)?)?::(query\(\)->)?(create|insert|upsert|updateOrCreate)\(|WaveScript(Hook(Text)?)?::query\(\)->[^;]*->(delete|update|increment|decrement)\(|\$(script|hook|text|row|copy)->(save|delete|forceFill)\(|forceFill\([^;]*\)->save\(/', $src) === 1;
        if ($writes && (str_contains($src, 'WaveScript') || str_contains($src, 'ScriptArchive'))) {
            $writers[] = basename($file);
        }
    }
    expect(array_diff($writers, $allowed))->toBe([]);

    // No script writer opens its own transaction: only ScriptGuard does.
    foreach (['ScriptService', 'ScriptHookService', 'ScriptArchive', 'StrategyWaveMarketGuard'] as $name) {
        $src = (string) file_get_contents(app_path("Modules/Strategy/Services/{$name}.php"));
        expect(str_contains($src, 'DB::transaction('))->toBeFalse("{$name} must not open a bare DB::transaction");
    }
    expect(substr_count((string) file_get_contents(app_path('Modules/Strategy/Services/ScriptGuard.php')), 'return DB::transaction('))->toBe(1);

    // Each public writer of the two services opens ScriptGuard::transaction.
    foreach ([ScriptService::class => 'ScriptService', ScriptHookService::class => 'ScriptHookService'] as $class => $name) {
        $src = (string) file_get_contents(app_path("Modules/Strategy/Services/{$name}.php"));
        $publics = count((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC));
        expect(preg_match_all('/^\s*(return )?ScriptGuard::transaction\(/m', $src))->toBe($publics, "{$name}: one guarded transaction per public writer");
    }

    // The slot writer calls the archive helpers only inside its own guarded transaction.
    $slots = (string) file_get_contents(app_path('Modules/Strategy/Services/WaveCreatorService.php'));
    expect(str_contains($slots, 'DB::transaction('))->toBeFalse()->and(preg_match_all('/^\s*(return )?WaveCreatorGuard::transaction\(/m', $slots))->toBe(4);
});

it('G14b: the lock order in ScriptGuard is wave first, then the script row, and an archived script is re-checked after the lock', function (): void {
    $src = (string) file_get_contents(app_path('Modules/Strategy/Services/ScriptGuard.php'));

    expect(str_contains($src, 'StrategyGuard::lockWave($wave)'))->toBeTrue();
    $live = substr($src, (int) strpos($src, 'function lockedScript'));
    expect(strpos($live, 'lockForUpdate()'))->toBeLessThan((int) strpos($live, 'archived_at'));
});

it('G11c: the shot types: ten, in the listed order, and the migration CHECK holds exactly those codes', function (): void {
    expect(ShotType::values())->toBe(['wide', 'medium', 'close_up', 'detail', 'pov', 'over_the_shoulder', 'top_down', 'screen_recording', 'reaction', 'creator_to_camera']);

    $migration = (string) file_get_contents(database_path('migrations/2026_11_24_100003_create_wave_script_hooks_table.php'));
    preg_match("/shot_type IS NULL OR shot_type IN \(([^)]*)\)/", $migration, $m);
    $codes = array_map(static fn (string $c): string => trim($c, " '"), explode(',', $m[1] ?? ''));
    expect($codes)->toBe(ShotType::values());
});

it('G11d: the limits of the plan, and the cap and duration list are what the migration says', function (): void {
    expect(StrategyLimits::SCRIPTS_PER_CREATOR)->toBe(3)->and(StrategyLimits::HOOKS_PER_SCRIPT)->toBe(6)->and(StrategyLimits::HOOKS_ON_CREATE)->toBe(3)
        ->and(StrategyLimits::HOOK_AUDIO)->toBe(300)->and(StrategyLimits::HOOK_OVERLAY)->toBe(100)->and(StrategyLimits::HOOK_VISUAL)->toBe(400)
        ->and(StrategyLimits::SCRIPT_ANGLE)->toBe(300)->and(StrategyLimits::SCRIPT_DURATIONS)->toBe([15, 20, 30, 45, 60])->and(StrategyLimits::SCRIPT_DURATION_DEFAULT)->toBe(30);

    $migration = (string) file_get_contents(database_path('migrations/2026_11_24_100002_create_wave_scripts_table.php'));
    expect($migration)->toContain('number BETWEEN 1 AND 3')->and($migration)->toContain('duration_seconds IN (15, 20, 30, 45, 60)');
});
