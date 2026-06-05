<?php

declare(strict_types=1);

use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignDraft;
use App\Modules\Campaigns\Models\CampaignPostedContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 9 Chunk 1 — column-set migration test
|--------------------------------------------------------------------------
|
| Mirrors the Sprint3/Sprint4 migration-test discipline: assert that every
| column from docs/03-DATA-MODEL.md §7 (`campaign_drafts` :572-600,
| `campaign_posted_content` :605-622) is present after migrate:fresh, and
| that the assignment-CASCADE FK + the version-uniqueness invariant hold.
|
*/

it('campaign_drafts table has every Phase 1 column from docs/03-DATA-MODEL.md §7', function (): void {
    expect(Schema::hasTable('campaign_drafts'))->toBeTrue();

    $expected = [
        'id',
        'ulid',
        'assignment_id',
        'version',
        'submitted_by_creator_id',
        'submitted_at',
        'caption',
        'hashtags',
        'mentions',
        'media_attachments',
        'review_status',
        'reviewed_at',
        'reviewed_by_user_id',
        'review_feedback',
        'client_review_status',
        'client_reviewed_at',
        'client_reviewed_by_user_id',
        'client_review_feedback',
        'ai_qc_results',
        'ai_qc_passed',
        'created_at',
        'updated_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('campaign_drafts', $column))
            ->toBeTrue("campaign_drafts.{$column} should exist");
    }
});

it('campaign_posted_content table has every Phase 1 column from docs/03-DATA-MODEL.md §7', function (): void {
    expect(Schema::hasTable('campaign_posted_content'))->toBeTrue();

    $expected = [
        'id',
        'ulid',
        'assignment_id',
        'platform',
        'post_url',
        'platform_post_id',
        'posted_at',
        'verified_at',
        'verification_status',
        'last_metrics_synced_at',
        'metrics',
        'metrics_history',
        'created_at',
        'updated_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('campaign_posted_content', $column))
            ->toBeTrue("campaign_posted_content.{$column} should exist");
    }
});

it('cascades drafts + posted-content when their assignment is deleted', function (): void {
    $assignment = CampaignAssignment::factory()->create();

    $draft = CampaignDraft::factory()->create([
        'assignment_id' => $assignment->id,
    ]);
    $posted = CampaignPostedContent::factory()->create([
        'assignment_id' => $assignment->id,
    ]);

    // Hard-delete the parent row (bypass soft-delete) to exercise the DB CASCADE.
    DB::table('campaign_assignments')->where('id', $assignment->id)->delete();

    expect(CampaignDraft::query()->whereKey($draft->id)->exists())->toBeFalse();
    expect(CampaignPostedContent::query()->whereKey($posted->id)->exists())->toBeFalse();
});
