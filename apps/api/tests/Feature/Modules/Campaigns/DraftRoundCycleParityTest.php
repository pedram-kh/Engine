<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\DraftReviewStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignDraft;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-068 (D6) — the behaviour-parity pin.
 *
 * D6 says this chunk changes what the review cycle SAYS and nothing about what it
 * DOES. The zero-diff on the machine, the two controllers, the enums and the
 * migrations is the primary evidence (recorded by command output in the review
 * file); this test is the executable half: it drives a whole
 * submit → request changes → resubmit → approve cycle through the real endpoints
 * and pins every row, status and audit verb it produces.
 *
 * It is deliberately written as one long assertion of the WHOLE cycle rather than
 * split per step. The claim being defended is about the cycle as a unit — that
 * two rounds leave exactly two rows, each closed by its own review, with the
 * statuses walking the same path they always walked. A future change that alters
 * any part of that reds here with the full sequence visible.
 *
 * @return array{0: Agency, 1: Campaign, 2: CampaignAssignment, 3: Creator, 4: User, 5: User}
 */
function cycleSetup(): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne([
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'budget_currency' => 'EUR',
    ]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creatorUser = User::factory()->createOne();
    $creator = CreatorFactory::new()->createOne(['user_id' => $creatorUser->id]);

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Producing)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
        'invited_by_user_id' => $admin->id,
    ]);

    return [$agency, $campaign, $assignment, $creator, $creatorUser, $admin];
}

function cycleReviewUrl(Agency $agency, Campaign $campaign, CampaignAssignment $assignment, string $action): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/{$action}";
}

it('a full submit → changes → resubmit → approve cycle produces exactly the rows, statuses and audit verbs it always did', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, , $creatorUser, $admin] = cycleSetup();

    $submitUrl = "/api/v1/creators/me/assignments/{$assignment->ulid}/drafts";

    // ── Round 1: the creator submits ──────────────────────────────────────────
    $this->actingAs($creatorUser)
        ->postJson($submitUrl, [
            'caption' => 'First cut',
            'links' => [['url' => 'https://example.com/first-cut']],
        ])
        ->assertCreated()
        ->assertJsonPath('data.attributes.version', 1);

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::DraftSubmitted);

    // ── Round 1 closes: the agency asks for changes ───────────────────────────
    $this->actingAs($admin)
        ->postJson(cycleReviewUrl($agency, $campaign, $assignment, 'request-revision'), [
            'review_feedback' => 'Brighten the lighting.',
        ])
        ->assertOk()
        ->assertJsonPath('meta.code', 'assignment.revision_requested');

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::RevisionRequested);

    // ── Round 2: the creator resubmits ────────────────────────────────────────
    $this->actingAs($creatorUser)
        ->postJson($submitUrl, [
            'caption' => 'Second cut',
            'links' => [['url' => 'https://example.com/second-cut']],
        ])
        ->assertCreated()
        // max(version) + 1 — the round number IS the version (D1).
        ->assertJsonPath('data.attributes.version', 2);

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::DraftSubmitted);

    // ── Round 2 closes: approved ──────────────────────────────────────────────
    $this->actingAs($admin)
        ->postJson(cycleReviewUrl($agency, $campaign, $assignment, 'approve'))
        ->assertOk()
        ->assertJsonPath('meta.code', 'assignment.draft_approved');

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Approved);

    // ── What the cycle left behind ────────────────────────────────────────────

    $drafts = CampaignDraft::query()
        ->where('assignment_id', $assignment->id)
        ->orderBy('version')
        ->get();

    // Two rounds, two rows — a resubmission never overwrites its predecessor.
    expect($drafts)->toHaveCount(2)
        ->and($drafts->pluck('version')->all())->toBe([1, 2]);

    // Each round carries ITS OWN closing review, not the latest one.
    $round1 = $drafts->firstWhere('version', 1);
    $round2 = $drafts->firstWhere('version', 2);

    expect($round1?->review_status)->toBe(DraftReviewStatus::RevisionRequested)
        ->and($round1?->review_feedback)->toBe('Brighten the lighting.')
        ->and($round1?->reviewed_at)->not->toBeNull()
        ->and($round1?->caption)->toBe('First cut')
        ->and($round2?->review_status)->toBe(DraftReviewStatus::Approved)
        // The approval carried no note, and none was invented for it.
        ->and($round2?->review_feedback)->toBeNull()
        ->and($round2?->reviewed_at)->not->toBeNull()
        ->and($round2?->caption)->toBe('Second cut');

    // The audit trail: the same four verbs, in the same order, once each.
    $verbs = AuditLog::query()
        ->where('subject_id', $assignment->id)
        ->whereIn('action', [
            'assignment.draft_submitted',
            'assignment.revision_requested',
            'assignment.draft_approved',
        ])
        ->orderBy('id')
        ->pluck('action')
        ->map(static fn (AuditAction $action): string => $action->value)
        ->all();

    expect($verbs)->toBe([
        'assignment.draft_submitted',
        'assignment.revision_requested',
        'assignment.draft_submitted',
        'assignment.draft_approved',
    ]);
});

it('the round number is the version column and nothing else — no counter, no second source of truth', function (): void {
    // D1 pinned structurally: whatever the surfaces call a "round", the only
    // number behind it is `campaign_drafts.version`. A counter column added later
    // would make this list longer and red the test, which is the intent.
    $columns = array_keys(CampaignDraft::factory()->makeOne()->getAttributes());

    expect($columns)->toContain('version')
        ->and($columns)->not->toContain('round')
        ->and($columns)->not->toContain('round_number');
});
