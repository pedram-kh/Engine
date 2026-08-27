<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyFile;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAttachment;
use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Campaign attachments from the Files library, chunk B (AH-092), S1 — the
 * additive migration + model, pinned before anything reads them. Per
 * docs/reviews/campaign-attachments-plan.md §2.1/§2.2/§2.4/§6 S1.
 */
it('builds a same-agency attachment via factory with relations and casts intact', function (): void {
    $agency = Agency::factory()->create();
    $campaign = Campaign::factory()->forAgency($agency->id)->createOne();
    $uploader = User::factory()->create();

    $attachment = CampaignAttachment::factory()
        ->attachedBy($uploader->id)
        ->createOne(['campaign_id' => $campaign->id]);

    expect($attachment->campaign->id)->toBe($campaign->id)
        ->and($attachment->agencyFile->agency_id)->toBe($agency->id)
        ->and($attachment->attachedBy?->id)->toBe($uploader->id)
        ->and($attachment->visible_to_creators)->toBeFalse()
        ->and($attachment->created_at)->not->toBeNull();
});

it('enforces one attachment per (campaign, agency_file) pair — the idempotent-attach mechanism', function (): void {
    $campaign = Campaign::factory()->createOne();
    $file = AgencyFile::factory()->forAgency($campaign->agency_id)->create();

    CampaignAttachment::factory()->createOne(['campaign_id' => $campaign->id, 'agency_file_id' => $file->id]);

    expect(fn () => CampaignAttachment::factory()->createOne([
        'campaign_id' => $campaign->id,
        'agency_file_id' => $file->id,
    ]))->toThrow(QueryException::class);
});

it('lets the SAME file attach to two DIFFERENT campaigns with independent visibility', function (): void {
    $agency = Agency::factory()->create();
    $campaignA = Campaign::factory()->forAgency($agency->id)->createOne();
    $campaignB = Campaign::factory()->forAgency($agency->id)->createOne();
    $file = AgencyFile::factory()->forAgency($agency->id)->create();

    $onA = CampaignAttachment::factory()->visible()->createOne(['campaign_id' => $campaignA->id, 'agency_file_id' => $file->id]);
    $offB = CampaignAttachment::factory()->createOne(['campaign_id' => $campaignB->id, 'agency_file_id' => $file->id]);

    expect($onA->visible_to_creators)->toBeTrue()
        ->and($offB->visible_to_creators)->toBeFalse()
        ->and(CampaignAttachment::query()->where('agency_file_id', $file->id)->count())->toBe(2);
});

it('CASCADEs the join row when its campaign is hard-deleted', function (): void {
    $campaign = Campaign::factory()->createOne();
    $attachment = CampaignAttachment::factory()->createOne(['campaign_id' => $campaign->id]);

    DB::table('campaigns')->where('id', $campaign->id)->delete();

    expect(CampaignAttachment::query()->find($attachment->id))->toBeNull();
});

it('RESTRICTs a hard delete of an attached agency_file — the FK backstop behind the 409', function (): void {
    $campaign = Campaign::factory()->createOne();
    $file = AgencyFile::factory()->forAgency($campaign->agency_id)->create();
    CampaignAttachment::factory()->createOne(['campaign_id' => $campaign->id, 'agency_file_id' => $file->id]);

    expect(fn () => DB::table('agency_files')->where('id', $file->id)->delete())
        ->toThrow(QueryException::class);

    // An UNattached file has no backstop to trip.
    $unattached = AgencyFile::factory()->forAgency($campaign->agency_id)->create();
    DB::table('agency_files')->where('id', $unattached->id)->delete();
    expect(AgencyFile::withoutGlobalScopes()->find($unattached->id))->toBeNull();
});

it('nulls attached_by_user_id when the attaching user is deleted, without touching the row', function (): void {
    $user = User::factory()->create();
    $attachment = CampaignAttachment::factory()->attachedBy($user->id)->createOne();

    DB::table('users')->where('id', $user->id)->delete();

    expect($attachment->refresh()->attached_by_user_id)->toBeNull();
});

// ── AgencyFile::hasAttachments() — the AH-091 seam, now live (D2) ──────────

it('AgencyFile::hasAttachments() is false with no attachment and true the moment one exists, regardless of visibility', function (): void {
    $file = AgencyFile::factory()->create();
    expect($file->hasAttachments())->toBeFalse();

    CampaignAttachment::factory()->createOne(['agency_file_id' => $file->id]);

    expect($file->hasAttachments())->toBeTrue();
});

it('hasAttachments() ignores the visibility flag, campaign status, and campaign soft-deletion', function (): void {
    $cancelled = Campaign::factory()->createOne(['status' => 'cancelled']);
    $file = AgencyFile::factory()->forAgency($cancelled->agency_id)->create();
    CampaignAttachment::factory()->createOne([
        'campaign_id' => $cancelled->id,
        'agency_file_id' => $file->id,
        'visible_to_creators' => false,
    ]);

    expect($file->hasAttachments())->toBeTrue();

    $cancelled->delete(); // soft-delete only — the join row is untouched
    expect($file->hasAttachments())->toBeTrue();
});

// ── CampaignAttachment::creatorVisible() — the D4 shared definition ────────

it('creatorVisible() requires BOTH the flag ON and a live file', function (): void {
    $campaign = Campaign::factory()->createOne();
    $liveOn = CampaignAttachment::factory()->visible()->createOne(['campaign_id' => $campaign->id]);
    CampaignAttachment::factory()->createOne(['campaign_id' => $campaign->id]); // flag OFF

    $softDeletedFile = AgencyFile::factory()->forAgency($campaign->agency_id)->create();
    CampaignAttachment::factory()->visible()->createOne([
        'campaign_id' => $campaign->id,
        'agency_file_id' => $softDeletedFile->id,
    ]);
    $softDeletedFile->delete();

    $visible = CampaignAttachment::query()->where('campaign_id', $campaign->id)->creatorVisible()->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()?->id)->toBe($liveOn->id);
});
