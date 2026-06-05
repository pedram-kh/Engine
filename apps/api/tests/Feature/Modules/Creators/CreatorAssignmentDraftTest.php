<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignDraft;
use App\Modules\Campaigns\Models\CampaignPostedContent;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 9 Chunk 1 — the CREATOR submission surface (drafts + media +
 * posted-content). Pins:
 *   - draft submit creates a v1 row + producing → draft_submitted (audit + event);
 *   - resubmit creates v2 via the two-step machine path (version increments,
 *     history preserved), seeded from revision_requested;
 *   - posted-content submit creates the row + approved → posted (seeded approved);
 *   - startProducing as the explicit step (submit from contracted);
 *   - creator-self scoping (non-owned ULID 404) + fail-closed (non-producible 422);
 *   - presigned media init/complete under the `drafts` namespace + ownership.
 *
 * Arc STOPS at posted / verification_status=pending (no verifyLive, no review).
 */
if (! function_exists('draftCreatorUser')) {
    /**
     * @return array{0: User, 1: Creator}
     */
    function draftCreatorUser(): array
    {
        $user = User::factory()->create();
        $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

        return [$user, $creator];
    }
}

if (! function_exists('assignmentForCreatorInStatus')) {
    function assignmentForCreatorInStatus(Creator $creator, AssignmentStatus $status): CampaignAssignment
    {
        $campaign = Campaign::factory()->create(['budget_currency' => 'EUR']);

        return CampaignAssignment::factory()->status($status)->create([
            'campaign_id' => $campaign->id,
            'creator_id' => $creator->id,
        ]);
    }
}

if (! function_exists('draftMedia')) {
    /**
     * @return array{s3_path: string, mime_type: string, kind: string, thumbnail_path: null, duration_seconds: int}
     */
    function draftMedia(Creator $creator): array
    {
        return [
            's3_path' => "creators/{$creator->ulid}/drafts/01ABCDEF.mp4",
            'mime_type' => 'video/mp4',
            'kind' => 'video',
            'thumbnail_path' => null,
            'duration_seconds' => 30,
        ];
    }
}

if (! function_exists('reloadAssignment')) {
    function reloadAssignment(CampaignAssignment $assignment): CampaignAssignment
    {
        return $assignment->fresh() ?? $assignment;
    }
}

// ── Show (detail payload) ─────────────────────────────────────────────────────

it('returns 401 when unauthenticated on show', function (): void {
    [, $creator] = draftCreatorUser();
    $assignment = assignmentForCreatorInStatus($creator, AssignmentStatus::Producing);

    expect($this->getJson("/api/v1/creators/me/assignments/{$assignment->ulid}")->status())->toBe(401);
});

it('shows the assignment with its draft history + posted content', function (): void {
    [$user, $creator] = draftCreatorUser();
    $assignment = assignmentForCreatorInStatus($creator, AssignmentStatus::DraftSubmitted);

    CampaignDraft::factory()->version(1)->create(['assignment_id' => $assignment->id]);
    CampaignDraft::factory()->version(2)->create(['assignment_id' => $assignment->id]);

    $this->actingAs($user)
        ->getJson("/api/v1/creators/me/assignments/{$assignment->ulid}")
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'draft_submitted')
        ->assertJsonCount(2, 'data.relationships.drafts')
        // Newest version first.
        ->assertJsonPath('data.relationships.drafts.0.attributes.version', 2)
        ->assertJsonPath('data.relationships.drafts.1.attributes.version', 1);
});

it('404s on show for another creator\'s assignment', function (): void {
    [$user] = draftCreatorUser();
    $other = CreatorFactory::new()->createOne();
    $foreign = assignmentForCreatorInStatus($other, AssignmentStatus::Producing);

    $this->actingAs($user)
        ->getJson("/api/v1/creators/me/assignments/{$foreign->ulid}")
        ->assertNotFound();
});

// ── Draft submit (producing → draft_submitted) ────────────────────────────────

it('submits a v1 draft, transitions producing → draft_submitted, audits + emits the event', function (): void {
    Event::fake([AssignmentTransitioned::class]);

    [$user, $creator] = draftCreatorUser();
    $assignment = assignmentForCreatorInStatus($creator, AssignmentStatus::Producing);

    $this->actingAs($user)
        ->postJson("/api/v1/creators/me/assignments/{$assignment->ulid}/drafts", [
            'caption' => 'My first draft',
            'hashtags' => ['#ad'],
            'mentions' => ['@brand'],
            'media' => [draftMedia($creator)],
        ])
        ->assertCreated()
        ->assertJsonPath('meta.code', 'assignment.draft_submitted')
        ->assertJsonPath('data.attributes.version', 1)
        ->assertJsonPath('data.attributes.review_status', 'pending');

    $fresh = reloadAssignment($assignment);
    expect($fresh->status)->toBe(AssignmentStatus::DraftSubmitted)
        ->and($fresh->submitted_draft_at)->not->toBeNull();

    $draft = CampaignDraft::query()->where('assignment_id', $assignment->id)->firstOrFail();
    expect($draft->version)->toBe(1)
        ->and($draft->submitted_by_creator_id)->toBe($creator->id)
        ->and($draft->caption)->toBe('My first draft');

    // The transition audit carries the draft identity, NOT the free-text caption (D-3).
    $audit = AuditLog::query()
        ->where('action', 'assignment.draft_submitted')
        ->where('subject_id', $assignment->id)
        ->firstOrFail();
    expect($audit->metadata['draft_id'] ?? null)->toBe($draft->ulid)
        ->and($audit->metadata['version'] ?? null)->toBe(1)
        ->and($audit->metadata['media_count'] ?? null)->toBe(1)
        ->and($audit->metadata)->not->toHaveKey('caption');

    Event::assertDispatched(AssignmentTransitioned::class, fn (AssignmentTransitioned $e): bool => $e->to === AssignmentStatus::DraftSubmitted && $e->assignment->is($assignment));
});

it('submits from contracted via the explicit startProducing step (D-4) — two audit rows', function (): void {
    [$user, $creator] = draftCreatorUser();
    $assignment = assignmentForCreatorInStatus($creator, AssignmentStatus::Contracted);

    $this->actingAs($user)
        ->postJson("/api/v1/creators/me/assignments/{$assignment->ulid}/drafts", [
            'media' => [draftMedia($creator)],
        ])
        ->assertCreated()
        ->assertJsonPath('data.attributes.version', 1);

    expect(reloadAssignment($assignment)->status)->toBe(AssignmentStatus::DraftSubmitted);

    // Both the producing AND the draft_submitted transitions were audited.
    expect(AuditLog::query()->where('action', 'assignment.producing')->where('subject_id', $assignment->id)->exists())->toBeTrue();
    expect(AuditLog::query()->where('action', 'assignment.draft_submitted')->where('subject_id', $assignment->id)->exists())->toBeTrue();
});

it('resubmits as v2 via the two-step path (revision_requested → producing → draft_submitted), preserving v1 history', function (): void {
    [$user, $creator] = draftCreatorUser();
    $assignment = assignmentForCreatorInStatus($creator, AssignmentStatus::RevisionRequested);

    // Seed the prior submission (v1) so the resubmit computes max+1.
    CampaignDraft::factory()->version(1)->create(['assignment_id' => $assignment->id]);

    $this->actingAs($user)
        ->postJson("/api/v1/creators/me/assignments/{$assignment->ulid}/drafts", [
            'caption' => 'Revised per feedback',
            'media' => [draftMedia($creator)],
        ])
        ->assertCreated()
        ->assertJsonPath('data.attributes.version', 2);

    expect(reloadAssignment($assignment)->status)->toBe(AssignmentStatus::DraftSubmitted);

    // History preserved — both versions remain as their own rows.
    $versions = CampaignDraft::query()->where('assignment_id', $assignment->id)->orderBy('version')->pluck('version')->all();
    expect($versions)->toBe([1, 2]);
});

it('fails closed — a draft cannot be submitted on a non-producible assignment (422)', function (): void {
    [$user, $creator] = draftCreatorUser();
    $assignment = assignmentForCreatorInStatus($creator, AssignmentStatus::Accepted);

    $this->actingAs($user)
        ->postJson("/api/v1/creators/me/assignments/{$assignment->ulid}/drafts", [
            'media' => [draftMedia($creator)],
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'assignment.not_producible');

    expect(reloadAssignment($assignment)->status)->toBe(AssignmentStatus::Accepted);
    expect(CampaignDraft::query()->where('assignment_id', $assignment->id)->exists())->toBeFalse();
});

it('404s when submitting a draft on another creator\'s assignment', function (): void {
    [$user, $creator] = draftCreatorUser();
    $other = CreatorFactory::new()->createOne();
    $foreign = assignmentForCreatorInStatus($other, AssignmentStatus::Producing);

    $this->actingAs($user)
        ->postJson("/api/v1/creators/me/assignments/{$foreign->ulid}/drafts", [
            'media' => [draftMedia($creator)],
        ])
        ->assertNotFound();

    expect(reloadAssignment($foreign)->status)->toBe(AssignmentStatus::Producing);
});

it('rejects draft media that does not belong to the creator (422 draft.media_invalid)', function (): void {
    [$user, $creator] = draftCreatorUser();
    $other = CreatorFactory::new()->createOne();
    $assignment = assignmentForCreatorInStatus($creator, AssignmentStatus::Producing);

    $this->actingAs($user)
        ->postJson("/api/v1/creators/me/assignments/{$assignment->ulid}/drafts", [
            'media' => [draftMedia($other)], // a path under ANOTHER creator's prefix
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'draft.media_invalid');

    expect(reloadAssignment($assignment)->status)->toBe(AssignmentStatus::Producing);
});

// ── Posted content (approved → posted) ────────────────────────────────────────

it('submits posted content, transitions approved → posted, leaving verification pending', function (): void {
    [$user, $creator] = draftCreatorUser();
    $assignment = assignmentForCreatorInStatus($creator, AssignmentStatus::Approved);

    $this->actingAs($user)
        ->postJson("/api/v1/creators/me/assignments/{$assignment->ulid}/posted-content", [
            'platform' => 'instagram',
            'post_url' => 'https://instagram.com/p/abc123',
        ])
        ->assertCreated()
        ->assertJsonPath('meta.code', 'assignment.posted_by_creator')
        ->assertJsonPath('data.attributes.verification_status', 'pending');

    $fresh = reloadAssignment($assignment);
    expect($fresh->status)->toBe(AssignmentStatus::Posted)
        ->and($fresh->posted_at)->not->toBeNull();

    $posted = CampaignPostedContent::query()->where('assignment_id', $assignment->id)->firstOrFail();
    expect($posted->verification_status->value)->toBe('pending')
        ->and($posted->verified_at)->toBeNull();

    // The post_url (free text) is NOT in the transition audit metadata (D-3).
    $audit = AuditLog::query()->where('action', 'assignment.posted_by_creator')->where('subject_id', $assignment->id)->firstOrFail();
    expect($audit->metadata['posted_content_id'] ?? null)->toBe($posted->ulid)
        ->and($audit->metadata['platform'] ?? null)->toBe('instagram')
        ->and($audit->metadata)->not->toHaveKey('post_url');
});

it('fails closed — posted content cannot be submitted unless approved (422)', function (): void {
    [$user, $creator] = draftCreatorUser();
    $assignment = assignmentForCreatorInStatus($creator, AssignmentStatus::DraftSubmitted);

    $this->actingAs($user)
        ->postJson("/api/v1/creators/me/assignments/{$assignment->ulid}/posted-content", [
            'platform' => 'instagram',
            'post_url' => 'https://instagram.com/p/abc123',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'assignment.not_approved');

    expect(reloadAssignment($assignment)->status)->toBe(AssignmentStatus::DraftSubmitted);
    expect(CampaignPostedContent::query()->where('assignment_id', $assignment->id)->exists())->toBeFalse();
});

// ── Presigned draft media (init / complete + Content-Type match) ──────────────

it('initiates a presigned draft-media upload under the drafts namespace', function (): void {
    Storage::fake('media');

    [$user, $creator] = draftCreatorUser();
    $assignment = assignmentForCreatorInStatus($creator, AssignmentStatus::Producing);

    $this->actingAs($user)
        ->postJson("/api/v1/creators/me/assignments/{$assignment->ulid}/drafts/media/init", [
            'mime_type' => 'video/mp4',
            'declared_bytes' => 25 * 1024 * 1024,
        ])
        ->assertOk()
        ->assertJsonStructure(['data' => ['upload_url', 'upload_id', 'storage_path', 'expires_at', 'max_bytes']])
        ->assertJsonPath('data.upload_id', fn (string $id): bool => str_starts_with($id, "creators/{$creator->ulid}/drafts/") && str_ends_with($id, '.mp4'));
});

it('accepts image MIME for draft media (widened set, D-8)', function (): void {
    Storage::fake('media');

    [$user, $creator] = draftCreatorUser();
    $assignment = assignmentForCreatorInStatus($creator, AssignmentStatus::Producing);

    $this->actingAs($user)
        ->postJson("/api/v1/creators/me/assignments/{$assignment->ulid}/drafts/media/init", [
            'mime_type' => 'image/jpeg',
            'declared_bytes' => 1024,
        ])
        ->assertOk()
        ->assertJsonPath('data.upload_id', fn (string $id): bool => str_ends_with($id, '.jpg'));
});

it('completes a presigned draft-media upload once the object exists', function (): void {
    Storage::fake('media');

    [$user, $creator] = draftCreatorUser();
    $assignment = assignmentForCreatorInStatus($creator, AssignmentStatus::Producing);

    $path = "creators/{$creator->ulid}/drafts/01ABCDEFG.mp4";
    Storage::disk('media')->put($path, 'fake video bytes');

    $this->actingAs($user)
        ->postJson("/api/v1/creators/me/assignments/{$assignment->ulid}/drafts/media/complete", [
            'upload_id' => $path,
        ])
        ->assertCreated()
        ->assertJsonPath('data.storage_path', $path);
});

it('rejects a complete whose upload_id belongs to another creator', function (): void {
    Storage::fake('media');

    [$user, $creator] = draftCreatorUser();
    $other = CreatorFactory::new()->createOne();
    $assignment = assignmentForCreatorInStatus($creator, AssignmentStatus::Producing);

    $foreignPath = "creators/{$other->ulid}/drafts/01XYZ.mp4";
    Storage::disk('media')->put($foreignPath, 'fake');

    $this->actingAs($user)
        ->postJson("/api/v1/creators/me/assignments/{$assignment->ulid}/drafts/media/complete", [
            'upload_id' => $foreignPath,
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'draft.complete_failed');
});
