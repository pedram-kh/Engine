<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyFile;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAttachment;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    // The AgencyFile 409-seam tests below drive the REAL AgencyFileController
    // delete flow, which touches the `media` disk — fake it so a factory-made
    // file's storage_key resolves to a real (if empty) object rather than a
    // hard delete failure on a path nothing ever wrote.
    Storage::fake('media');
});

/**
 * Campaign attachments from the Files library, chunk B (AH-092) — the
 * agency-side surface (S2). Per docs/reviews/campaign-attachments-plan.md
 * §3/§5/§6 S2. Case numbers below refer to the plan's §5 case matrix.
 *
 *   GET    /api/v1/agencies/{agency}/campaigns/{campaign}/attachments
 *   POST   /api/v1/agencies/{agency}/campaigns/{campaign}/attachments
 *   PATCH  /api/v1/agencies/{agency}/campaigns/{campaign}/attachments/{file}
 *   DELETE /api/v1/agencies/{agency}/campaigns/{campaign}/attachments/{file}
 */
function attachmentsUrl(Agency $agency, Campaign $campaign): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/attachments";
}

function attachmentUrl(Agency $agency, Campaign $campaign, AgencyFile $file): string
{
    return attachmentsUrl($agency, $campaign)."/{$file->ulid}";
}

// ── Attach: idempotency, duplicates, independence (cases 8, 9) ────────────

it('attaches a file (201) and is idempotent on replay (200, one row, one audit row)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne();
    $file = AgencyFile::factory()->forAgency($agency->id)->create();

    $first = $this->actingAs($admin)
        ->postJson(attachmentsUrl($agency, $campaign), ['agency_file_id' => $file->ulid])
        ->assertCreated();

    expect($first->json('data.id'))->toBe($file->ulid)
        ->and($first->json('data.attributes.visible_to_creators'))->toBeFalse();

    $second = $this->actingAs($admin)
        ->postJson(attachmentsUrl($agency, $campaign), ['agency_file_id' => $file->ulid])
        ->assertOk();

    expect($second->json('data.id'))->toBe($file->ulid)
        ->and(CampaignAttachment::query()->where('campaign_id', $campaign->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'campaign_attachment.added')->count())->toBe(1);
});

it('a duplicate attach does NOT re-apply visible_to_creators to the existing row', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne();
    $file = AgencyFile::factory()->forAgency($agency->id)->create();

    $this->actingAs($admin)->postJson(attachmentsUrl($agency, $campaign), ['agency_file_id' => $file->ulid])->assertCreated();

    $replay = $this->actingAs($admin)
        ->postJson(attachmentsUrl($agency, $campaign), ['agency_file_id' => $file->ulid, 'visible_to_creators' => true])
        ->assertOk();

    expect($replay->json('data.attributes.visible_to_creators'))->toBeFalse();
});

it('lets the SAME file attach to two campaigns with independent visibility (case 9)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaignA = Campaign::factory()->forAgency($agency->id)->createOne();
    $campaignB = Campaign::factory()->forAgency($agency->id)->createOne();
    $file = AgencyFile::factory()->forAgency($agency->id)->create();

    $this->actingAs($admin)->postJson(attachmentsUrl($agency, $campaignA), ['agency_file_id' => $file->ulid, 'visible_to_creators' => true])->assertCreated();
    $this->actingAs($admin)->postJson(attachmentsUrl($agency, $campaignB), ['agency_file_id' => $file->ulid])->assertCreated();

    expect($this->actingAs($admin)->getJson(attachmentsUrl($agency, $campaignA))->json('data.0.attributes.visible_to_creators'))->toBeTrue()
        ->and($this->actingAs($admin)->getJson(attachmentsUrl($agency, $campaignB))->json('data.0.attributes.visible_to_creators'))->toBeFalse();
});

// ── Toggle + detach, and independence across campaigns (case 10) ──────────

it('toggles visibility with an audit row only on an actual change', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne();
    $file = AgencyFile::factory()->forAgency($agency->id)->create();
    CampaignAttachment::factory()->createOne(['campaign_id' => $campaign->id, 'agency_file_id' => $file->id]);

    $this->actingAs($admin)
        ->patchJson(attachmentUrl($agency, $campaign, $file), ['visible_to_creators' => true])
        ->assertOk()
        ->assertJsonPath('data.attributes.visible_to_creators', true);

    // No-op replay: same value, no second audit row.
    $this->actingAs($admin)->patchJson(attachmentUrl($agency, $campaign, $file), ['visible_to_creators' => true])->assertOk();

    expect(AuditLog::query()->where('action', 'campaign_attachment.visibility_changed')->count())->toBe(1);
});

it('detaches (204) leaving the OTHER campaign\'s attachment of the same file intact (case 10)', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaignA = Campaign::factory()->forAgency($agency->id)->createOne();
    $campaignB = Campaign::factory()->forAgency($agency->id)->createOne();
    $file = AgencyFile::factory()->forAgency($agency->id)->create();
    CampaignAttachment::factory()->createOne(['campaign_id' => $campaignA->id, 'agency_file_id' => $file->id]);
    $kept = CampaignAttachment::factory()->createOne(['campaign_id' => $campaignB->id, 'agency_file_id' => $file->id]);

    $this->actingAs($admin)->deleteJson(attachmentUrl($agency, $campaignA, $file))->assertNoContent();

    expect(CampaignAttachment::query()->where('campaign_id', $campaignA->id)->count())->toBe(0)
        ->and(CampaignAttachment::query()->find($kept->id))->not->toBeNull()
        // The file itself, and the OTHER campaign's row, are untouched.
        ->and(AgencyFile::query()->find($file->id))->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'campaign_attachment.removed']);
});

// ── The 409 seam, now reachable (case 7 — the AH-091 seam closes) ─────────

it('refuses to delete an attached library file with 409 agency_file.attached', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne();
    $file = AgencyFile::factory()->forAgency($agency->id)->create();
    CampaignAttachment::factory()->createOne(['campaign_id' => $campaign->id, 'agency_file_id' => $file->id]);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/agencies/{$agency->ulid}/files/{$file->ulid}")
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'agency_file.attached');

    expect($file->fresh()?->deleted_at)->toBeNull();
});

it('allows deleting a library file once its only attachment is detached', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne();
    $file = AgencyFile::factory()->forAgency($agency->id)->create();
    Storage::disk('media')->put($file->storage_key, 'x'); // the delete flow expects a real object
    CampaignAttachment::factory()->createOne(['campaign_id' => $campaign->id, 'agency_file_id' => $file->id]);

    $this->actingAs($admin)->deleteJson(attachmentUrl($agency, $campaign, $file))->assertNoContent();

    $this->actingAs($admin)
        ->deleteJson("/api/v1/agencies/{$agency->ulid}/files/{$file->ulid}")
        ->assertNoContent();
});

// ── Q1 race's dangling row — filtered, not dereferenced (docs/tech-debt.md) ─

it('omits an attachment whose file was soft-deleted underneath it, rather than 500ing (Q1 race)', function (): void {
    // Reproduces the ONE state the guard normally prevents: an attachment
    // row surviving a concurrent soft-delete of its file. Bypassing at the
    // model layer directly (the plan's own sanctioned technique for this
    // unreachable-through-the-API state) rather than trying to actually win
    // the race.
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne();
    $liveFile = AgencyFile::factory()->forAgency($agency->id)->create();
    $danglingFile = AgencyFile::factory()->forAgency($agency->id)->create();
    CampaignAttachment::factory()->createOne(['campaign_id' => $campaign->id, 'agency_file_id' => $liveFile->id]);
    CampaignAttachment::factory()->createOne(['campaign_id' => $campaign->id, 'agency_file_id' => $danglingFile->id]);

    $danglingFile->delete();

    $response = $this->actingAs($admin)->getJson(attachmentsUrl($agency, $campaign))->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($liveFile->ulid);
});

// ── Cross-agency (case 11) ──────────────────────────────────────────────────

it('404s attaching another agency\'s file — the structural same-agency check', function (): void {
    $agencyA = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agencyA)->createOne();
    $campaign = Campaign::factory()->forAgency($agencyA->id)->createOne();

    $agencyB = Agency::factory()->createOne();
    $foreignFile = AgencyFile::factory()->forAgency($agencyB->id)->create();

    $this->actingAs($admin)
        ->postJson(attachmentsUrl($agencyA, $campaign), ['agency_file_id' => $foreignFile->ulid])
        ->assertNotFound();

    expect(CampaignAttachment::query()->count())->toBe(0);
});

it('404s a cross-agency campaign path on every action, never 403', function (): void {
    $agencyA = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agencyA)->createOne();

    $agencyB = Agency::factory()->createOne();
    $foreignCampaign = Campaign::factory()->forAgency($agencyB->id)->createOne();
    $foreignFile = AgencyFile::factory()->forAgency($agencyB->id)->create();
    $foreignAttachment = CampaignAttachment::factory()->createOne(['campaign_id' => $foreignCampaign->id, 'agency_file_id' => $foreignFile->id]);

    $this->actingAs($admin)->getJson(attachmentsUrl($agencyA, $foreignCampaign))->assertNotFound();
    $this->actingAs($admin)->postJson(attachmentsUrl($agencyA, $foreignCampaign), ['agency_file_id' => $foreignFile->ulid])->assertNotFound();
    $this->actingAs($admin)->patchJson(attachmentUrl($agencyA, $foreignCampaign, $foreignFile), ['visible_to_creators' => true])->assertNotFound();
    $this->actingAs($admin)->deleteJson(attachmentUrl($agencyA, $foreignCampaign, $foreignFile))->assertNotFound();

    expect($foreignAttachment->fresh())->not->toBeNull();
});

// ── Roles (case 12) ─────────────────────────────────────────────────────────

it('lets every role VIEW but only admin/manager attach, toggle, and detach', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $manager = User::factory()->agencyManager($agency)->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne();
    $file = AgencyFile::factory()->forAgency($agency->id)->create();

    foreach ([$admin, $manager, $staff] as $member) {
        $this->actingAs($member)->getJson(attachmentsUrl($agency, $campaign))->assertOk();
    }

    $this->actingAs($staff)
        ->postJson(attachmentsUrl($agency, $campaign), ['agency_file_id' => $file->ulid])
        ->assertForbidden();
    expect(CampaignAttachment::query()->count())->toBe(0);

    $this->actingAs($manager)->postJson(attachmentsUrl($agency, $campaign), ['agency_file_id' => $file->ulid])->assertCreated();

    $this->actingAs($staff)->patchJson(attachmentUrl($agency, $campaign, $file), ['visible_to_creators' => true])->assertForbidden();
    $this->actingAs($staff)->deleteJson(attachmentUrl($agency, $campaign, $file))->assertForbidden();

    expect(CampaignAttachment::query()->first()?->visible_to_creators)->toBeFalse();

    $this->actingAs($admin)->deleteJson(attachmentUrl($agency, $campaign, $file))->assertNoContent();
});

it('refuses an unauthenticated request', function (): void {
    $agency = Agency::factory()->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne();

    $this->getJson(attachmentsUrl($agency, $campaign))->assertUnauthorized();
});

// ── The picker-facing exclusion — the resource never exposes storage_key ──

it('never exposes storage_key on the attachment resource', function (): void {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne();
    $file = AgencyFile::factory()->forAgency($agency->id)->create();
    CampaignAttachment::factory()->createOne(['campaign_id' => $campaign->id, 'agency_file_id' => $file->id]);

    $response = $this->actingAs($admin)->getJson(attachmentsUrl($agency, $campaign))->assertOk();

    expect($response->json('data.0.attributes'))
        ->toHaveKeys(['original_filename', 'mime_type', 'size_bytes', 'visible_to_creators', 'download_url', 'attached_at'])
        ->and($response->json('data.0.attributes'))->not->toHaveKey('storage_key');
});
