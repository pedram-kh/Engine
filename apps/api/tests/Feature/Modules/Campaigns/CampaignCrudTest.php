<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function campaignsUrl(Agency $agency, string $query = ''): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns".($query === '' ? '' : "?{$query}");
}

/** Reload from the DB, narrowed non-null (the row always exists in these tests). */
function reloadCampaign(Campaign $campaign): Campaign
{
    return $campaign->fresh() ?? $campaign;
}

/**
 * @return array<string, mixed>
 */
function validCampaignPayload(Brand $brand, array $overrides = []): array
{
    return array_merge([
        'brand_id' => $brand->ulid,
        'name' => 'Summer launch',
        'description' => 'A summer push.',
        'objective' => 'awareness',
        'budget_minor_units' => 2_500_000,
        'budget_currency' => 'EUR',
        'brief' => [
            'deliverables' => ['1 Reel', '3 Stories'],
            'hashtags' => ['#summer'],
            'usage_rights' => '30 days paid usage',
        ],
        'requires_per_campaign_contract' => true,
    ], $overrides);
}

// ── Create gate (D-10) ──────────────────────────────────────────────────────

it('lets an admin create a campaign — brief lands in the jsonb, money is minor-units integer', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $response = $this->actingAs($admin)->postJson(campaignsUrl($agency), validCampaignPayload($brand));

    $response->assertCreated()
        ->assertJsonPath('data.type', 'campaigns')
        ->assertJsonPath('data.attributes.status', 'draft')
        ->assertJsonPath('data.attributes.budget_minor_units', 2_500_000)
        ->assertJsonPath('data.attributes.brief.deliverables.0', '1 Reel')
        ->assertJsonPath('data.relationships.brand.data.id', $brand->ulid);

    $campaign = Campaign::query()->where('agency_id', $agency->id)->firstOrFail();
    expect($campaign->brief['hashtags'] ?? null)->toBe(['#summer'])
        ->and($campaign->budget_minor_units)->toBe(2_500_000)
        ->and($campaign->budget_minor_units)->toBeInt()
        ->and($campaign->created_by_user_id)->toBe($admin->id);

    expect(AuditLog::query()->where('action', 'campaign.created')->where('subject_id', $campaign->id)->exists())->toBeTrue();
});

it('lets a manager create a campaign', function (): void {
    $agency = Agency::factory()->createOne();
    $manager = User::factory()->agencyManager($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($manager)
        ->postJson(campaignsUrl($agency), validCampaignPayload($brand))
        ->assertCreated();
});

it('forbids a staff member from creating a campaign (403 — the create gate)', function (): void {
    $agency = Agency::factory()->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($staff)
        ->postJson(campaignsUrl($agency), validCampaignPayload($brand))
        ->assertForbidden();

    expect(Campaign::query()->where('agency_id', $agency->id)->count())->toBe(0);
});

it('rejects a campaign whose brand belongs to another agency (422)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $otherBrand = Brand::factory()->createOne(); // different agency

    $this->actingAs($admin)
        ->postJson(campaignsUrl($agency), validCampaignPayload($otherBrand))
        ->assertStatus(422);
});

it('rejects an invalid objective + missing budget (422)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($admin)
        ->postJson(campaignsUrl($agency), validCampaignPayload($brand, [
            'objective' => 'not-a-real-objective',
            'budget_minor_units' => null,
        ]))
        ->assertStatus(422);
});

// ── List (agency-scoped + filters) ───────────────────────────────────────────

it('lists campaigns scoped to the agency, filtered by brand / status / dates', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $brandA = Brand::factory()->forAgency($agency->id)->createOne();
    $brandB = Brand::factory()->forAgency($agency->id)->createOne();

    Campaign::factory()->forAgency($agency->id)->create(['brand_id' => $brandA->id, 'status' => CampaignStatus::Active, 'starts_at' => '2026-07-01']);
    Campaign::factory()->forAgency($agency->id)->create(['brand_id' => $brandB->id, 'status' => CampaignStatus::Draft, 'starts_at' => '2026-09-01']);
    // Another agency's campaign — must never surface.
    Campaign::factory()->createOne();

    // Agency scope: only the two campaigns for this agency.
    $this->actingAs($admin)->getJson(campaignsUrl($agency))
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    // Brand filter.
    $this->actingAs($admin)->getJson(campaignsUrl($agency, "brand={$brandA->ulid}"))
        ->assertJsonPath('meta.total', 1);

    // Status filter.
    $this->actingAs($admin)->getJson(campaignsUrl($agency, 'status=active'))
        ->assertJsonPath('meta.total', 1);

    // Date filter (starts_from).
    $this->actingAs($admin)->getJson(campaignsUrl($agency, 'starts_from=2026-08-01'))
        ->assertJsonPath('meta.total', 1);
});

it('returns an empty page for an unknown status filter', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    Campaign::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($admin)->getJson(campaignsUrl($agency, 'status=bogus'))
        ->assertJsonPath('meta.total', 0);
});

// ── Show / tenancy ────────────────────────────────────────────────────────────

it('shows a campaign to any member', function (): void {
    $agency = Agency::factory()->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($staff)->getJson(campaignsUrl($agency)."/{$campaign->ulid}")
        ->assertOk()
        ->assertJsonPath('data.id', $campaign->ulid);
});

it('404s a campaign from another agency (cross-tenant)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $foreign = Campaign::factory()->createOne();

    $this->actingAs($admin)->getJson(campaignsUrl($agency)."/{$foreign->ulid}")
        ->assertNotFound();
});

it('404s for a non-member (tenancy invisibility)', function (): void {
    $agency = Agency::factory()->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne();
    $outsider = User::factory()->agencyAdmin()->createOne(); // admin of a DIFFERENT agency

    $this->actingAs($outsider)->getJson(campaignsUrl($agency)."/{$campaign->ulid}")
        ->assertNotFound();
});

// ── Update (Settings edit gate) ───────────────────────────────────────────────

it('lets an admin edit campaign settings (status) + logs campaign.updated', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($admin)->patchJson(campaignsUrl($agency)."/{$campaign->ulid}", [
        'status' => 'active',
        'budget_minor_units' => 9_999_999,
    ])->assertOk()->assertJsonPath('data.attributes.status', 'active');

    expect(reloadCampaign($campaign)->status)->toBe(CampaignStatus::Active)
        ->and(reloadCampaign($campaign)->budget_minor_units)->toBe(9_999_999);
    expect(AuditLog::query()->where('action', 'campaign.updated')->where('subject_id', $campaign->id)->exists())->toBeTrue();
});

it('forbids a staff member from editing campaign settings (403)', function (): void {
    $agency = Agency::factory()->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($staff)->patchJson(campaignsUrl($agency)."/{$campaign->ulid}", [
        'status' => 'active',
    ])->assertForbidden();

    expect(reloadCampaign($campaign)->status)->toBe(CampaignStatus::Draft);
});

// ── Creators tab assignment list (read-only, Chunk 1) ─────────────────────────

it('returns an empty assignment page for a fresh campaign (Creators tab)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($admin)->getJson(campaignsUrl($agency)."/{$campaign->ulid}/assignments")
        ->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('data', []);
});
