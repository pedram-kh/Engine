<?php

declare(strict_types=1);

use App\Modules\Creators\Models\Creator;
use App\Modules\Erasure\Enums\ErasureLedgerState;
use App\Modules\Erasure\Models\ErasureLedger;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104a Stage 1 — the markers and the ledger (schema half)
|--------------------------------------------------------------------------
|
| `users.erased_at` and `creators.erased_at` are additive-nullable: NULL is
| what makes every account that exists today read as "not erased", with no
| backfill and no data write at deploy. The ledger records THAT an erasure
| ran for a pseudonymous ulid and carries no personal data, which is pinned
| by a column allowlist so a PII column cannot be added to it quietly.
*/

it('adds a nullable erased_at to users and creators', function (): void {
    foreach (['users', 'creators'] as $table) {
        expect(Schema::hasColumn($table, 'erased_at'))->toBeTrue("{$table}.erased_at should exist");

        $column = collect(Schema::getColumns($table))->firstWhere('name', 'erased_at');
        expect($column['nullable'])->toBeTrue("{$table}.erased_at must be nullable")
            ->and($column['default'])->toBeNull("{$table}.erased_at must have no default");
    }
});

it('leaves erased_at NULL, in SQL, for accounts created the normal way (no backfill)', function (): void {
    $creator = Creator::factory()->create();

    expect(DB::table('users')->where('id', $creator->user_id)->whereNull('erased_at')->exists())->toBeTrue()
        ->and(DB::table('creators')->where('id', $creator->id)->whereNull('erased_at')->exists())->toBeTrue()
        ->and(User::query()->findOrFail($creator->user_id)->isErased())->toBeFalse();
});

it('reports an account as erased only once erased_at is set', function (): void {
    $user = User::factory()->erased()->create();

    $creator = Creator::factory()->erased()->create();

    expect(User::query()->findOrFail($user->id)->isErased())->toBeTrue()
        ->and(Creator::query()->findOrFail($creator->id)->erased_at)->not->toBeNull();
});

it('pins the ledger columns to an allowlist with no personal data in it', function (): void {
    $columns = collect(Schema::getColumns('erasure_ledger'))->pluck('name')->sort()->values()->all();

    // Adding a column here is a decision: it must not be an email, a name, an ip
    // or free text. If you are here because this failed, read the migration header.
    expect($columns)->toBe([
        'completed_at',
        'counts',
        'created_at',
        'digest',
        'id',
        'run_by_user_id',
        'started_at',
        'state',
        'ulid',
        'updated_at',
        'user_ulid',
    ]);
});

it('keys the ledger by the pseudonymous ulid and not by a foreign key to the subject', function (): void {
    $foreignKeys = collect(Schema::getForeignKeys('erasure_ledger'));

    expect($foreignKeys->pluck('columns')->flatten()->all())->toBe(['run_by_user_id']);
});

it('stores a ledger row with counts only and reads its state back as the enum', function (): void {
    $ledger = ErasureLedger::factory()->create([
        'counts' => ['users' => 1, 'sessions' => 3],
        'state' => ErasureLedgerState::DbDone,
    ]);

    $fresh = ErasureLedger::query()->findOrFail($ledger->id);

    expect($fresh->state)->toBe(ErasureLedgerState::DbDone)
        ->and($fresh->counts)->toBe(['users' => 1, 'sessions' => 3])
        ->and($fresh->ulid)->toHaveLength(26)
        ->and($fresh->user_ulid)->toHaveLength(26);
});
