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

// ── Objective default (D-1 — the form no longer sends it) ────────────────────

it('defaults a missing objective to ugc on create (D-1)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    // The simplified form omits `objective` entirely.
    $payload = validCampaignPayload($brand);
    unset($payload['objective']);

    $this->actingAs($admin)->postJson(campaignsUrl($agency), $payload)
        ->assertCreated()
        ->assertJsonPath('data.attributes.objective', 'ugc');

    $campaign = Campaign::query()->where('agency_id', $agency->id)->firstOrFail();
    expect($campaign->objective->value)->toBe('ugc');
});

it('honors an explicit objective on create (contract only relaxes, D-1)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $this->actingAs($admin)
        ->postJson(campaignsUrl($agency), validCampaignPayload($brand, ['objective' => 'awareness']))
        ->assertCreated()
        ->assertJsonPath('data.attributes.objective', 'awareness');

    expect(Campaign::query()->where('agency_id', $agency->id)->firstOrFail()->objective->value)->toBe('awareness');
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

it('still lists + shows a campaign whose brand was ARCHIVED (soft-deleted) — the July-Wave-4 incident', function (): void {
    // Archiving a brand is a soft delete (BrandController::destroy). Before the
    // Campaign::brand() withTrashed() fix, the SoftDeletes scope nulled the
    // relation and CampaignResource's assert crashed the ENTIRE campaigns page
    // for one archived brand.
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne(['name' => 'Bolt Food']);
    $campaign = Campaign::factory()->forAgency($agency->id)->create(['brand_id' => $brand->id]);

    $brand->delete(); // soft delete — exactly what the archive endpoint does

    $this->actingAs($admin)->getJson(campaignsUrl($agency))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.relationships.brand.data.name', 'Bolt Food');

    $this->actingAs($admin)->getJson(campaignsUrl($agency)."/{$campaign->ulid}")
        ->assertOk()
        ->assertJsonPath('data.relationships.brand.data.name', 'Bolt Food');
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

it('preserves the stored brief byte-identical when the edit omits it (D-3 — the form no longer sends brief)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    // A RICH brief, including the sub-keys the old form rebuilt-and-wiped
    // (dos/donts/mentions/links/attachments). The simplified form omits `brief`
    // on save, so `sometimes` must leave every key untouched.
    $storedBrief = [
        'deliverables' => ['1 Reel', '3 Stories'],
        'hashtags' => ['#summer', '#launch'],
        'usage_rights' => '30 days paid usage',
        'dos' => ['Tag the brand'],
        'donts' => ['No competitor mentions'],
        'mentions' => ['@brandhandle'],
        'links' => ['https://brand.example/landing'],
        'attachments' => ['brief.pdf'],
    ];
    $campaign = Campaign::factory()->forAgency($agency->id)->create(['brief' => $storedBrief]);

    // Edit only the name — no `brief` key in the payload (the D-3 mechanism).
    $this->actingAs($admin)->patchJson(campaignsUrl($agency)."/{$campaign->ulid}", [
        'name' => 'Renamed campaign',
    ])->assertOk()->assertJsonPath('data.attributes.name', 'Renamed campaign');

    // Byte-identical: the whole blob AND each sub-key the wipe-bug used to drop.
    expect(reloadCampaign($campaign)->brief)->toBe($storedBrief)
        ->and(reloadCampaign($campaign)->brief['dos'] ?? null)->toBe(['Tag the brand'])
        ->and(reloadCampaign($campaign)->brief['donts'] ?? null)->toBe(['No competitor mentions'])
        ->and(reloadCampaign($campaign)->brief['mentions'] ?? null)->toBe(['@brandhandle'])
        ->and(reloadCampaign($campaign)->brief['links'] ?? null)->toBe(['https://brand.example/landing'])
        ->and(reloadCampaign($campaign)->brief['attachments'] ?? null)->toBe(['brief.pdf']);
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
