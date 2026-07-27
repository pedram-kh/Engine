<?php

declare(strict_types=1);

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Core\Tenancy\TenancyContext;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignJobNotification;
use App\Modules\Creators\Models\Creator;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Jobs Board chunk 3 (AH-056) sub-step 1 — the three additive migrations and
 * the two new models, pinned before anything reads them.
 *
 * The load-bearing assertions here are the two unique composites: they are not
 * decoration, they ARE the mechanism for "one application per creator per
 * campaign", "no re-apply after rejection" (the retained terminal row keeps the
 * pair occupied) and "a re-list never re-notifies".
 */

// ── campaign_applications ───────────────────────────────────────────────────

it('enforces one application per (campaign, creator) pair', function (): void {
    $campaign = Campaign::factory()->listed()->createOne();
    $creator = Creator::factory()->createOne();

    CampaignApplication::factory()->createOne([
        'campaign_id' => $campaign->id,
        'creator_id' => $creator->id,
    ]);

    expect(fn () => CampaignApplication::factory()->createOne([
        'campaign_id' => $campaign->id,
        'creator_id' => $creator->id,
    ]))->toThrow(QueryException::class);
});

it('keeps the unique pair occupied by a RETAINED rejected row — the no-re-apply mechanism', function (): void {
    $campaign = Campaign::factory()->listed()->createOne();
    $creator = Creator::factory()->createOne();

    CampaignApplication::factory()
        ->status(CampaignApplicationStatus::Rejected)
        ->createOne(['campaign_id' => $campaign->id, 'creator_id' => $creator->id]);

    // The rejected row is NOT soft-deleted and NOT removed, so a second insert
    // for the same pair cannot happen at the database level either. This is the
    // belt behind the application-layer 409 (D5).
    expect(fn () => CampaignApplication::factory()->createOne([
        'campaign_id' => $campaign->id,
        'creator_id' => $creator->id,
    ]))->toThrow(QueryException::class);

    expect(CampaignApplication::withoutGlobalScope(BelongsToAgencyScope::class)
        ->where('campaign_id', $campaign->id)
        ->count())->toBe(1);
});

it('lets the SAME creator apply to a DIFFERENT campaign, and different creators to the same one', function (): void {
    $campaignA = Campaign::factory()->listed()->createOne();
    $campaignB = Campaign::factory()->listed()->createOne();
    $creator = Creator::factory()->createOne();
    $other = Creator::factory()->createOne();

    CampaignApplication::factory()->createOne(['campaign_id' => $campaignA->id, 'creator_id' => $creator->id]);
    CampaignApplication::factory()->createOne(['campaign_id' => $campaignB->id, 'creator_id' => $creator->id]);
    CampaignApplication::factory()->createOne(['campaign_id' => $campaignA->id, 'creator_id' => $other->id]);

    expect(CampaignApplication::withoutGlobalScope(BelongsToAgencyScope::class)->count())->toBe(3);
});

it('auto-populates a ulid and casts status + responded_at', function (): void {
    $application = CampaignApplication::factory()
        ->status(CampaignApplicationStatus::Accepted)
        ->createOne();

    expect($application->ulid)->toBeString()->toHaveLength(26)
        ->and($application->status)->toBe(CampaignApplicationStatus::Accepted)
        ->and($application->responded_at)->not->toBeNull();
});

it('denormalises agency_id from the parent campaign', function (): void {
    $agency = Agency::factory()->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->listed()->createOne();

    $application = CampaignApplication::factory()->createOne(['campaign_id' => $campaign->id]);

    expect($application->agency_id)->toBe($agency->id)->toBe($campaign->agency_id);
});

it('is tenant-scoped, so an agency never reads another agency application', function (): void {
    $mine = Agency::factory()->createOne();
    $theirs = Agency::factory()->createOne();

    CampaignApplication::factory()->createOne([
        'campaign_id' => Campaign::factory()->forAgency($mine->id)->listed()->createOne()->id,
    ]);
    CampaignApplication::factory()->createOne([
        'campaign_id' => Campaign::factory()->forAgency($theirs->id)->listed()->createOne()->id,
    ]);

    app(TenancyContext::class)->setAgencyId($mine->id);

    expect(CampaignApplication::query()->count())->toBe(1)
        ->and(CampaignApplication::withoutGlobalScope(BelongsToAgencyScope::class)->count())->toBe(2);
});

// ── the applicant count (D4) ────────────────────────────────────────────────

it('counts applications in EVERY status, and never confuses them with assignments', function (): void {
    $campaign = Campaign::factory()->listed()->createOne();

    foreach (CampaignApplicationStatus::cases() as $status) {
        CampaignApplication::factory()
            ->status($status)
            ->createOne(['campaign_id' => $campaign->id, 'creator_id' => Creator::factory()->createOne()->id]);
    }

    $counted = Campaign::withoutGlobalScope(BelongsToAgencyScope::class)
        ->withCount(['applications', 'assignments'])
        ->findOrFail($campaign->id);

    // Three applications (pending + accepted + rejected — social proof of
    // interest, D4), zero assignments: the two counts are independent, so
    // `assignment_count`'s shipped meaning is untouched.
    expect($counted->applications_count)->toBe(3)
        ->and($counted->assignments_count)->toBe(0);
});

// ── campaign_job_notifications (D7) ─────────────────────────────────────────

it('enforces one job-posted stamp per (campaign, creator) pair — the re-list guard', function (): void {
    $campaign = Campaign::factory()->listed()->createOne();
    $creator = Creator::factory()->createOne();

    CampaignJobNotification::factory()->createOne([
        'campaign_id' => $campaign->id,
        'creator_id' => $creator->id,
    ]);

    expect(fn () => CampaignJobNotification::factory()->createOne([
        'campaign_id' => $campaign->id,
        'creator_id' => $creator->id,
    ]))->toThrow(QueryException::class);
});

it('carries notified_at and no created_at/updated_at', function (): void {
    $stamp = CampaignJobNotification::factory()->createOne();

    expect($stamp->notified_at)->not->toBeNull()
        ->and($stamp->timestamps)->toBeFalse()
        ->and(Schema::hasColumn('campaign_job_notifications', 'created_at'))->toBeFalse();
});

// ── campaigns.listed_at (D4) ────────────────────────────────────────────────

it('adds listed_at as a nullable datetime that defaults to null', function (): void {
    $unlisted = Campaign::factory()->jobReady()->createOne();
    $listed = Campaign::factory()->listed()->createOne();

    expect($unlisted->listed_at)->toBeNull()
        ->and($listed->listed_at)->toBeInstanceOf(CarbonInterface::class);
});

// ── the enum (C4) ───────────────────────────────────────────────────────────

it('models a tiny two-outcome lifecycle with both outcomes terminal', function (): void {
    expect(CampaignApplicationStatus::Pending->isTerminal())->toBeFalse()
        ->and(CampaignApplicationStatus::Accepted->isTerminal())->toBeTrue()
        ->and(CampaignApplicationStatus::Rejected->isTerminal())->toBeTrue()
        ->and(array_map(
            static fn (CampaignApplicationStatus $s): string => $s->value,
            CampaignApplicationStatus::cases(),
        ))->toBe(['pending', 'accepted', 'rejected']);
});

it('maps each status to the 409 code a fresh apply must receive (D5)', function (): void {
    expect(CampaignApplicationStatus::Pending->reapplyBlockCode())->toBe('job.already_applied')
        ->and(CampaignApplicationStatus::Accepted->reapplyBlockCode())->toBe('job.already_applied')
        ->and(CampaignApplicationStatus::Rejected->reapplyBlockCode())->toBe('job.application_rejected');
});
