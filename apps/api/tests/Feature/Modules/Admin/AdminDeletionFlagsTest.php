<?php

declare(strict_types=1);

use App\Modules\Admin\Support\AdminDeletionFlags;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\PushDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104c C2-4 — the admin read-through: an account that is leaving
|--------------------------------------------------------------------------
|
| Every admin payload that carries a creator says, for the admin only, whether the account
| is erased (`is_erased`: show "Deleted creator", never the placeholder email) and whether a
| deletion request stands (`deletion_pending`: show a chip). Five payloads, three states each.
| The negative half (an agency never sees `deletion_pending`) is
| `AdminDeletionFlagsArchitectureTest`.
*/

function flagsAdmin(): User
{
    return User::factory()->createOne(['type' => UserType::PlatformAdmin, 'two_factor_confirmed_at' => now()]);
}

/** @return array{creator: Creator, user: User} */
function flagsCreator(string $state): array
{
    $creator = CreatorFactory::new()->createOne(['display_name' => 'Flag Subject '.$state]);
    $user = User::query()->findOrFail($creator->user_id);
    $user->forceFill(['name' => 'Flag User '.$state])->saveQuietly();

    match ($state) {
        'ordinary' => null,
        'pending' => $user->forceFill(['deletion_requested_at' => now()->subDay(), 'deletion_scheduled_for' => now()->addDays(13)])->saveQuietly(),
        'erased' => [
            $user->forceFill(['erased_at' => now(), 'deletion_requested_at' => now()->subDays(15), 'deletion_scheduled_for' => now()->subDay()])->saveQuietly(),
            $creator->forceFill(['erased_at' => now(), 'display_name' => null])->saveQuietly(),
        ],
        default => throw new InvalidArgumentException($state),
    };

    return ['creator' => $creator->refresh(), 'user' => $user->refresh()];
}

const FLAG_EXPECTED = [
    'ordinary' => ['is_erased' => false, 'deletion_pending' => false],
    'pending' => ['is_erased' => false, 'deletion_pending' => true],
    'erased' => ['is_erased' => true, 'deletion_pending' => false],
];

/**
 * Each payload, as a closure that fetches it for one creator and returns the flags it carries.
 *
 * @return array<string, Closure(array{creator: Creator, user: User}): array<string, mixed>>
 */
function flagPayloads(): array
{
    return [
        'creator list' => function (array $c): array {
            $rows = actingAs(flagsAdmin(), 'web_admin')->getJson('/api/v1/admin/creators?per_page=100')->assertOk()->collect('data');
            $row = $rows->firstWhere('id', $c['creator']->ulid);

            return ['is_erased' => $row['attributes']['is_erased'] ?? null, 'deletion_pending' => $row['attributes']['deletion_pending'] ?? null];
        },
        'creator detail' => function (array $c): array {
            $json = actingAs(flagsAdmin(), 'web_admin')->getJson('/api/v1/admin/creators/'.$c['creator']->ulid)->assertOk()->json('data.admin_attributes');

            return ['is_erased' => $json['is_erased'] ?? null, 'deletion_pending' => $json['deletion_pending'] ?? null];
        },
        'creator-app usage' => function (array $c): array {
            $rows = actingAs(flagsAdmin(), 'web_admin')->getJson('/api/v1/admin/creator-app/users?per_page=100')->assertOk()->collect('data');
            $row = $rows->firstWhere('id', $c['creator']->ulid)['attributes']['creator'];

            return ['is_erased' => $row['is_erased'] ?? null, 'deletion_pending' => $row['deletion_pending'] ?? null];
        },
        'impersonation user search' => function (array $c): array {
            $rows = actingAs(flagsAdmin(), 'web_admin')->getJson('/api/v1/admin/impersonate/users?q='.urlencode('Flag User'))->assertOk()->collect('data');
            $row = $rows->firstWhere('id', $c['user']->ulid)['attributes'];

            return ['is_erased' => $row['is_erased'] ?? null, 'deletion_pending' => $row['deletion_pending'] ?? null];
        },
        'push deliveries' => function (array $c): array {
            PushDelivery::factory()->create(['user_id' => $c['creator']->user_id]);
            $rows = actingAs(flagsAdmin(), 'web_admin')->getJson('/api/v1/admin/push-deliveries')->assertOk()->json('data');
            $row = $rows[0]['attributes']['creator'];

            return ['is_erased' => $row['is_erased'] ?? null, 'deletion_pending' => $row['deletion_pending'] ?? null];
        },
    ];
}

it('flags an account that is leaving on every admin payload that carries a creator', function (string $payload, string $state): void {
    $subject = flagsCreator($state);

    expect(flagPayloads()[$payload]($subject))->toBe(FLAG_EXPECTED[$state]);
})->with(array_keys(flagPayloads()))->with(array_keys(FLAG_EXPECTED));

it('derives the flags in one place, from the account alone', function (): void {
    foreach (FLAG_EXPECTED as $state => $expected) {
        $subject = flagsCreator($state);

        expect(AdminDeletionFlags::forCreator($subject['creator']))->toBe($expected)
            ->and(AdminDeletionFlags::forUser($subject['user']))->toBe($expected);
    }
});

it('does not flag a request that was cancelled', function (): void {
    $subject = flagsCreator('pending');
    $subject['user']->forceFill(['deletion_requested_at' => null, 'deletion_scheduled_for' => null])->saveQuietly();

    expect(AdminDeletionFlags::forUser($subject['user']->refresh()))->toBe(FLAG_EXPECTED['ordinary']);
});

it('never says a creator is leaving once the creator row is erased, whatever the user row says', function (): void {
    // The two rows are erased in one transaction, so this state should not exist; the creator
    // reading must still not depend on it.
    $subject = flagsCreator('pending');
    $subject['creator']->forceFill(['erased_at' => now(), 'display_name' => null])->saveQuietly();

    expect(AdminDeletionFlags::forCreator($subject['creator']->refresh()))->toBe(FLAG_EXPECTED['erased']);
});
