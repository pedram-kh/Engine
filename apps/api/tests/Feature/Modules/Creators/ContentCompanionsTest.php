<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Http\Requests\UpdateProfileRequest;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-050 — "Who appears in your content?" (`creators.content_companions`).
 *
 * Pins the four load-bearing properties of the field:
 *
 *   1. Round-trip: populated / [] / null all persist and read back exactly;
 *      [] and null BOTH mean "undisclosed" (D3/Q5 — no normalization).
 *   2. Registry validation: per-item whitelist against
 *      UpdateProfileRequest::CONTENT_COMPANION_KEYS + max:11 (§5.34 negatives).
 *   3. Completeness-inert (D6, §5.34): disclosure is NEVER incentivized by
 *      the score — a populated creator scores identically to an empty one.
 *      Break-revert: add 'content_companions' to PROFILE_OPTIONAL_WEIGHTS
 *      and the inertness case goes red.
 *   4. Admin read-only (D7, §5.34): the admin per-field PATCH rejects the
 *      field (not in EDITABLE_FIELDS) and cannot piggy-back it onto another
 *      field's update. Break-revert: add it to EDITABLE_FIELDS and the
 *      rejection case goes red.
 */
function companionCreator(array $attributes = []): array
{
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->bootstrap()->createOne(
        ['user_id' => $user->id] + $attributes,
    );

    return ['user' => $user, 'creator' => $creator];
}

function companionAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

// ---------------------------------------------------------------------------
// 1. Round-trip — populated / [] / null (D3 / Q5)
// ---------------------------------------------------------------------------

it('persists a populated companion selection and reads it back exactly', function (): void {
    ['user' => $user, 'creator' => $creator] = companionCreator();

    $this->actingAs($user)
        ->patchJson('/api/v1/creators/me/wizard/profile', [
            'content_companions' => ['partner', 'pets_dogs', 'young_kids'],
        ])
        ->assertOk();

    $creator->refresh();
    expect($creator->content_companions)->toBe(['partner', 'pets_dogs', 'young_kids']);

    // The creator-self bootstrap emits the field verbatim.
    $me = $this->actingAs($user)->getJson('/api/v1/creators/me');
    expect($me->json('data.attributes.content_companions'))
        ->toBe(['partner', 'pets_dogs', 'young_kids']);
});

it('persists an EMPTY array as-is — [] means undisclosed, no phantom state (Q5)', function (): void {
    ['user' => $user, 'creator' => $creator] = companionCreator([
        'content_companions' => ['partner'],
    ]);

    // Clearing every chip saves [] — a valid "undisclosed" save (no min:1).
    $this->actingAs($user)
        ->patchJson('/api/v1/creators/me/wizard/profile', [
            'content_companions' => [],
        ])
        ->assertOk();

    $creator->refresh();
    expect($creator->content_companions)->toBe([]);

    // Re-hydration surface: the bootstrap carries [] verbatim (the SPA
    // renders an empty chip group for [] and null identically).
    $me = $this->actingAs($user)->getJson('/api/v1/creators/me');
    expect($me->json('data.attributes.content_companions'))->toBe([]);
});

it('persists an explicit null — null means undisclosed exactly like [] (Q5)', function (): void {
    ['user' => $user, 'creator' => $creator] = companionCreator([
        'content_companions' => ['roommates'],
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/creators/me/wizard/profile', [
            'content_companions' => null,
        ])
        ->assertOk();

    $creator->refresh();
    expect($creator->content_companions)->toBeNull();

    $me = $this->actingAs($user)->getJson('/api/v1/creators/me');
    expect($me->json('data.attributes.content_companions'))->toBeNull();
});

it('preserves the stored value when the PATCH omits the field (sometimes semantics)', function (): void {
    ['user' => $user, 'creator' => $creator] = companionCreator([
        'content_companions' => ['pets_cats'],
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/creators/me/wizard/profile', [
            'display_name' => 'Untouched Companions',
        ])
        ->assertOk();

    $creator->refresh();
    expect($creator->content_companions)->toBe(['pets_cats']);
});

// ---------------------------------------------------------------------------
// 2. Registry validation (§5.34 negatives)
// ---------------------------------------------------------------------------

it('rejects a value outside the 11-key registry', function (): void {
    ['user' => $user] = companionCreator();

    $this->actingAs($user)
        ->patchJson('/api/v1/creators/me/wizard/profile', [
            'content_companions' => ['partner', 'nonsense_key'],
        ])
        ->assertStatus(422);
});

it('rejects a non-array value', function (): void {
    ['user' => $user] = companionCreator();

    $this->actingAs($user)
        ->patchJson('/api/v1/creators/me/wizard/profile', [
            'content_companions' => 'partner',
        ])
        ->assertStatus(422);
});

it('rejects more than 11 items (max:11)', function (): void {
    ['user' => $user] = companionCreator();

    // 12 items can only be reached via a duplicate — max:11 rejects before
    // any dedupe question arises.
    $twelve = [...UpdateProfileRequest::CONTENT_COMPANION_KEYS, 'partner'];

    $this->actingAs($user)
        ->patchJson('/api/v1/creators/me/wizard/profile', [
            'content_companions' => $twelve,
        ])
        ->assertStatus(422);
});

it('accepts the full 11-key registry (the max boundary)', function (): void {
    ['user' => $user, 'creator' => $creator] = companionCreator();

    $this->actingAs($user)
        ->patchJson('/api/v1/creators/me/wizard/profile', [
            'content_companions' => UpdateProfileRequest::CONTENT_COMPANION_KEYS,
        ])
        ->assertOk();

    $creator->refresh();
    expect($creator->content_companions)->toBe(UpdateProfileRequest::CONTENT_COMPANION_KEYS);
});

it('registry catalogue pins the exact 11-key set (catalogue tripwire)', function (): void {
    // The enum-catalogue discipline (CampaignEnumsTest / BlacklistEnumsTest):
    // any add/remove is a deliberate, reviewed change that forces the FE
    // registry copy + the i18n option keys to be revisited.
    expect(UpdateProfileRequest::CONTENT_COMPANION_KEYS)->toBe([
        'partner',
        'baby_toddler',
        'young_kids',
        'teens',
        'adult_children',
        'parents_grandparents',
        'extended_family_friends',
        'pets_dogs',
        'pets_cats',
        'pets_other',
        'roommates',
    ]);
});

// ---------------------------------------------------------------------------
// 3. Completeness-inert (D6, §5.34) — disclosure is never score-incentivized
// ---------------------------------------------------------------------------

it('D6 — a creator with companions populated scores IDENTICALLY to one without (§5.34)', function (): void {
    // Two byte-identical creators except content_companions. Any score
    // difference means the field leaked into the completeness formula —
    // the GDPR-relevant no-disclosure-incentive invariant.
    $base = [
        'display_name' => 'Score Twin',
        'country_code' => 'IT',
        'region' => 'Lazio',
        'primary_language' => 'en',
        'categories' => ['lifestyle'],
        'avatar_path' => 'creators/test/avatar/twin.jpg',
        'bio' => 'A short bio.',
    ];

    $without = CreatorFactory::new()->createOne($base + ['content_companions' => null]);
    $with = CreatorFactory::new()->createOne(
        $base + ['content_companions' => ['partner', 'baby_toddler', 'pets_dogs']],
    );

    $calc = new CompletenessScoreCalculator;

    expect($calc->score($with))->toBe($calc->score($without));
});

it('D6 — content_companions is in neither the floor nor the optional-credit map (source pin)', function (): void {
    // Belt + suspenders on the behavioural twin test above: the field name
    // must not appear in PROFILE_OPTIONAL_WEIGHTS, and isProfileComplete()
    // must not read it. The floor half is already enforced by the FE↔BE
    // floor-mirror parity spec; this pins the backend side directly.
    expect(CompletenessScoreCalculator::PROFILE_OPTIONAL_WEIGHTS)
        ->not->toHaveKey('content_companions');

    $source = (string) file_get_contents(
        (new ReflectionClass(CompletenessScoreCalculator::class))->getFileName() ?: '',
    );
    preg_match(
        '/private function isProfileComplete\(Creator \$creator\): bool\s*\{([\s\S]*?)\n    \}/',
        $source,
        $matches,
    );
    expect($matches[1] ?? '')->not->toContain('content_companions');
});

// ---------------------------------------------------------------------------
// 4. Admin read-only (D7, §5.34)
// ---------------------------------------------------------------------------

it('admin detail READS the field (view-only — the D7 counterpart)', function (): void {
    $admin = companionAdmin();
    $creator = CreatorFactory::new()->createOne([
        'content_companions' => ['teens', 'pets_other'],
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/creators/{$creator->ulid}");

    $response->assertOk();
    expect($response->json('data.attributes.content_companions'))
        ->toBe(['teens', 'pets_other']);
});

it('D7 — admin PATCH with content_companions alone is rejected (not an editable field)', function (): void {
    $admin = companionAdmin();
    $creator = CreatorFactory::new()->createOne(['content_companions' => null]);

    $response = $this->actingAs($admin, 'web_admin')
        ->patchJson("/api/v1/admin/creators/{$creator->ulid}", [
            'content_companions' => ['partner'],
        ]);

    expect($response->status())->toBe(422);

    $creator->refresh();
    expect($creator->content_companions)->toBeNull();
});

it('D7 — content_companions cannot piggy-back on another field\'s admin PATCH', function (): void {
    // The admin endpoint applies exactly ONE editable field per request
    // (editableField()); a companion payload riding alongside display_name
    // must be ignored, never applied.
    $admin = companionAdmin();
    $creator = CreatorFactory::new()->createOne([
        'display_name' => 'Before',
        'content_companions' => null,
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->patchJson("/api/v1/admin/creators/{$creator->ulid}", [
            'display_name' => 'After',
            'content_companions' => ['partner'],
        ]);

    expect($response->status())->toBe(200);

    $creator->refresh();
    expect($creator->display_name)->toBe('After')
        ->and($creator->content_companions)->toBeNull();
});
