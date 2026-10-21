<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Features\AccountDeletionEnabled;
use App\Modules\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104b-del A1 — the request's storage, its flag, its audit verbs
|--------------------------------------------------------------------------
|
| The columns are the whole state of a request: there is no status enum to drift.
| "Pending" is derived (`deletion_requested_at` set and not erased), and a spec pins
| that an erased account is never read as pending.
*/

it('adds two nullable timestamps to users and an index on the scheduled date', function (): void {
    expect(Schema::hasColumns('users', ['deletion_requested_at', 'deletion_scheduled_for']))->toBeTrue();

    $columns = collect(Schema::getColumns('users'))->keyBy('name');

    expect($columns['deletion_requested_at']['nullable'])->toBeTrue()
        ->and($columns['deletion_scheduled_for']['nullable'])->toBeTrue()
        ->and($columns['deletion_requested_at']['default'])->toBeNull()
        ->and($columns['deletion_scheduled_for']['default'])->toBeNull();

    $indexed = collect(Schema::getIndexes('users'))->contains(
        fn (array $index): bool => $index['columns'] === ['deletion_scheduled_for'],
    );

    expect($indexed)->toBeTrue();
});

it('leaves every existing account reading as "no request pending"', function (): void {
    $user = User::factory()->create();

    $fresh = User::query()->findOrFail($user->id);

    expect($fresh->deletion_requested_at)->toBeNull()
        ->and($fresh->deletion_scheduled_for)->toBeNull()
        ->and($fresh->isDeletionPending())->toBeFalse();
});

it('reads a standing request as pending and casts both columns to dates', function (): void {
    $user = User::factory()->create([
        'deletion_requested_at' => now()->subDay(),
        'deletion_scheduled_for' => now()->addDays(13),
    ]);

    $fresh = User::query()->findOrFail($user->id);

    expect($fresh->deletion_requested_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($fresh->deletion_scheduled_for)->toBeInstanceOf(CarbonInterface::class)
        ->and($fresh->isDeletionPending())->toBeTrue();
});

it('never reads an erased account as pending (erasure supersedes the request)', function (): void {
    $user = User::factory()->create([
        'deletion_requested_at' => now()->subDays(15),
        'deletion_scheduled_for' => now()->subDay(),
        'erased_at' => now(),
    ]);

    expect(User::query()->findOrFail($user->id)->isDeletionPending())->toBeFalse();
});

it('has an honest down() and an up() that can run again', function (): void {
    $migration = require database_path('migrations/2026_10_21_110000_add_deletion_request_to_users.php');

    $migration->down();

    expect(Schema::hasColumn('users', 'deletion_requested_at'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'deletion_scheduled_for'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumns('users', ['deletion_requested_at', 'deletion_scheduled_for']))->toBeTrue();
});

it('writes no data: the migration file touches schema only', function (): void {
    $source = (string) file_get_contents(database_path('migrations/2026_10_21_110000_add_deletion_request_to_users.php'));
    $code = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;

    expect($code)->not->toMatch('/DB::|->update\(|->insert\(|->delete\(|\bforceFill\b/');
    expect(DB::table('users')->whereNotNull('deletion_requested_at')->count())->toBe(0);
});

it('registers the flag, default OFF, and round-trips it', function (): void {
    expect(AccountDeletionEnabled::NAME)->toBe('account_deletion_enabled')
        ->and(Feature::defined())->toContain(AccountDeletionEnabled::NAME)
        ->and(Feature::active(AccountDeletionEnabled::NAME))->toBeFalse();

    Feature::activate(AccountDeletionEnabled::NAME);
    expect(Feature::active(AccountDeletionEnabled::NAME))->toBeTrue();

    Feature::deactivate(AccountDeletionEnabled::NAME);
    expect(Feature::active(AccountDeletionEnabled::NAME))->toBeFalse();
});

it('puts the flag in the admin allowlist so it can be flipped from the admin page', function (): void {
    $admin = User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);

    $data = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/feature-flags')->json('data');
    $names = array_column(is_array($data) ? $data : [], 'id');

    expect($names)->toContain(AccountDeletionEnabled::NAME);

    $this->actingAs($admin, 'web_admin')
        ->postJson('/api/v1/admin/feature-flags/'.AccountDeletionEnabled::NAME, ['enabled' => true, 'reason' => 'AH-104b-del walk'])
        ->assertOk();

    expect(Feature::active(AccountDeletionEnabled::NAME))->toBeTrue();
});

it('defines the two audit verbs without a mandatory reason', function (): void {
    expect(AuditAction::AccountDeletionRequested->value)->toBe('account.deletion_requested')
        ->and(AuditAction::AccountDeletionCancelled->value)->toBe('account.deletion_cancelled')
        ->and(AuditAction::AccountDeletionRequested->requiresReason())->toBeFalse()
        ->and(AuditAction::AccountDeletionCancelled->requiresReason())->toBeFalse();
});

it('keeps the support address in config, defaulting to the ruled one', function (): void {
    expect(config('app.support_email'))->toBe('support@catalyst-growth.com');
});

it('has the support address literal in exactly one place in the API (config), and in the env example', function (): void {
    $hits = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'support@catalyst-growth.com')) {
            $hits[] = $file->getPathname();
        }
    }

    foreach (['lang', 'resources'] as $dir) {
        if (! is_dir(base_path($dir))) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_contains((string) file_get_contents($file->getPathname()), 'support@catalyst-growth.com')) {
                $hits[] = $file->getPathname();
            }
        }
    }

    expect($hits)->toBe([], 'the support address must come from config(app.support_email), never be typed again');
    expect((string) file_get_contents(base_path('config/app.php')))->toContain('support@catalyst-growth.com');
});
