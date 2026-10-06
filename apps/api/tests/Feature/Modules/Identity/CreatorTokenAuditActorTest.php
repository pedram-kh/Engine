<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-099 S5 — the audit-actor proof (inventory M2.4: "should hold — verify,
| don't assume")
|--------------------------------------------------------------------------
|
| AuditLogger resolves an omitted actor from the DEFAULT guard
| (`auth()->guard()->user()`), and `auth:web,creator_app` switches the default
| guard to whichever guard authenticated the request. This proves it with the
| one path that actually exercises the implicit read: the `Audited` model
| trait logs `creator.updated` with NO explicit actor. (The wizard's own
| `creator.wizard.profile_completed` row passes `actor:` explicitly, so on its
| own it would prove nothing.)
|
| Control: the factory-created `creator.created` row — written with no auth
| context at all — is `system`/null, so an unresolved actor is observable and
| this assertion can genuinely fail.
|
*/

const AA_PROFILE = [
    'display_name' => 'Bearer Name',
    'country_code' => 'IT',
    'region' => 'Lazio',
    'date_of_birth' => '1994-03-17',
    'primary_language' => 'en',
    'categories' => ['lifestyle'],
];

function aaCreator(): User
{
    $user = User::factory()->creator()->createOne();
    CreatorFactory::new()->bootstrap()->createOne(['user_id' => $user->id, 'avatar_path' => 'creators/seed/avatar/x.jpg']);

    return $user;
}

function aaUpdatedRow(User $user): AuditLog
{
    $creator = Creator::query()->where('user_id', $user->id)->firstOrFail();

    return AuditLog::query()
        ->where('action', AuditAction::CreatorUpdated->value)
        ->where('subject_id', $creator->id)
        ->latest('id')
        ->firstOrFail();
}

it('control: an audited write with no auth context records actor_type=system, actor_id=null', function (): void {
    $user = aaCreator();
    $creator = Creator::query()->where('user_id', $user->id)->firstOrFail();

    $row = AuditLog::query()
        ->where('action', AuditAction::CreatorCreated->value)
        ->where('subject_id', $creator->id)
        ->firstOrFail();

    expect($row->actor_type)->toBe('system')->and($row->actor_id)->toBeNull();
});

it('under BEARER auth the implicit-actor audit row records the creator as the actor', function (): void {
    $user = aaCreator();
    $plain = $user->createToken('creator-app:ios', ['*'])->plainTextToken;

    $this->withHeaders(['Origin' => 'capacitor://localhost', 'Authorization' => 'Bearer '.$plain])
        ->patchJson('/api/v1/creators/me/wizard/profile', AA_PROFILE)
        ->assertOk();

    $row = aaUpdatedRow($user);

    expect($row->actor_type)->toBe('user')
        ->and($row->actor_id)->toBe($user->id);
});

it('cookie parity: the identical request under a COOKIE session records the identical actor', function (): void {
    $user = aaCreator();

    $this->actingAs($user, 'web')
        ->patchJson('/api/v1/creators/me/wizard/profile', AA_PROFILE)
        ->assertOk();

    $row = aaUpdatedRow($user);

    expect($row->actor_type)->toBe('user')
        ->and($row->actor_id)->toBe($user->id);
});
