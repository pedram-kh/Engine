<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Boards\Services\OverdueScanService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignPostedContent;
use App\Modules\Campaigns\Services\CampaignInvitationService;
use App\Modules\Campaigns\ValueObjects\AssignmentOffer;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-069 Q2 + D8 — the two places a hand-off campaign could still be dragged
 * back onto the posting path.
 *
 *   Q2. The creator's post endpoint. In practice an approval on a hand-off
 *       campaign already completed the assignment, so the existing
 *       `status !== approved` check catches it. The guard covers the sliver the
 *       status check cannot: an assignment approved while the toggle was ON, on
 *       a campaign turned OFF before the creator got around to posting. Without
 *       it, that post lands the card on a column the board no longer renders.
 *
 *   D8. `posting_due_at`. Fixed on BOTH sides, because they cover different
 *       populations: the writer stops stamping the deadline on hand-off
 *       campaigns (nothing to miss), and the sweep excludes them (covers the
 *       assignments invited before the toggle was turned off, whose deadline is
 *       already in the table).
 *
 * Recorded as PROPHYLAXIS, not a bug fix (Q5): no automation is seeded against
 * the posting-overdue verb today, so the live blast radius of the unguarded
 * sweep is a column stamp and an audit row. These guards stop that from
 * becoming a false "you are late to post" the day somebody maps the verb.
 *
 * @return array{0: Agency, 1: Campaign, 2: Creator, 3: User}
 */
function handOffGuardSetup(bool $creatorPostsContent): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne([
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'creator_posts_content' => $creatorPostsContent,
    ]);
    $creator = Creator::factory()->approved()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    return [$agency, $campaign, $creator, $admin];
}

function handOffApprovedAssignment(Agency $agency, Campaign $campaign, Creator $creator): CampaignAssignment
{
    return CampaignAssignment::factory()->status(AssignmentStatus::Approved)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $campaign->brand_id,
        'creator_id' => $creator->id,
        'approved_at' => now(),
    ]);
}

// ── Q2: the creator's post endpoint ─────────────────────────────────────────

it('refuses a creator post on a hand-off campaign, and creates nothing (Q2)', function (): void {
    [$agency, $campaign, $creator] = handOffGuardSetup(creatorPostsContent: false);
    $assignment = handOffApprovedAssignment($agency, $campaign, $creator);

    $response = $this->actingAs($creator->user)
        ->postJson("/api/v1/creators/me/assignments/{$assignment->ulid}/posted-content", [
            'platform' => 'instagram',
            'post_url' => 'https://instagram.com/p/abc123',
        ])
        ->assertStatus(422);

    expect($response->json('errors.0.code'))->toBe('assignment.posting_not_required');

    // Nothing half-happened: no posted-content row, no transition, no audit.
    expect(CampaignPostedContent::query()->where('assignment_id', $assignment->id)->exists())->toBeFalse()
        ->and($assignment->fresh()?->status)->toBe(AssignmentStatus::Approved)
        ->and(AuditLog::query()
            ->where('subject_id', $assignment->id)
            ->where('action', AuditAction::AssignmentPostedByCreator->value)
            ->exists())->toBeFalse();
});

it('still accepts a creator post on a posting campaign (the guard is inert there)', function (): void {
    [$agency, $campaign, $creator] = handOffGuardSetup(creatorPostsContent: true);
    $assignment = handOffApprovedAssignment($agency, $campaign, $creator);

    $this->actingAs($creator->user)
        ->postJson("/api/v1/creators/me/assignments/{$assignment->ulid}/posted-content", [
            'platform' => 'instagram',
            'post_url' => 'https://instagram.com/p/abc123',
        ])
        ->assertCreated();

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Posted);
});

it('tells the creator surface which posture the campaign has (D7 meta flag)', function (): void {
    [$agency, $campaign, $creator] = handOffGuardSetup(creatorPostsContent: false);
    $assignment = handOffApprovedAssignment($agency, $campaign, $creator);

    $this->actingAs($creator->user)
        ->getJson("/api/v1/creators/me/assignments/{$assignment->ulid}")
        ->assertOk()
        ->assertJsonPath('meta.creator_posts_content', false);
});

it('reports the posting posture as true on a normal campaign', function (): void {
    [$agency, $campaign, $creator] = handOffGuardSetup(creatorPostsContent: true);
    $assignment = handOffApprovedAssignment($agency, $campaign, $creator);

    $this->actingAs($creator->user)
        ->getJson("/api/v1/creators/me/assignments/{$assignment->ulid}")
        ->assertOk()
        ->assertJsonPath('meta.creator_posts_content', true);
});

// ── D8 / Q5b: the writer ────────────────────────────────────────────────────

it('does not stamp posting_due_at when inviting to a hand-off campaign (Q5b)', function (): void {
    [$agency, $campaign, $creator, $admin] = handOffGuardSetup(creatorPostsContent: false);

    $assignment = app(CampaignInvitationService::class)->invite(
        $agency,
        $campaign,
        $creator,
        new AssignmentOffer(
            agreedFeeMinorUnits: 50000,
            agreedFeeCurrency: 'EUR',
            postingDueAt: now()->addDays(10)->toIso8601String(),
            draftDueAt: now()->addDays(5)->toIso8601String(),
        ),
        $admin,
    );

    expect($assignment->posting_due_at)->toBeNull()
        // The DRAFT deadline is untouched — a hand-off campaign still wants its
        // draft on time; it is only the posting step that does not exist.
        ->and($assignment->draft_due_at)->not->toBeNull();
});

it('still stamps posting_due_at when inviting to a posting campaign', function (): void {
    [$agency, $campaign, $creator, $admin] = handOffGuardSetup(creatorPostsContent: true);

    $assignment = app(CampaignInvitationService::class)->invite(
        $agency,
        $campaign,
        $creator,
        new AssignmentOffer(
            agreedFeeMinorUnits: 50000,
            agreedFeeCurrency: 'EUR',
            postingDueAt: now()->addDays(10)->toIso8601String(),
        ),
        $admin,
    );

    expect($assignment->posting_due_at)->not->toBeNull();
});

// ── D8: the sweep ───────────────────────────────────────────────────────────

it('never flags a hand-off campaign as overdue to post (D8)', function (): void {
    [$agency, $campaign, $creator] = handOffGuardSetup(creatorPostsContent: false);

    // The historical row the writer-side fix cannot reach: invited while the
    // toggle was ON, so the deadline is already in the table, and now overdue.
    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::CompletedOnApproval)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $campaign->brand_id,
        'creator_id' => $creator->id,
        'posting_due_at' => now()->subDays(3),
    ]);

    $counts = app(OverdueScanService::class)->scan();

    expect($counts['posting'])->toBe(0)
        // Not merely skipped — UNFLAGGED. The one-shot marker stays null, so if
        // the campaign's toggle goes back on the deadline is live again.
        ->and($assignment->fresh()?->posting_overdue_flagged_at)->toBeNull();
});

it('never flags a hand-off campaign before approval either', function (): void {
    // The status-based exclusion would have missed this one: a `contracted`
    // assignment on an OFF campaign, with a stale deadline from before the flip.
    [$agency, $campaign, $creator] = handOffGuardSetup(creatorPostsContent: false);

    CampaignAssignment::factory()->status(AssignmentStatus::Contracted)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $campaign->brand_id,
        'creator_id' => $creator->id,
        'posting_due_at' => now()->subDays(3),
    ]);

    expect(app(OverdueScanService::class)->scan()['posting'])->toBe(0);
});

it('still flags a posting campaign as overdue (the exclusion is narrow)', function (): void {
    [$agency, $campaign, $creator] = handOffGuardSetup(creatorPostsContent: true);

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Approved)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $campaign->brand_id,
        'creator_id' => $creator->id,
        'posting_due_at' => now()->subDays(3),
    ]);

    expect(app(OverdueScanService::class)->scan()['posting'])->toBe(1)
        ->and($assignment->fresh()?->posting_overdue_flagged_at)->not->toBeNull();
});

it('leaves the DRAFT sweep untouched on a hand-off campaign', function (): void {
    // The exclusion is applied to the posting sweep only. A hand-off campaign
    // still has drafts, and a late draft is still late.
    [$agency, $campaign, $creator] = handOffGuardSetup(creatorPostsContent: false);

    CampaignAssignment::factory()->status(AssignmentStatus::Contracted)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $campaign->brand_id,
        'creator_id' => $creator->id,
        'draft_due_at' => now()->subDays(3),
    ]);

    $counts = app(OverdueScanService::class)->scan();

    expect($counts['draft'])->toBe(1)->and($counts['posting'])->toBe(0);
});
