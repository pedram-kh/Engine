<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\DraftReviewStatus;
use App\Modules\Campaigns\Enums\PostedContentVerificationStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignDraft;
use App\Modules\Campaigns\Models\CampaignPostedContent;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function draftsUrl(Agency $agency, Campaign $campaign): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/drafts";
}

/**
 * @return array{0: Agency, 1: Campaign, 2: list<CampaignAssignment>}
 */
function draftsListSetup(int $assignmentCount = 2, int $versionsPerAssignment = 2): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);
    $creator = Creator::factory()->approved()->createOne();
    $inviter = User::factory()->agencyAdmin($agency)->createOne();

    $assignments = [];
    for ($i = 0; $i < $assignmentCount; $i++) {
        $assignmentCreator = $i === 0 ? $creator : Creator::factory()->approved()->createOne();
        $assignment = CampaignAssignment::factory()->status(AssignmentStatus::DraftSubmitted)->createOne([
            'agency_id' => $agency->id,
            'campaign_id' => $campaign->id,
            'brand_id' => $brand->id,
            'creator_id' => $assignmentCreator->id,
            'invited_by_user_id' => $inviter->id,
        ]);

        for ($version = 1; $version <= $versionsPerAssignment; $version++) {
            CampaignDraft::factory()->version($version)->createOne([
                'assignment_id' => $assignment->id,
                'submitted_by_creator_id' => $assignmentCreator->id,
                'review_status' => $version === $versionsPerAssignment
                    ? DraftReviewStatus::Pending
                    : DraftReviewStatus::RevisionRequested,
            ]);
        }

        $assignments[] = $assignment;
    }

    return [$agency, $campaign, $assignments];
}

it('returns all draft versions across assignments for the campaign', function (): void {
    [$agency, $campaign] = draftsListSetup(2, 2);
    $staff = User::factory()->agencyStaff($agency)->createOne();

    $this->actingAs($staff)
        ->getJson(draftsUrl($agency, $campaign))
        ->assertOk()
        ->assertJsonPath('meta.total', 4)
        ->assertJsonCount(4, 'data');
});

it('filters by review_status', function (): void {
    [$agency, $campaign, $assignments] = draftsListSetup(1, 2);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    CampaignDraft::factory()->version(3)->reviewStatus(DraftReviewStatus::Approved)->createOne([
        'assignment_id' => $assignments[0]->id,
        'submitted_by_creator_id' => $assignments[0]->creator_id,
    ]);

    $this->actingAs($admin)
        ->getJson(draftsUrl($agency, $campaign).'?review_status=pending')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.attributes.review_status', 'pending');
});

it('paginates draft rows', function (): void {
    [$agency, $campaign] = draftsListSetup(2, 2);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->getJson(draftsUrl($agency, $campaign).'?per_page=1&page=2')
        ->assertOk()
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.page', 2)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.last_page', 4)
        ->assertJsonCount(1, 'data');
});

it('404s when the campaign belongs to another agency (cross-tenant absence)', function (): void {
    [$agency, $campaign] = draftsListSetup(1, 1);
    $foreignAgency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($foreignAgency)->createOne();

    $this->actingAs($admin)
        ->getJson(draftsUrl($foreignAgency, $campaign))
        ->assertNotFound();
});

it('404s for a non-member (tenancy invisibility)', function (): void {
    [$agency, $campaign] = draftsListSetup(1, 1);
    $outsider = User::factory()->agencyAdmin()->createOne();

    $this->actingAs($outsider)
        ->getJson(draftsUrl($agency, $campaign))
        ->assertNotFound();
});

it('is view-gated — any agency member may list drafts', function (): void {
    [$agency, $campaign] = draftsListSetup(1, 1);
    $staff = User::factory()->agencyStaff($agency)->createOne();

    $this->actingAs($staff)
        ->getJson(draftsUrl($agency, $campaign))
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('returns the summary shape with assignment context and no signed media URLs', function (): void {
    [$agency, $campaign, $assignments] = draftsListSetup(1, 1);
    $assignment = $assignments[0];
    $draft = CampaignDraft::query()->where('assignment_id', $assignment->id)->firstOrFail();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $response = $this->actingAs($admin)
        ->getJson(draftsUrl($agency, $campaign))
        ->assertOk()
        ->assertJsonPath('data.0.id', $draft->ulid)
        ->assertJsonPath('data.0.type', 'campaign_draft_list_item')
        ->assertJsonPath('data.0.attributes.version', 1)
        ->assertJsonPath('data.0.attributes.review_status', 'pending')
        ->assertJsonPath('data.0.attributes.assignment.id', $assignment->ulid)
        ->assertJsonPath('data.0.attributes.assignment.status', 'draft_submitted')
        ->assertJsonStructure([
            'data' => [
                [
                    'attributes' => [
                        'assignment' => [
                            'creator' => ['id', 'display_name'],
                        ],
                    ],
                ],
            ],
        ]);

    $encoded = json_encode($response->json('data'), JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('view_url')
        ->and($encoded)->not->toContain('thumbnail_view_url')
        ->and($encoded)->not->toContain('"media"');
});

it('emits the LATEST post verification status on the assignment stub (Drafts-tab resolve action, AH-045)', function (): void {
    [$agency, $campaign, $assignments] = draftsListSetup(1, 1);
    $assignment = $assignments[0];
    $assignment->update(['status' => AssignmentStatus::Posted, 'posted_at' => now()]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    // Two posted rows — the stub must reflect the LATEST one (not_found), not
    // the older verified one.
    CampaignPostedContent::factory()->createOne([
        'assignment_id' => $assignment->id,
        'verification_status' => PostedContentVerificationStatus::Verified,
    ]);
    CampaignPostedContent::factory()->createOne([
        'assignment_id' => $assignment->id,
        'verification_status' => PostedContentVerificationStatus::NotFound,
    ]);

    $this->actingAs($admin)
        ->getJson(draftsUrl($agency, $campaign))
        ->assertOk()
        ->assertJsonPath('data.0.attributes.assignment.status', 'posted')
        ->assertJsonPath('data.0.attributes.assignment.verification_status', 'not_found');
});

it('emits a null verification status when the assignment has no posted content', function (): void {
    [$agency, $campaign] = draftsListSetup(1, 1);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->getJson(draftsUrl($agency, $campaign))
        ->assertOk()
        ->assertJsonPath('data.0.attributes.assignment.verification_status', null);
});

it('returns an empty page for an invalid review_status (CampaignController precedent)', function (): void {
    [$agency, $campaign] = draftsListSetup(1, 1);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->getJson(draftsUrl($agency, $campaign).'?review_status=not-a-status')
        ->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('data', []);
});
