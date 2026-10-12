<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\PushDelivery;
use App\Modules\Notifications\Push\PushOutcome;
use App\Modules\Notifications\Push\PushPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-102b C1.1 — push_deliveries, PushDelivery, PushDeliveryFactory
|--------------------------------------------------------------------------
|
| The table is the attempt log the admin Push deliveries page reads. These
| tests pin its SHAPE (columns, the three indexes, the single FK), the
| deliberate absence of a FK on `user_push_token_id` / `notification_ulid`,
| the user cascade, the never-store rule, and a reversible down().
*/

/** @return array<string, array<string, mixed>> columns keyed by name */
function pushDeliveryColumns(): array
{
    $columns = [];
    foreach (Schema::getColumns('push_deliveries') as $column) {
        $columns[(string) $column['name']] = $column;
    }

    return $columns;
}

it('has exactly the planned columns, in order, with the planned nullability', function (): void {
    $columns = pushDeliveryColumns();

    expect(array_keys($columns))->toBe([
        'id', 'user_id', 'user_push_token_id', 'platform', 'notification_type', 'notification_ulid',
        'outcome', 'attempt', 'final', 'code', 'http_status', 'detail', 'created_at',
    ]);

    $nullable = array_keys(array_filter($columns, fn (array $c): bool => (bool) $c['nullable']));
    expect($nullable)->toBe(['user_push_token_id', 'notification_ulid', 'code', 'http_status', 'detail']);
});

it('is append-only and can never hold a token, message body, IP or user-agent (no such column exists)', function (): void {
    $names = array_keys(pushDeliveryColumns());

    expect($names)->not->toContain('updated_at');

    foreach (['token', 'fcm_token', 'device_token', 'title', 'body', 'message', 'data', 'payload', 'ip', 'ip_address', 'user_agent'] as $forbidden) {
        expect($names)->not->toContain($forbidden);
    }
});

it('has exactly three secondary indexes: (user_id, created_at), (outcome, created_at), (created_at)', function (): void {
    $indexes = [];
    foreach (Schema::getIndexes('push_deliveries') as $index) {
        if ($index['primary']) {
            continue;
        }
        $indexes[(string) $index['name']] = $index['columns'];
    }
    ksort($indexes);

    expect($indexes)->toBe([
        'idx_push_deliveries_created' => ['created_at'],
        'idx_push_deliveries_outcome_created' => ['outcome', 'created_at'],
        'idx_push_deliveries_user_created' => ['user_id', 'created_at'],
    ]);
});

it('has ONE foreign key — user_id → users ON DELETE CASCADE — and none on user_push_token_id or notification_ulid', function (): void {
    $foreignKeys = Schema::getForeignKeys('push_deliveries');

    expect($foreignKeys)->toHaveCount(1);
    expect($foreignKeys[0]['columns'])->toBe(['user_id'])
        ->and($foreignKeys[0]['foreign_table'])->toBe('users')
        ->and($foreignKeys[0]['foreign_columns'])->toBe(['id'])
        ->and(strtolower((string) $foreignKeys[0]['on_delete']))->toBe('cascade');
});

it('keeps a delivery row whose device row no longer exists (no FK on user_push_token_id) and whose notification is gone (no FK on notification_ulid)', function (): void {
    $row = PushDelivery::factory()->create([
        'user_push_token_id' => 987654,
        'notification_ulid' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
    ]);

    expect(PushDelivery::query()->whereKey($row->id)->exists())->toBeTrue()
        ->and($row->fresh()?->user_push_token_id)->toBe(987654);
});

it('cascades: hard-deleting the user removes their delivery history and nobody else\'s', function (): void {
    $gone = User::factory()->creator()->createOne();
    $stays = User::factory()->creator()->createOne();
    PushDelivery::factory()->count(2)->create(['user_id' => $gone->id]);
    $kept = PushDelivery::factory()->create(['user_id' => $stays->id]);

    DB::table('users')->where('id', $gone->id)->delete();

    expect(PushDelivery::query()->where('user_id', $gone->id)->count())->toBe(0)
        ->and(PushDelivery::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('factory builds a valid row; casts round-trip; created_at defaults at the database when omitted', function (): void {
    $row = PushDelivery::factory()->outcome(PushOutcome::TokenInvalid, 'UNREGISTERED', 404)->final()->create([
        'platform' => PushPlatform::Ios,
        'attempt' => 2,
    ]);
    $fresh = $row->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh?->outcome)->toBe(PushOutcome::TokenInvalid)
        ->and($fresh?->platform)->toBe(PushPlatform::Ios)
        ->and($fresh?->final)->toBeTrue()
        ->and($fresh?->attempt)->toBe(2)
        ->and($fresh?->http_status)->toBe(404)
        ->and($fresh?->code)->toBe('UNREGISTERED');

    $user = User::factory()->creator()->createOne();
    DB::table('push_deliveries')->insert([
        'user_id' => $user->id,
        'platform' => 'android',
        'notification_type' => 'assignment.invited',
        'outcome' => 'delivered',
        'attempt' => 1,
    ]);
    $defaulted = PushDelivery::query()->where('user_id', $user->id)->firstOrFail();

    expect($defaulted->final)->toBeFalse()
        ->and($defaulted->created_at)->not->toBeNull();
});

it('serialises without any token-like key', function (): void {
    $keys = array_keys(PushDelivery::factory()->create()->toArray());

    expect($keys)->not->toContain('token')
        ->and($keys)->not->toContain('updated_at');
});

it('down() drops the table and up() recreates it', function (): void {
    $migration = require base_path('database/migrations/2026_10_12_100000_create_push_deliveries_table.php');

    $migration->down();
    expect(Schema::hasTable('push_deliveries'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('push_deliveries'))->toBeTrue();
});
