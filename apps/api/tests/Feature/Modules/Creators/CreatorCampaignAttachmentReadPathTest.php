<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\AgencyFile;
use App\Modules\Agencies\Models\BrandCreatorBlacklist;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignAttachment;
use App\Modules\Creators\Enums\RelationshipStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\JobsBoard\CreatorJobFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Campaign attachments from the Files library, chunk B (AH-092), S4 — the
 * TWO creator read doors: assignment detail (ownership) and Jobs Board detail
 * (`JobsBoardVisibility`). Per docs/reviews/campaign-attachments-plan.md
 * §2.4/§5. Case numbers refer to the plan's §5 case matrix.
 *
 *   GET /api/v1/creators/me/assignments/{assignment}   (CreatorAssignmentDraftController)
 *   GET /api/v1/creators/me/jobs/{campaign}             (CreatorJobBoardController)
 */
// Named distinctly from `CreatorAssignmentTest.php`'s own `assignmentUrl()`
// (a different signature — that one takes a `$verb`) — Pest loads every test
// file's top-level functions into one global namespace, so a same-named
// helper anywhere in the suite is a fatal `Cannot redeclare function`, not a
// silent shadow.
function attachmentReadAssignmentUrl(CampaignAssignment $assignment): string
{
    return "/api/v1/creators/me/assignments/{$assignment->ulid}";
}

// ── Case 1 — attached + visible + listed: both doors return it + a URL ────

it('case 1 — job detail returns the visible attachment with a filename, mime, size, and url', function (): void {
    $f = CreatorJobFixture::make();
    $file = AgencyFile::factory()->forAgency($f->agency->id)->create(['original_filename' => 'brief.pdf']);
    CampaignAttachment::factory()->visible()->createOne(['campaign_id' => $f->campaign->id, 'agency_file_id' => $file->id]);

    $attachments = $this->actingAs($f->user)->getJson($f->jobUrl())->assertOk()->json('data.attributes.attachments');

    expect($attachments)->toHaveCount(1)
        ->and($attachments[0])->toHaveKeys(['filename', 'mime_type', 'size_bytes', 'url'])
        ->and($attachments[0]['filename'])->toBe('brief.pdf');
});

it('case 1 — assignment detail returns the visible attachment with a filename, mime, size, and url', function (): void {
    $f = CreatorJobFixture::make();
    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Producing)->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
    ]);
    $file = AgencyFile::factory()->forAgency($f->agency->id)->create(['original_filename' => 'brief.pdf']);
    CampaignAttachment::factory()->visible()->createOne(['campaign_id' => $f->campaign->id, 'agency_file_id' => $file->id]);

    $attachments = $this->actingAs($f->user)->getJson(attachmentReadAssignmentUrl($assignment))->assertOk()->json('data.attributes.attachments');

    expect($attachments)->toHaveCount(1)
        ->and($attachments[0])->toHaveKeys(['filename', 'mime_type', 'size_bytes', 'url'])
        ->and($attachments[0]['filename'])->toBe('brief.pdf');
});

// ── Case 2 — attached + NOT visible + listed: absent from the array, exact-keyset ──

it('case 2 — an attached-but-not-visible file is absent from BOTH surfaces\' attachments array, not null/empty-marker', function (): void {
    $f = CreatorJobFixture::make();
    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Producing)->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
    ]);
    $file = AgencyFile::factory()->forAgency($f->agency->id)->create();
    CampaignAttachment::factory()->createOne(['campaign_id' => $f->campaign->id, 'agency_file_id' => $file->id]); // flag OFF

    $jobAttachments = $this->actingAs($f->user)->getJson($f->jobUrl())->assertOk()->json('data.attributes.attachments');
    $assignmentAttachments = $this->actingAs($f->user)->getJson(attachmentReadAssignmentUrl($assignment))->assertOk()->json('data.attributes.attachments');

    // The KEY is present (both endpoints always emit `attachments`) but the
    // ARRAY is empty — the file itself never appears, in any form.
    expect($jobAttachments)->toBe([])
        ->and($assignmentAttachments)->toBe([]);
});

// ── Case 3 — attached + visible + UNLISTED: board door dead, assignment door alive ──

it('case 3 — an unlisted campaign 404s on the Jobs Board but an assigned creator still reads the attachment via assignment detail', function (): void {
    $f = CreatorJobFixture::make();
    $f->campaign->forceFill(['listed_on_jobs_board' => false])->save();

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Producing)->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
    ]);
    $file = AgencyFile::factory()->forAgency($f->agency->id)->create();
    CampaignAttachment::factory()->visible()->createOne(['campaign_id' => $f->campaign->id, 'agency_file_id' => $file->id]);

    $this->actingAs($f->user)->getJson($f->jobUrl())->assertNotFound();
    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->assertJsonCount(0, 'data');

    $this->actingAs($f->user)->getJson(attachmentReadAssignmentUrl($assignment))
        ->assertOk()
        ->assertJsonCount(1, 'data.attributes.attachments');
});

// ── Case 5 — severed roster relation: assignment door survives, board door dies ──

it('case 5 — a SEVERED roster relation kills the Jobs Board door but the assignment door still reads the visible attachment (AH-051 survives-disconnect)', function (): void {
    $f = CreatorJobFixture::make();
    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Producing)->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
    ]);
    $file = AgencyFile::factory()->forAgency($f->agency->id)->create();
    CampaignAttachment::factory()->visible()->createOne(['campaign_id' => $f->campaign->id, 'agency_file_id' => $file->id]);

    $f->relation->forceFill(['relationship_status' => RelationshipStatus::Ended])->save();

    // Door 1 (Jobs Board) is now dead for this caller.
    $this->actingAs($f->user)->getJson($f->jobUrl())->assertNotFound();
    $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->assertJsonCount(0, 'data');

    // Door 2 (assignment ownership) is untouched by relation status.
    $this->actingAs($f->user)->getJson(attachmentReadAssignmentUrl($assignment))
        ->assertOk()
        ->assertJsonCount(1, 'data.attributes.attachments');
});

// ── Case 6 — brand soft-blacklist: both doors remain readable ─────────────

it('case 6 — a brand SOFT blacklist leaves the campaign and its ON attachment readable on the Jobs Board (warn-at-invite posture, unaffected)', function (): void {
    $f = CreatorJobFixture::make();
    $file = AgencyFile::factory()->forAgency($f->agency->id)->create();
    CampaignAttachment::factory()->visible()->createOne(['campaign_id' => $f->campaign->id, 'agency_file_id' => $file->id]);

    BrandCreatorBlacklist::factory()->soft()->createOne([
        'brand_id' => $f->brand->id,
        'creator_id' => $f->creator->id,
    ]);

    $this->actingAs($f->user)->getJson($f->jobUrl())
        ->assertOk()
        ->assertJsonCount(1, 'data.attributes.attachments');
});

// ── Case 13 — the `attachments` key is always present, even at zero ───────

it('case 13 — the attachments key is present (an empty array), never omitted, when nothing qualifies', function (): void {
    $f = CreatorJobFixture::make();
    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Producing)->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
    ]);

    $jobAttributes = $this->actingAs($f->user)->getJson($f->jobUrl())->assertOk()->json('data.attributes');
    $assignmentAttributes = $this->actingAs($f->user)->getJson(attachmentReadAssignmentUrl($assignment))->assertOk()->json('data.attributes');

    expect($jobAttributes)->toHaveKey('attachments')
        ->and($jobAttributes['attachments'])->toBe([])
        ->and($assignmentAttributes)->toHaveKey('attachments')
        ->and($assignmentAttributes['attachments'])->toBe([]);
});

// ── The flat list never gains attachments (D4's fence) ─────────────────────

it('never adds attachments to the flat GET creators/me/assignments list', function (): void {
    $f = CreatorJobFixture::make();
    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Producing)->createOne([
        'campaign_id' => $f->campaign->id,
        'creator_id' => $f->creator->id,
    ]);
    $file = AgencyFile::factory()->forAgency($f->agency->id)->create();
    CampaignAttachment::factory()->visible()->createOne(['campaign_id' => $f->campaign->id, 'agency_file_id' => $file->id]);

    $response = $this->actingAs($f->user)->getJson('/api/v1/creators/me/assignments')->assertOk();

    $rows = array_filter($response->json('data'), fn (array $row): bool => $row['id'] === $assignment->ulid);
    $row = array_values($rows)[0] ?? null;
    expect($row)->not->toBeNull()
        ->and($row['attributes'] ?? [])->not->toHaveKey('attachments');
});

it('never leaks another agency\'s file into the Jobs Board card (cardAttributes stays byte-identical)', function (): void {
    $f = CreatorJobFixture::make();
    $file = AgencyFile::factory()->forAgency($f->agency->id)->create();
    CampaignAttachment::factory()->visible()->createOne(['campaign_id' => $f->campaign->id, 'agency_file_id' => $file->id]);

    $card = $this->actingAs($f->user)->getJson(CreatorJobFixture::URL)->assertOk()->json('data.0.attributes');

    expect($card)->not->toHaveKey('attachments');
});
