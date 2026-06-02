<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Models\Contract;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 4 Chunk 4 — backfill data migration (D-c4-4)
|--------------------------------------------------------------------------
|
| On current seed/dev data the backfill is a documented no-op (no creator
| is seeded with click_through_accepted_at). These tests exercise the real
| logic by re-running the migration's up() against a synthetic pre-chunk
| acceptance (a creator with the legacy timestamp but no contracts row).
|
*/

function runBackfillMigration(): void
{
    $migration = require base_path(
        'database/migrations/2026_05_17_100001_backfill_click_through_contracts.php',
    );
    $migration->up();
}

it('backfills a v1.0 contracts row for a pre-chunk acceptance and links the creator', function (): void {
    $user = User::factory()->createOne();
    $acceptedAt = now()->subMonth();
    $creator = CreatorFactory::new()->bootstrap()->createOne([
        'user_id' => $user->id,
        'click_through_accepted_at' => $acceptedAt,
        'signed_master_contract_id' => null,
    ]);

    runBackfillMigration();

    $creator->refresh();
    expect($creator->signed_master_contract_id)->not->toBeNull();

    $contract = Contract::query()->findOrFail($creator->signed_master_contract_id);
    expect($contract->version)->toBe(1);
    expect($contract->signature_provider)->toBe(Contract::PROVIDER_INTERNAL);
    expect($contract->signed_by_creator_id)->toBe($creator->id);
    // Second-granularity compare: the DB column truncates sub-second
    // precision on the round-trip, so the original microsecond Carbon
    // won't be exactly equal.
    expect($contract->signed_at?->toDateTimeString())->toBe($acceptedAt->toDateTimeString());

    $data = $contract->signed_signature_data;
    expect(data_get($data, 'method'))->toBe(Contract::METHOD_CLICK_THROUGH);
    expect(data_get($data, 'version'))->toBe('1.0');
    expect(data_get($data, 'backfilled'))->toBeTrue();
    // No IP/UA is fabricated retroactively.
    expect($data)->not->toHaveKey('ip');
});

it('is idempotent — re-running the backfill creates no duplicate row', function (): void {
    $user = User::factory()->createOne();
    CreatorFactory::new()->bootstrap()->createOne([
        'user_id' => $user->id,
        'click_through_accepted_at' => now()->subMonth(),
        'signed_master_contract_id' => null,
    ]);

    runBackfillMigration();
    runBackfillMigration();

    expect(Contract::query()->count())->toBe(1);
});

it('is a no-op when no pre-chunk acceptance exists (current seed/dev data)', function (): void {
    $user = User::factory()->createOne();
    CreatorFactory::new()->bootstrap()->createOne(['user_id' => $user->id]);

    runBackfillMigration();

    expect(Contract::query()->count())->toBe(0);
});
