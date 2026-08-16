<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-069 D1 — the per-campaign posting toggle's WRITE PATH.
 *
 * The file exists because of AH-054: `CampaignController::store()` persists
 * through an explicit `create([...])` whitelist rather than `$fillable`, so a
 * field can validate, return 201, and never reach the database. Every assertion
 * below reads the PERSISTED ROW, not the response body — a resource echoing the
 * request would pass a response-only test while the column stayed at its
 * default.
 *
 * It also pins the Q1 TWO-LAYER design, which is the part most likely to be
 * "simplified" by someone who sees two different defaults and assumes one is a
 * bug:
 *
 *   - the DB/model default is `true` — the SAFETY FLOOR. Anything that creates a
 *     campaign without naming the field (a direct API POST, a factory, a
 *     seeder, an import) gets the lifecycle that has always shipped.
 *   - the create FORM pre-sets the switch to `false` — the PRODUCT default — and
 *     always sends the field explicitly. That lives in `CampaignForm.vue` and is
 *     pinned by its own Vitest spec; here we pin the half that makes it safe:
 *     an absent key reads ON.
 */

/**
 * @return array{agency: Agency, admin: User, brand: Brand}
 */
function postingToggleFixture(): array
{
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    return ['agency' => $agency, 'admin' => $admin, 'brand' => $brand];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function postingTogglePayload(Brand $brand, array $overrides = []): array
{
    return [
        'brand_id' => $brand->ulid,
        'name' => 'Autumn hand-off campaign',
        'budget_minor_units' => 500_000,
        'budget_currency' => 'EUR',
        ...$overrides,
    ];
}

// ── The two-layer default (Q1) — §5.34, disjoint and complete ────────────────

it('persists false on create when the form sends it explicitly (the product default)', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = postingToggleFixture();

    $response = $this->actingAs($admin)->postJson(
        "/api/v1/agencies/{$agency->ulid}/campaigns",
        postingTogglePayload($brand, ['creator_posts_content' => false]),
    );

    $response->assertCreated()
        ->assertJsonPath('data.attributes.creator_posts_content', false);

    // The AH-054 assertion: the ROW, not the response.
    $campaign = Campaign::query()->where('ulid', $response->json('data.id'))->sole();
    expect($campaign->creator_posts_content)->toBeFalse();
});

it('persists true on create when the payload names it true', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = postingToggleFixture();

    $response = $this->actingAs($admin)->postJson(
        "/api/v1/agencies/{$agency->ulid}/campaigns",
        postingTogglePayload($brand, ['creator_posts_content' => true]),
    );

    $response->assertCreated()
        ->assertJsonPath('data.attributes.creator_posts_content', true);

    $campaign = Campaign::query()->where('ulid', $response->json('data.id'))->sole();
    expect($campaign->creator_posts_content)->toBeTrue();
});

it('falls back to ON when the key is absent — the Q1 safety floor', function (): void {
    // The case that matters most: a caller that does not know about the toggle
    // must get the lifecycle that has always shipped, never the new one.
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = postingToggleFixture();

    $payload = postingTogglePayload($brand);
    expect(array_key_exists('creator_posts_content', $payload))->toBeFalse();

    $response = $this->actingAs($admin)->postJson("/api/v1/agencies/{$agency->ulid}/campaigns", $payload);

    $response->assertCreated()
        ->assertJsonPath('data.attributes.creator_posts_content', true);

    $campaign = Campaign::query()->where('ulid', $response->json('data.id'))->sole();
    expect($campaign->creator_posts_content)->toBeTrue();
});

it('defaults to ON at the DATABASE layer, not just the controller', function (): void {
    // Bypass the model entirely — a raw insert proves the column's own default
    // rather than `$attributes`. This is the assertion that would catch someone
    // "tidying" the migration to `default(false)` to match the form.
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $creator = User::factory()->agencyAdmin($agency)->createOne();

    $id = DB::table('campaigns')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'created_by_user_id' => $creator->id,
        'name' => 'Raw insert',
        'objective' => 'ugc',
        'status' => 'draft',
        'budget_minor_units' => 1000,
        'budget_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('campaigns')->where('id', $id)->value('creator_posts_content'))->toBeTruthy();
});

it('defaults to ON in the factory, so every pre-existing fixture keeps its lifecycle', function (): void {
    expect(Campaign::factory()->createOne()->creator_posts_content)->toBeTrue()
        ->and(Campaign::factory()->handsOffAtApproval()->createOne()->creator_posts_content)->toBeFalse();
});

// ── The Settings edit ───────────────────────────────────────────────────────

it('persists the toggle through the Settings PATCH, both directions', function (): void {
    ['agency' => $agency, 'admin' => $admin] = postingToggleFixture();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne();

    $url = "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}";

    $this->actingAs($admin)->patchJson($url, ['creator_posts_content' => false])
        ->assertOk()
        ->assertJsonPath('data.attributes.creator_posts_content', false);
    expect($campaign->fresh()?->creator_posts_content)->toBeFalse();

    $this->actingAs($admin)->patchJson($url, ['creator_posts_content' => true])
        ->assertOk()
        ->assertJsonPath('data.attributes.creator_posts_content', true);
    expect($campaign->fresh()?->creator_posts_content)->toBeTrue();
});

it('leaves the toggle untouched when a PATCH does not mention it', function (): void {
    ['agency' => $agency, 'admin' => $admin] = postingToggleFixture();
    $campaign = Campaign::factory()->forAgency($agency->id)->handsOffAtApproval()->createOne();

    $this->actingAs($admin)->patchJson(
        "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}",
        ['name' => 'Renamed, nothing else'],
    )->assertOk();

    expect($campaign->fresh()?->creator_posts_content)->toBeFalse();
});

it('rejects a non-boolean toggle value', function (): void {
    ['agency' => $agency, 'admin' => $admin] = postingToggleFixture();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($admin)->patchJson(
        "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}",
        ['creator_posts_content' => 'nope'],
    )->assertStatus(422);

    expect($campaign->fresh()?->creator_posts_content)->toBeTrue();
});

// ── The audit trail ─────────────────────────────────────────────────────────

it('records the toggle in the campaign audit snapshot on both create and update', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = postingToggleFixture();

    $created = $this->actingAs($admin)->postJson(
        "/api/v1/agencies/{$agency->ulid}/campaigns",
        postingTogglePayload($brand, ['creator_posts_content' => false]),
    )->assertCreated();

    $campaign = Campaign::query()->where('ulid', $created->json('data.id'))->sole();

    $createdLog = AuditLog::query()
        ->where('subject_type', Campaign::class)
        ->where('subject_id', $campaign->id)
        ->latest('id')
        ->firstOrFail();

    expect($createdLog->after['creator_posts_content'] ?? null)->toBeFalse();

    $this->actingAs($admin)->patchJson(
        "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}",
        ['creator_posts_content' => true],
    )->assertOk();

    $updatedLog = AuditLog::query()
        ->where('subject_type', Campaign::class)
        ->where('subject_id', $campaign->id)
        ->latest('id')
        ->firstOrFail();

    // The before/after pair is what answers "who turned posting off, and when".
    expect($updatedLog->before['creator_posts_content'] ?? null)->toBeFalse()
        ->and($updatedLog->after['creator_posts_content'] ?? null)->toBeTrue();
});
