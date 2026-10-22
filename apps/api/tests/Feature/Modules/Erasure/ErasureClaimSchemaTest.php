<?php

declare(strict_types=1);

use App\Modules\Erasure\Registry\Disposition;
use App\Modules\Erasure\Registry\ErasureRegistry;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104c C1-1 — the claim and the hold, stored on the user
|--------------------------------------------------------------------------
*/

const CLAIM_COLUMNS = ['erasure_started_at', 'erasure_held_at', 'erasure_held_by_user_id'];

it('adds three nullable columns to users, with no default and no backfill', function (): void {
    expect(Schema::hasColumns('users', CLAIM_COLUMNS))->toBeTrue();

    $columns = collect(Schema::getColumns('users'))->keyBy('name');

    foreach (CLAIM_COLUMNS as $name) {
        expect($columns[$name]['nullable'])->toBeTrue()->and($columns[$name]['default'])->toBeNull();
    }
});

it('leaves every existing account reading as unclaimed and unheld', function (): void {
    $fresh = User::query()->findOrFail(User::factory()->creator()->createOne()->id);

    expect($fresh->erasure_started_at)->toBeNull()
        ->and($fresh->erasure_held_at)->toBeNull()
        ->and($fresh->erasure_held_by_user_id)->toBeNull()
        ->and($fresh->isErasureClaimed())->toBeFalse()
        ->and($fresh->isErasureHeld())->toBeFalse();
});

it('reads a claim as a claim only until the account is erased', function (): void {
    $claimed = User::factory()->creator()->erasureClaimed()->createOne();
    $erased = User::factory()->creator()->erasureClaimed()->erased()->createOne();

    expect($claimed->refresh()->isErasureClaimed())->toBeTrue()
        ->and($erased->refresh()->isErasureClaimed())->toBeFalse()
        ->and($erased->isErased())->toBeTrue();
});

it('casts the two timestamps to dates and keeps the holder as an admin id', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();
    $held = User::factory()->creator()->erasureHeld($admin->id)->erasureClaimed()->createOne()->refresh();

    expect($held->erasure_held_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($held->erasure_started_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($held->erasure_held_by_user_id)->toBe($admin->id)
        ->and($held->isErasureHeld())->toBeTrue();
});

it('lets a holder be deleted without losing the account that was held (the id goes null)', function (): void {
    $admin = User::factory()->platformAdmin()->createOne();
    $held = User::factory()->creator()->erasureHeld($admin->id)->createOne();

    DB::table('users')->where('id', $admin->id)->delete();

    expect($held->refresh()->erasure_held_by_user_id)->toBeNull()
        ->and($held->erasure_held_at)->not->toBeNull();
});

it('classifies all three columns in the erasure registry as KEEP, and the holder as a declared reference', function (): void {
    $users = ErasureRegistry::tables()['users'];

    foreach (CLAIM_COLUMNS as $name) {
        expect($users->columns)->toHaveKey($name)
            ->and($users->columns[$name]->disposition)->toBe(Disposition::Keep);
    }
    expect($users->refs)->toContain('erasure_held_by_user_id');
});

it('drops and restores the three columns with the migration\'s own down() and up()', function (): void {
    $migration = require database_path('migrations/2026_10_22_100000_add_erasure_claim_and_hold_to_users.php');

    $migration->down();
    expect(Schema::hasColumn('users', 'erasure_started_at'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'erasure_held_at'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'erasure_held_by_user_id'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumns('users', CLAIM_COLUMNS))->toBeTrue();
});
