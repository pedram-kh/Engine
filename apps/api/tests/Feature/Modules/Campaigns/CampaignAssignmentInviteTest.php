<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Models\BrandCreatorBlacklist;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\AssignmentOfferAttachmentUploadService;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorAvailabilityBlock;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 8 Chunk 2 — the agency INVITE front-door + the two-tier gate (D-1/D-2)
 * + the execute ability (D-6) + the re-invite guarded edge (D-7).
 *
 * The load-bearing coverage: blacklist (BOTH scopes) is a HARD 422 block;
 * availability is a SOFT 409-then-acknowledge warn — the two tiers must stay
 * distinct severities. Invite is a CREATE that hand-writes its own audit row.
 */
function inviteUrl(Agency $agency, Campaign $campaign): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments";
}

/**
 * @return array{0: Agency, 1: Brand, 2: Campaign}
 */
function campaignWithBrand(array $campaignOverrides = []): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne(array_merge([
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'budget_currency' => 'EUR',
    ], $campaignOverrides));

    return [$agency, $brand, $campaign];
}

function invitableCreator(): Creator
{
    return CreatorFactory::new()->approved()->createOne();
}

// The repo's `fresh() ?? $self` reload idiom — fresh() is typed ?static, so the
// non-null reload keeps Larastan level-8 happy on property reads (guarded
// because Pest loads every test file into one shared function scope).
if (! function_exists('reloadAssignment')) {
    function reloadAssignment(CampaignAssignment $assignment): CampaignAssignment
    {
        return $assignment->fresh() ?? $assignment;
    }
}

/**
 * @return array<string, mixed>
 */
function invitePayload(Creator $creator, array $overrides = []): array
{
    return array_merge([
        'creator_id' => $creator->ulid,
        'agreed_fee_minor_units' => 500_000,
        'agreed_fee_currency' => 'EUR',
    ], $overrides);
}

// ── Authz (D-6 — invite IS the execute ability: admin + manager + staff) ─────

it('lets an admin invite a discoverable creator — creates an invited assignment + hand-writes assignment.invited', function (): void {
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = invitableCreator();

    $response = $this->actingAs($admin)->postJson(inviteUrl($agency, $campaign), invitePayload($creator));

    $response->assertCreated()
        ->assertJsonPath('data.attributes.status', 'invited')
        ->assertJsonPath('data.attributes.agreed_fee_minor_units', 500_000);

    $assignment = CampaignAssignment::query()->where('campaign_id', $campaign->id)->firstOrFail();
    expect($assignment->status)->toBe(AssignmentStatus::Invited)
        ->and($assignment->creator_id)->toBe($creator->id)
        ->and($assignment->invited_by_user_id)->toBe($admin->id)
        ->and($assignment->invited_at)->not->toBeNull();

    // Correction #1 — the ENDPOINT hand-writes the audit row (a CREATE, not a
    // machine transition).
    expect(AuditLog::query()
        ->where('action', 'assignment.invited')
        ->where('subject_id', $assignment->id)
        ->exists())->toBeTrue();
});

it('persists draft_due_at + posting_due_at on invite (Sprint 12 Chunk 3, D-2 — the deadline set-path)', function (): void {
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = invitableCreator();

    $postingDue = now()->addDays(14)->startOfSecond();
    $draftDue = now()->addDays(7)->startOfSecond();

    $this->actingAs($admin)->postJson(inviteUrl($agency, $campaign), invitePayload($creator, [
        'posting_due_at' => $postingDue->toIso8601String(),
        'draft_due_at' => $draftDue->toIso8601String(),
    ]))->assertCreated();

    $assignment = CampaignAssignment::query()->where('campaign_id', $campaign->id)->firstOrFail();
    expect($assignment->posting_due_at?->equalTo($postingDue))->toBeTrue()
        ->and($assignment->draft_due_at?->equalTo($draftDue))->toBeTrue();
});

it('leaves draft_due_at NULL when not supplied (nullable — draft_overdue inert until set)', function (): void {
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign), invitePayload(invitableCreator()))
        ->assertCreated();

    $assignment = CampaignAssignment::query()->where('campaign_id', $campaign->id)->firstOrFail();
    expect($assignment->draft_due_at)->toBeNull();
});

it('lets a manager invite', function (): void {
    [$agency, , $campaign] = campaignWithBrand();
    $manager = User::factory()->agencyManager($agency)->createOne();

    $this->actingAs($manager)
        ->postJson(inviteUrl($agency, $campaign), invitePayload(invitableCreator()))
        ->assertCreated();
});

it('lets STAFF invite — inviting IS executing a campaign (D-6, the deferred staff-execute question)', function (): void {
    [$agency, , $campaign] = campaignWithBrand();
    $staff = User::factory()->agencyStaff($agency)->createOne();

    $this->actingAs($staff)
        ->postJson(inviteUrl($agency, $campaign), invitePayload(invitableCreator()))
        ->assertCreated();
});

it('404s for a non-member inviting (tenancy invisibility — the tenancy.agency middleware rejects before the policy)', function (): void {
    // Honest deviation: the kickoff names a "non-member 403", but the
    // tenancy.agency middleware returns 404 for non-members (don't leak the
    // agency's existence) BEFORE the invite policy runs — the house convention
    // ("404s for a non-member (tenancy invisibility)" in CampaignCrudTest). All
    // three agency roles CAN invite (D-6), so there is no member-403 case.
    [$agency, , $campaign] = campaignWithBrand();
    $outsider = User::factory()->agencyAdmin()->createOne(); // admin of a DIFFERENT agency

    $this->actingAs($outsider)
        ->postJson(inviteUrl($agency, $campaign), invitePayload(invitableCreator()))
        ->assertNotFound();
});

// ── D-4 — discoverable-only (no roster relation required) ────────────────────

it('404s when inviting a non-discoverable creator (the discovery-gate precedent — no roster relation required, D-4)', function (): void {
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $hidden = CreatorFactory::new()->approved()->notDiscoverable()->createOne();

    $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign), invitePayload($hidden))
        ->assertNotFound();
});

// ── TIER 1 — blacklist HARD BLOCK (422), BOTH scopes (D-1) ───────────────────

it('refuses an AGENCY-WIDE hard-blacklisted creator (422 assignment.blacklisted)', function (): void {
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = invitableCreator();

    AgencyCreatorRelation::factory()->blacklisted()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
    ]);

    $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign), invitePayload($creator))
        ->assertStatus(422)
        ->assertJsonPath('meta.code', 'assignment.blacklisted');

    expect(CampaignAssignment::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

it('refuses a BRAND-SCOPED hard-blacklisted creator for THIS brand (422 — the deferred promise comes due)', function (): void {
    // Break-revert: drop the brand predicate in AssignmentInviteGate and this
    // invite WRONGLY succeeds — the deferred-promise regression.
    [$agency, $brand, $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = invitableCreator();

    BrandCreatorBlacklist::factory()->create([
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
    ]);

    $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign), invitePayload($creator))
        ->assertStatus(422)
        ->assertJsonPath('meta.code', 'assignment.blacklisted');

    expect(CampaignAssignment::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

it('ALLOWS a creator brand-blacklisted for a DIFFERENT brand (the scope is per-brand)', function (): void {
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = invitableCreator();

    // A blacklist on some OTHER brand (even another of this agency's brands).
    $otherBrand = Brand::factory()->forAgency($agency->id)->createOne();
    BrandCreatorBlacklist::factory()->create([
        'brand_id' => $otherBrand->id,
        'creator_id' => $creator->id,
    ]);

    $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign), invitePayload($creator))
        ->assertCreated();
});

it('does NOT block a SOFT blacklist (either scope) — soft never gates', function (): void {
    [$agency, $brand, $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $softAgency = invitableCreator();
    AgencyCreatorRelation::factory()->blacklisted()->create([
        'agency_id' => $agency->id,
        'creator_id' => $softAgency->id,
        'blacklist_type' => 'soft',
    ]);

    $softBrand = invitableCreator();
    BrandCreatorBlacklist::factory()->soft()->create([
        'brand_id' => $brand->id,
        'creator_id' => $softBrand->id,
    ]);

    $this->actingAs($admin)->postJson(inviteUrl($agency, $campaign), invitePayload($softAgency))->assertCreated();
    $this->actingAs($admin)->postJson(inviteUrl($agency, $campaign), invitePayload($softBrand))->assertCreated();
});

// ── TIER 2 — availability SOFT WARN (409 then acknowledge) (D-2) ─────────────

it('returns a 409 conflict signal (NOT a block) for a hard availability conflict; re-submitting with acknowledged succeeds', function (): void {
    [$agency, , $campaign] = campaignWithBrand([
        'posting_window_starts_at' => now()->addDays(5),
        'posting_window_ends_at' => now()->addDays(10),
    ]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = invitableCreator();

    CreatorAvailabilityBlock::factory()->hard()->create([
        'creator_id' => $creator->id,
        'starts_at' => now()->addDays(6),
        'ends_at' => now()->addDays(8),
        'is_recurring' => false,
    ]);

    // First submit (no acknowledge) → 409, NO assignment created.
    $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign), invitePayload($creator))
        ->assertStatus(409)
        ->assertJsonPath('meta.code', 'assignment.availability_conflict')
        ->assertJsonCount(1, 'conflict.conflicts');

    expect(CampaignAssignment::query()->where('campaign_id', $campaign->id)->count())->toBe(0);

    // Re-submit WITH acknowledged → proceeds (the soft-warn protocol).
    $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign), invitePayload($creator, ['acknowledged' => true]))
        ->assertCreated();

    expect(CampaignAssignment::query()->where('campaign_id', $campaign->id)->count())->toBe(1);
});

it('does NOT warn on a SOFT availability block (soft is not a conflict)', function (): void {
    [$agency, , $campaign] = campaignWithBrand([
        'posting_window_starts_at' => now()->addDays(5),
        'posting_window_ends_at' => now()->addDays(10),
    ]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = invitableCreator();

    CreatorAvailabilityBlock::factory()->soft()->create([
        'creator_id' => $creator->id,
        'starts_at' => now()->addDays(6),
        'ends_at' => now()->addDays(8),
        'is_recurring' => false,
    ]);

    $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign), invitePayload($creator))
        ->assertCreated();
});

// ── D-8 — fee validation ─────────────────────────────────────────────────────

it('rejects a fee currency that does not match the campaign currency (422)', function (): void {
    [$agency, , $campaign] = campaignWithBrand(['budget_currency' => 'EUR']);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign), invitePayload(invitableCreator(), ['agreed_fee_currency' => 'USD']))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.source.pointer', '/data/attributes/agreed_fee_currency');
});

it('rejects a non-positive fee (422)', function (): void {
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign), invitePayload(invitableCreator(), ['agreed_fee_minor_units' => 0]))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.source.pointer', '/data/attributes/agreed_fee_minor_units');
});

// ── D-5 — idempotent on unique(campaign_id, creator_id) ──────────────────────

it('is idempotent — inviting the same creator twice yields ONE row + ONE audit (the bulk-loop contract)', function (): void {
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = invitableCreator();

    $this->actingAs($admin)->postJson(inviteUrl($agency, $campaign), invitePayload($creator))->assertCreated();
    $this->actingAs($admin)->postJson(inviteUrl($agency, $campaign), invitePayload($creator))->assertOk();

    expect(CampaignAssignment::query()->where('campaign_id', $campaign->id)->where('creator_id', $creator->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'assignment.invited')->count())->toBe(1);
});

// ── Cross-tenant + auth ──────────────────────────────────────────────────────

it('returns 401 when unauthenticated', function (): void {
    [$agency, , $campaign] = campaignWithBrand();

    $this->postJson(inviteUrl($agency, $campaign), invitePayload(invitableCreator()))
        ->assertUnauthorized();
});

// ── D-7 — re-invite is a GUARDED machine edge (countered → invited) ──────────

it('re-invites a countered assignment (countered → invited) recording a NEW agreed fee + audits assignment.re_invited', function (): void {
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Countered)->create([
        'campaign_id' => $campaign->id,
        'agreed_fee_minor_units' => 500_000,
        'agreed_fee_currency' => 'EUR',
        'countered_fee_minor_units' => 700_000,
        'countered_fee_currency' => 'EUR',
    ]);

    $url = inviteUrl($agency, $campaign)."/{$assignment->ulid}/reinvite";

    $this->actingAs($admin)
        ->postJson($url, ['agreed_fee_minor_units' => 650_000, 'agreed_fee_currency' => 'EUR'])
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'invited');

    $fresh = reloadAssignment($assignment);
    expect($fresh->status)->toBe(AssignmentStatus::Invited)
        ->and($fresh->agreed_fee_minor_units)->toBe(650_000)
        ->and($fresh->countered_fee_minor_units)->toBeNull();

    expect(AuditLog::query()->where('action', 'assignment.re_invited')->where('subject_id', $assignment->id)->exists())->toBeTrue();
});

it('fails closed on an illegal re-invite source (e.g. accepted → invited) — 422, the machine is the sole authority', function (): void {
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Accepted)->create([
        'campaign_id' => $campaign->id,
    ]);

    $url = inviteUrl($agency, $campaign)."/{$assignment->ulid}/reinvite";

    $this->actingAs($admin)
        ->postJson($url, ['agreed_fee_minor_units' => 650_000, 'agreed_fee_currency' => 'EUR'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'assignment.invalid_transition');

    expect(reloadAssignment($assignment)->status)->toBe(AssignmentStatus::Accepted);
});

// ── Invite-offer details (fee_per + offer_description + attachment) ──────────

function offerAttachmentInitUrl(Agency $agency, Campaign $campaign): string
{
    return inviteUrl($agency, $campaign).'/attachments/init';
}

/**
 * A minimal JPEG with an APP1/EXIF segment carrying a unique ASCII marker —
 * the complete-time re-encode must drop it. (Named distinctly from the
 * messaging test's helper: Pest loads all test files into one function scope.)
 */
function jpegWithOfferExifMarker(string $marker): string
{
    $image = imagecreatetruecolor(48, 48);
    assert($image !== false);
    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();
    imagedestroy($image);

    $payload = "Exif\x00\x00".$marker;
    $length = strlen($payload) + 2;
    $app1 = "\xFF\xE1".pack('n', $length).$payload;

    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
}

it('persists fee_per + offer_description on invite and emits them in the resource', function (): void {
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = invitableCreator();

    $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign), invitePayload($creator, [
            'fee_per' => 'per script',
            'offer_description' => 'One 60s UGC video, casual tone, product in frame.',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.attributes.fee_per', 'per script')
        ->assertJsonPath('data.attributes.offer_description', 'One 60s UGC video, casual tone, product in frame.')
        ->assertJsonPath('data.attributes.offer_attachment', null);

    $assignment = CampaignAssignment::query()->where('campaign_id', $campaign->id)->firstOrFail();
    expect($assignment->fee_per)->toBe('per script')
        ->and($assignment->offer_description)->toBe('One 60s UGC video, casual tone, product in frame.');
});

it('initiates an offer-attachment upload scoped under the campaign prefix', function (): void {
    Storage::fake('media');
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $response = $this->actingAs($admin)
        ->postJson(offerAttachmentInitUrl($agency, $campaign), ['mime_type' => 'application/pdf', 'size_bytes' => 1024])
        ->assertOk();

    expect($response->json('data.storage_path'))
        ->toStartWith("agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/offer-attachments/")
        ->toEndWith('.pdf')
        ->and($response->json('data.max_bytes'))->toBe(AssignmentOfferAttachmentUploadService::MAX_BYTES);
});

it('rejects an unsupported offer-attachment mime type on init (422)', function (): void {
    Storage::fake('media');
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson(offerAttachmentInitUrl($agency, $campaign), ['mime_type' => 'application/x-msdownload', 'size_bytes' => 1024])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'assignment.attachment_invalid');
});

it('rejects completing an upload_id that belongs to another campaign prefix (isolation backstop)', function (): void {
    Storage::fake('media');
    [$agency, $brand, $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $otherCampaign = Campaign::factory()->createOne([
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'budget_currency' => 'EUR',
    ]);
    $foreignPath = "agencies/{$agency->ulid}/campaigns/{$otherCampaign->ulid}/offer-attachments/01FOREIGN.pdf";
    Storage::disk('media')->put($foreignPath, 'pdf-bytes');

    $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign).'/attachments/complete', ['upload_id' => $foreignPath])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'assignment.attachment_invalid');
});

it('invites with an offer attachment — persists the metadata and emits the offer_attachment block', function (): void {
    Storage::fake('media');
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = invitableCreator();

    $storagePath = $this->actingAs($admin)
        ->postJson(offerAttachmentInitUrl($agency, $campaign), ['mime_type' => 'application/pdf', 'size_bytes' => 2048])
        ->json('data.storage_path');
    Storage::disk('media')->put($storagePath, 'pdf-bytes');

    $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign).'/attachments/complete', ['upload_id' => $storagePath])
        ->assertOk()
        ->assertJsonPath('data.storage_path', $storagePath);

    $response = $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign), invitePayload($creator, [
            'attachment' => [
                'upload_id' => $storagePath,
                'name' => 'brief.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 2048,
            ],
        ]))
        ->assertCreated();

    // Storage::fake is a local (non-S3) disk, so the signed URL is null in
    // tests — the metadata block is the assertable surface.
    expect($response->json('data.attributes.offer_attachment.name'))->toBe('brief.pdf')
        ->and($response->json('data.attributes.offer_attachment.mime_type'))->toBe('application/pdf')
        ->and($response->json('data.attributes.offer_attachment.size_bytes'))->toBe(2048);

    $assignment = CampaignAssignment::query()->where('campaign_id', $campaign->id)->firstOrFail();
    expect($assignment->offer_attachment_path)->toBe($storagePath)
        ->and($assignment->offer_attachment_name)->toBe('brief.pdf');
});

it('rejects an invite whose attachment upload_id escapes the campaign prefix (422)', function (): void {
    Storage::fake('media');
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = invitableCreator();

    $foreignPath = 'relationship-messages/01SOMETHREAD/01SOMEFILE.pdf';
    Storage::disk('media')->put($foreignPath, 'pdf-bytes');

    $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign), invitePayload($creator, [
            'attachment' => [
                'upload_id' => $foreignPath,
                'name' => 'brief.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 2048,
            ],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'assignment.attachment_invalid');

    expect(CampaignAssignment::query()->where('campaign_id', $campaign->id)->exists())->toBeFalse();
});

// ── Re-offer after decline (declined → invited via the invite front-door) ────

it('re-opens a DECLINED assignment through the invite endpoint — same row flips to invited, new offer overwrites, previously_declined set', function (): void {
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = invitableCreator();

    $declined = CampaignAssignment::factory()->status(AssignmentStatus::Declined)->create([
        'campaign_id' => $campaign->id,
        'creator_id' => $creator->id,
        'agreed_fee_minor_units' => 200_00,
        'agreed_fee_currency' => 'EUR',
        'fee_per' => 'per post',
        'responded_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign), invitePayload($creator, [
            'agreed_fee_minor_units' => 350_00,
            'fee_per' => 'per script',
            'offer_description' => 'Revised brief — one 30s Reel.',
        ]))
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'invited')
        ->assertJsonPath('data.attributes.agreed_fee_minor_units', 350_00)
        ->assertJsonPath('data.attributes.fee_per', 'per script')
        ->assertJsonPath('data.attributes.offer_description', 'Revised brief — one 30s Reel.')
        ->assertJsonPath('data.attributes.previously_declined', true);

    // SAME row (chat-thread continuity): the ULID is unchanged, no second row.
    expect($response->json('data.id'))->toBe($declined->ulid)
        ->and(CampaignAssignment::query()->where('campaign_id', $campaign->id)->where('creator_id', $creator->id)->count())->toBe(1);

    $fresh = reloadAssignment($declined);
    expect($fresh->status)->toBe(AssignmentStatus::Invited)
        ->and($fresh->agreed_fee_minor_units)->toBe(350_00)
        ->and($fresh->fee_per)->toBe('per script')
        ->and($fresh->previously_declined)->toBeTrue()
        ->and($fresh->responded_at)->toBeNull();

    // The re-open is a machine edge — it audits the re_invited verb.
    expect(AuditLog::query()->where('action', 'assignment.re_invited')->where('subject_id', $declined->id)->exists())->toBeTrue();
});

it('leaves a NON-declined existing row untouched (idempotent no-op — no offer overwrite)', function (): void {
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = invitableCreator();

    $accepted = CampaignAssignment::factory()->status(AssignmentStatus::Accepted)->create([
        'campaign_id' => $campaign->id,
        'creator_id' => $creator->id,
        'agreed_fee_minor_units' => 500_00,
        'agreed_fee_currency' => 'EUR',
    ]);

    $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign), invitePayload($creator, [
            'agreed_fee_minor_units' => 999_00,
            'fee_per' => 'per script',
        ]))
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'accepted')
        ->assertJsonPath('data.attributes.agreed_fee_minor_units', 500_00);

    $fresh = reloadAssignment($accepted);
    expect($fresh->status)->toBe(AssignmentStatus::Accepted)
        ->and($fresh->agreed_fee_minor_units)->toBe(500_00)
        ->and($fresh->fee_per)->toBeNull()
        ->and($fresh->previously_declined)->toBeFalse();
});

it('re-opens a declined row with an offer attachment, overwriting the prior offer file', function (): void {
    Storage::fake('media');
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = invitableCreator();

    CampaignAssignment::factory()->status(AssignmentStatus::Declined)->create([
        'campaign_id' => $campaign->id,
        'creator_id' => $creator->id,
        'agreed_fee_minor_units' => 200_00,
        'agreed_fee_currency' => 'EUR',
        'offer_attachment_path' => 'agencies/OLD/campaigns/OLD/offer-attachments/OLD.pdf',
        'offer_attachment_name' => 'old-brief.pdf',
    ]);

    $storagePath = $this->actingAs($admin)
        ->postJson(offerAttachmentInitUrl($agency, $campaign), ['mime_type' => 'application/pdf', 'size_bytes' => 2048])
        ->json('data.storage_path');
    Storage::disk('media')->put($storagePath, 'pdf-bytes');
    $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign).'/attachments/complete', ['upload_id' => $storagePath])
        ->assertOk();

    $response = $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign), invitePayload($creator, [
            'attachment' => [
                'upload_id' => $storagePath,
                'name' => 'revised-brief.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 2048,
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'invited')
        ->assertJsonPath('data.attributes.offer_attachment.name', 'revised-brief.pdf');

    $assignment = CampaignAssignment::query()->where('campaign_id', $campaign->id)->firstOrFail();
    expect($assignment->offer_attachment_path)->toBe($storagePath)
        ->and($assignment->offer_attachment_name)->toBe('revised-brief.pdf');
});

it('EXIF-strips a supported image at complete time (the AH-010a discipline)', function (): void {
    Storage::fake('media');
    [$agency, , $campaign] = campaignWithBrand();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $storagePath = $this->actingAs($admin)
        ->postJson(offerAttachmentInitUrl($agency, $campaign), ['mime_type' => 'image/jpeg', 'size_bytes' => 4096])
        ->json('data.storage_path');

    $marker = 'OFFER-EXIF-MARKER-9F2';
    Storage::disk('media')->put($storagePath, jpegWithOfferExifMarker($marker));

    $this->actingAs($admin)
        ->postJson(inviteUrl($agency, $campaign).'/attachments/complete', ['upload_id' => $storagePath])
        ->assertOk();

    $stored = Storage::disk('media')->get($storagePath);
    expect($stored)->not->toBeNull()
        ->and(str_contains((string) $stored, $marker))->toBeFalse();
});
