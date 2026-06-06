<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageSenderRole;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 11 (S3) — the agency + creator read/write surfaces onto a thread:
 * send (terminal-guarded), feed, mark-read (idempotent), unread counts, and the
 * tenancy isolation contract (D-16): cross-agency + creator-self absence
 * (404-not-403).
 *
 * @return array{agency: Agency, campaign: Campaign, assignment: CampaignAssignment, creatorUser: User, admin: User}
 */
function messagingSetup(AssignmentStatus $status = AssignmentStatus::Contracted): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creatorUser = User::factory()->createOne();
    $creator = CreatorFactory::new()->createOne(['user_id' => $creatorUser->id]);

    $assignment = CampaignAssignment::factory()->status($status)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
        'invited_by_user_id' => $admin->id,
    ]);

    return compact('agency', 'campaign', 'assignment', 'creatorUser', 'admin');
}

function agencyMessagesUrl(Agency $agency, Campaign $campaign, CampaignAssignment $assignment, string $suffix = ''): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/messages{$suffix}";
}

function creatorMessagesUrl(CampaignAssignment $assignment, string $suffix = ''): string
{
    return "/api/v1/creators/me/assignments/{$assignment->ulid}/messages{$suffix}";
}

// ── Send + feed (both surfaces) ────────────────────────────────────────────

it('an agency user sends a text message → persisted as agency_user, thread stamped', function (): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = messagingSetup();

    $this->actingAs($admin)
        ->postJson(agencyMessagesUrl($agency, $campaign, $assignment), ['body' => 'Hi, welcome aboard!'])
        ->assertCreated()
        ->assertJsonPath('data.attributes.body', 'Hi, welcome aboard!')
        ->assertJsonPath('data.attributes.sender_role', 'agency_user')
        ->assertJsonPath('data.attributes.is_own', true);

    $thread = MessageThread::withoutGlobalScopes()->where('assignment_id', $assignment->id)->firstOrFail();
    expect($thread->last_message_at)->not->toBeNull()
        ->and(Message::where('thread_id', $thread->id)->count())->toBe(1);
});

it('the creator sends a text message on their own assignment → persisted as creator', function (): void {
    ['assignment' => $assignment, 'creatorUser' => $creatorUser] = messagingSetup();

    $this->actingAs($creatorUser)
        ->postJson(creatorMessagesUrl($assignment), ['body' => 'Thanks! Excited to start.'])
        ->assertCreated()
        ->assertJsonPath('data.attributes.sender_role', 'creator');

    expect(Message::query()->where('sender_role', MessageSenderRole::Creator->value)->exists())->toBeTrue();
});

it('both parties read the same chronological feed', function (): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin, 'creatorUser' => $creatorUser] = messagingSetup();

    $this->actingAs($admin)->postJson(agencyMessagesUrl($agency, $campaign, $assignment), ['body' => 'first'])->assertCreated();
    $this->actingAs($creatorUser)->postJson(creatorMessagesUrl($assignment), ['body' => 'second'])->assertCreated();

    $this->actingAs($creatorUser)->getJson(creatorMessagesUrl($assignment))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.attributes.body', 'first')
        ->assertJsonPath('data.1.attributes.body', 'second');
});

it('an empty send is rejected 422 (body required_without attachments)', function (): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = messagingSetup();

    $this->actingAs($admin)
        ->postJson(agencyMessagesUrl($agency, $campaign, $assignment), [])
        ->assertStatus(422);
});

// ── Unread + read receipts (idempotent) ────────────────────────────────────

it('unread counts are per-viewer; own sends never count; mark-read clears them idempotently', function (): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin, 'creatorUser' => $creatorUser] = messagingSetup();

    $this->actingAs($admin)->postJson(agencyMessagesUrl($agency, $campaign, $assignment), ['body' => 'a1'])->assertCreated();
    $this->actingAs($admin)->postJson(agencyMessagesUrl($agency, $campaign, $assignment), ['body' => 'a2'])->assertCreated();
    $this->actingAs($creatorUser)->postJson(creatorMessagesUrl($assignment), ['body' => 'c1'])->assertCreated();

    // The creator sees the agency's 2 as unread (their own c1 does not count).
    $this->actingAs($creatorUser)->getJson(creatorMessagesUrl($assignment))
        ->assertOk()
        ->assertJsonPath('meta.thread.unread_count', 2);

    // The agency admin sees the creator's 1 as unread (their own a1/a2 do not count).
    $this->actingAs($admin)->getJson(agencyMessagesUrl($agency, $campaign, $assignment))
        ->assertOk()
        ->assertJsonPath('meta.thread.unread_count', 1);

    // Creator marks read → 2 newly marked; a re-read is a no-op (idempotent).
    $this->actingAs($creatorUser)->postJson(creatorMessagesUrl($assignment, '/read'))
        ->assertOk()
        ->assertJsonPath('meta.marked', 2);
    $this->actingAs($creatorUser)->postJson(creatorMessagesUrl($assignment, '/read'))
        ->assertOk()
        ->assertJsonPath('meta.marked', 0);

    $this->actingAs($creatorUser)->getJson(creatorMessagesUrl($assignment))
        ->assertJsonPath('meta.thread.unread_count', 0);
});

// ── Terminal write-guard (D-13 + Q2) ───────────────────────────────────────

it('human send is 422 on a declined assignment, but reads still work', function (): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = messagingSetup(AssignmentStatus::Declined);

    $this->actingAs($admin)
        ->postJson(agencyMessagesUrl($agency, $campaign, $assignment), ['body' => 'still there?'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'message.thread_closed');

    $this->actingAs($admin)->getJson(agencyMessagesUrl($agency, $campaign, $assignment))
        ->assertOk()
        ->assertJsonPath('meta.thread.human_send_blocked', true);
});

it('human send STAYS OPEN after payment_released for post-delivery wrap-up (Q2)', function (): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin] = messagingSetup(AssignmentStatus::PaymentReleased);

    $this->actingAs($admin)
        ->postJson(agencyMessagesUrl($agency, $campaign, $assignment), ['body' => 'Here are the final raw files.'])
        ->assertCreated();
});

// ── Tenancy isolation (D-16) — absence, 404-not-403 ────────────────────────

it("agency B cannot see agency A's thread (404, not 403)", function (): void {
    ['agency' => $agencyA, 'campaign' => $campaign, 'assignment' => $assignment] = messagingSetup();

    $agencyB = Agency::factory()->createOne();
    $outsider = User::factory()->agencyAdmin($agencyB)->createOne();

    $this->actingAs($outsider)
        ->getJson(agencyMessagesUrl($agencyA, $campaign, $assignment))
        ->assertNotFound();
});

it("creator Y cannot read creator X's thread (404, not 403)", function (): void {
    ['assignment' => $assignmentX] = messagingSetup();

    $otherUser = User::factory()->createOne();
    CreatorFactory::new()->createOne(['user_id' => $otherUser->id]);

    $this->actingAs($otherUser)
        ->getJson(creatorMessagesUrl($assignmentX))
        ->assertNotFound()
        ->assertJsonPath('errors.0.code', 'assignment.not_found');
});

// ── Rollup (Messages tab) ──────────────────────────────────────────────────

it('the agency rollup lists campaign threads with unread counts', function (): void {
    ['agency' => $agency, 'campaign' => $campaign, 'assignment' => $assignment, 'admin' => $admin, 'creatorUser' => $creatorUser] = messagingSetup();

    $this->actingAs($creatorUser)->postJson(creatorMessagesUrl($assignment), ['body' => 'hello agency'])->assertCreated();

    $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/message-threads")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.unread_count', 1)
        ->assertJsonPath('data.0.attributes.last_message_preview', 'hello agency');
});
