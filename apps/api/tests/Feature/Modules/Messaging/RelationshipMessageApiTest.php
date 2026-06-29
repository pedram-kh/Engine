<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageSenderRole;
use App\Modules\Messaging\Models\RelationshipMessage;
use App\Modules\Messaging\Models\RelationshipThread;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-010a — the agency + creator read/write surfaces onto the 1:1 relationship
 * thread: send, feed, the status-aware messaging GATE (the load-bearing
 * security decision, D2), idempotent one-per-pair provisioning (D3), and the
 * dual-recipient notifications (D5).
 *
 * @return array{agency: Agency, creator: Creator, creatorUser: User, admin: User}
 */
function relationshipSetup(
    RelationshipStatus $status = RelationshipStatus::Roster,
    bool $approved = true,
    bool $blacklisted = false,
): array {
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creatorUser = User::factory()->createOne();
    $creatorFactory = CreatorFactory::new();
    if ($approved) {
        $creatorFactory = $creatorFactory->approved();
    }
    $creator = $creatorFactory->createOne(['user_id' => $creatorUser->id]);

    $relation = AgencyCreatorRelation::factory();
    if ($blacklisted) {
        $relation = $relation->blacklisted('Hard ban');
    }
    $relation->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => $status,
        'is_blacklisted' => $blacklisted,
    ]);

    return compact('agency', 'creator', 'creatorUser', 'admin');
}

function agencyRelUrl(Agency $agency, Creator $creator, string $suffix = ''): string
{
    return "/api/v1/agencies/{$agency->ulid}/creators/{$creator->ulid}/relationship-messages{$suffix}";
}

function creatorRelUrl(Agency $agency, string $suffix = ''): string
{
    return "/api/v1/creators/me/relationship-threads/{$agency->ulid}/messages{$suffix}";
}

// ── Send + feed (both surfaces) ─────────────────────────────────────────────

it('an agency member sends a text message → persisted as agency_user, thread provisioned + stamped', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relationshipSetup();

    $this->actingAs($admin)
        ->postJson(agencyRelUrl($agency, $creator), ['body' => 'Hi — got a moment?'])
        ->assertCreated()
        ->assertJsonPath('data.attributes.body', 'Hi — got a moment?')
        ->assertJsonPath('data.attributes.sender_role', 'agency_user')
        ->assertJsonPath('data.attributes.is_own', true)
        ->assertJsonPath('data.attributes.sender.name', $admin->name);

    $thread = RelationshipThread::withoutGlobalScopes()
        ->where('agency_id', $agency->id)->where('creator_id', $creator->id)->firstOrFail();
    expect($thread->last_message_at)->not->toBeNull()
        ->and(RelationshipMessage::where('thread_id', $thread->id)->count())->toBe(1);
});

it('the creator sends a text message to a connected agency → persisted as creator', function (): void {
    ['agency' => $agency, 'creatorUser' => $creatorUser] = relationshipSetup();

    $this->actingAs($creatorUser)
        ->postJson(creatorRelUrl($agency), ['body' => 'Hey! Sure.'])
        ->assertCreated()
        ->assertJsonPath('data.attributes.sender_role', 'creator');

    expect(RelationshipMessage::query()->where('sender_role', MessageSenderRole::Creator->value)->exists())->toBeTrue();
});

it('both parties read the same chronological feed', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'creatorUser' => $creatorUser, 'admin' => $admin] = relationshipSetup();

    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'first'])->assertCreated();
    $this->actingAs($creatorUser)->postJson(creatorRelUrl($agency), ['body' => 'second'])->assertCreated();

    $agencyFeed = $this->actingAs($admin)->getJson(agencyRelUrl($agency, $creator))->assertOk();
    expect($agencyFeed->json('data.0.attributes.body'))->toBe('first')
        ->and($agencyFeed->json('data.1.attributes.body'))->toBe('second');

    $creatorFeed = $this->actingAs($creatorUser)->getJson(creatorRelUrl($agency))->assertOk();
    expect($creatorFeed->json('data.0.attributes.body'))->toBe('first')
        ->and($creatorFeed->json('data.1.attributes.body'))->toBe('second');
});

// ── Idempotent one-per-pair provisioning (D3) ───────────────────────────────

it('provisions exactly one thread per pair across both initiating sides', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'creatorUser' => $creatorUser, 'admin' => $admin] = relationshipSetup();

    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'a'])->assertCreated();
    $this->actingAs($creatorUser)->postJson(creatorRelUrl($agency), ['body' => 'b'])->assertCreated();
    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'c'])->assertCreated();

    expect(RelationshipThread::withoutGlobalScopes()
        ->where('agency_id', $agency->id)->where('creator_id', $creator->id)->count())->toBe(1);
});

// ── The GATE at the HTTP boundary (D2) — the load-bearing security decision ──

it('BLOCKS an agency send on a DECLINED relation (403) — the spam vector', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relationshipSetup(RelationshipStatus::Declined);

    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'spam?'])->assertForbidden();
    expect(RelationshipThread::withoutGlobalScopes()->count())->toBe(0);
});

it('BLOCKS an agency send on a BLACKLISTED roster relation (403)', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relationshipSetup(RelationshipStatus::Roster, blacklisted: true);

    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'hi'])->assertForbidden();
});

it('BLOCKS an agency send on a PROSPECT relation (403)', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relationshipSetup(RelationshipStatus::Prospect);

    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'hi'])->assertForbidden();
});

it('BLOCKS a send when the creator is NOT approved (403)', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relationshipSetup(RelationshipStatus::Roster, approved: false);

    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'hi'])->assertForbidden();
});

it('BLOCKS the creator send to an agency with no roster relation (403)', function (): void {
    ['agency' => $agency, 'creatorUser' => $creatorUser] = relationshipSetup(RelationshipStatus::Declined);

    $this->actingAs($creatorUser)->postJson(creatorRelUrl($agency), ['body' => 'hi'])->assertForbidden();
});

it('lets a blacklisted relation still READ existing history but not SEND (D6)', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relationshipSetup();

    // Send while clean, then blacklist.
    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'before ban'])->assertCreated();
    AgencyCreatorRelation::query()->where('agency_id', $agency->id)->where('creator_id', $creator->id)
        ->update(['is_blacklisted' => true]);

    // History readable.
    $this->actingAs($admin)->getJson(agencyRelUrl($agency, $creator))
        ->assertOk()
        ->assertJsonPath('data.0.attributes.body', 'before ban');

    // New send blocked.
    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'after ban'])->assertForbidden();
});

// ── Notifications (D5) — dual-recipient, relationship-shaped resolution ──────

it('a creator message notifies the agency members (received_by_agency)', function (): void {
    ['agency' => $agency, 'creatorUser' => $creatorUser, 'admin' => $admin] = relationshipSetup();

    $this->actingAs($creatorUser)->postJson(creatorRelUrl($agency), ['body' => 'ping'])->assertCreated();

    expect(Notification::query()
        ->where('recipient_user_id', $admin->id)
        ->where('type', 'message.relationship_received_by_agency')
        ->exists())->toBeTrue();
});

it('an agency message notifies the creator (received_by_creator)', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'creatorUser' => $creatorUser, 'admin' => $admin] = relationshipSetup();

    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'ping'])->assertCreated();

    expect(Notification::query()
        ->where('recipient_user_id', $creatorUser->id)
        ->where('type', 'message.relationship_received_by_creator')
        ->exists())->toBeTrue();
});

it('writes NO audit row on message send (the message.* verbs are inert vocabulary, not a DM content/metadata trail)', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'creatorUser' => $creatorUser, 'admin' => $admin] = relationshipSetup();

    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'private — for your eyes'])->assertCreated();
    $this->actingAs($creatorUser)->postJson(creatorRelUrl($agency), ['body' => 'and back'])->assertCreated();

    // The AuditAction message.* verbs exist ONLY to satisfy the NotificationType
    // one-vocabulary tie — NO audit_logs row is ever written on a message send,
    // so a private DM leaves no content AND no metadata trail in the audit log.
    expect(AuditLog::query()->where('action', 'like', 'message.%')->count())->toBe(0);
});

// ── Inbox roll-ups (D8) — symmetric both sides (Q5) ─────────────────────────

it('the agency inbox lists the thread with the creator + last-message preview', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relationshipSetup();

    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'newest'])->assertCreated();

    $this->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/relationship-threads")
        ->assertOk()
        ->assertJsonPath('data.0.type', 'relationship_thread')
        ->assertJsonPath('data.0.attributes.creator.id', $creator->ulid)
        ->assertJsonPath('data.0.attributes.last_message_preview', 'newest');
});

it('the creator inbox lists the thread with the agency + unread count', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'creatorUser' => $creatorUser, 'admin' => $admin] = relationshipSetup();

    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'hello there'])->assertCreated();

    $this->actingAs($creatorUser)->getJson('/api/v1/creators/me/relationship-threads')
        ->assertOk()
        ->assertJsonPath('data.0.attributes.agency.id', $agency->ulid)
        ->assertJsonPath('data.0.attributes.agency.name', $agency->name)
        ->assertJsonPath('data.0.attributes.unread_count', 1)
        ->assertJsonPath('data.0.attributes.last_message_preview', 'hello there');
});

// ── Read indicator (D10) — two-state, counterparty-aware ────────────────────

it('exposes read_by_counterparty on own messages: false until the creator reads, then true', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'creatorUser' => $creatorUser, 'admin' => $admin] = relationshipSetup();

    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'did you see this?'])->assertCreated();

    // Before the creator reads: the agency's own message is unread by the counterparty.
    $this->actingAs($admin)->getJson(agencyRelUrl($agency, $creator))
        ->assertOk()
        ->assertJsonPath('data.0.attributes.is_own', true)
        ->assertJsonPath('data.0.attributes.read_by_counterparty', false);

    // The creator opens the thread and marks it read (what the 15s poll does).
    $this->actingAs($creatorUser)->getJson(creatorRelUrl($agency))->assertOk();
    $this->actingAs($creatorUser)->postJson(creatorRelUrl($agency, '/read'))->assertOk();

    // Now the agency sees its own message as read by the counterparty.
    $this->actingAs($admin)->getJson(agencyRelUrl($agency, $creator))
        ->assertOk()
        ->assertJsonPath('data.0.attributes.read_by_counterparty', true);
});

it('returns read_by_counterparty=null on the other party\'s incoming messages (no tick on incoming)', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relationshipSetup();

    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'from agency'])->assertCreated();

    // The creator views the agency's message as INCOMING → no read tick.
    $this->actingAs($creator->user)->getJson(creatorRelUrl($agency))
        ->assertOk()
        ->assertJsonPath('data.0.attributes.is_own', false)
        ->assertJsonPath('data.0.attributes.read_by_counterparty', null);
});

// ── Unread / mark-read (D10) ─────────────────────────────────────────────────

it('mark-read resolves the unread count for the viewer', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'creatorUser' => $creatorUser, 'admin' => $admin] = relationshipSetup();

    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'unread me'])->assertCreated();

    // The creator sees one unread before reading.
    $this->actingAs($creatorUser)->getJson(creatorRelUrl($agency))
        ->assertOk()
        ->assertJsonPath('meta.thread.unread_count', 1);

    $this->actingAs($creatorUser)->postJson(creatorRelUrl($agency, '/read'))
        ->assertOk()
        ->assertJsonPath('meta.unread_count', 0);

    $this->actingAs($creatorUser)->getJson(creatorRelUrl($agency))
        ->assertJsonPath('meta.thread.unread_count', 0);
});

// ── Provisioning is lazy on first send / first attachment-upload (AH-012 D1) ─
// Opening a conversation must NOT persist a thread row; the row materializes
// only when a message is sent (or an attachment is uploaded — intent). These
// pin the corrected behavior the old "lazy on first send" docblocks claimed but
// the code did not honor (it provisioned on read).

it('a gate-passing agency GET with no prior thread does NOT persist a row (transient feed)', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relationshipSetup();

    $this->actingAs($admin)->getJson(agencyRelUrl($agency, $creator))
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonPath('meta.thread.unread_count', 0)
        ->assertJsonPath('meta.thread.last_message_at', null);

    expect(RelationshipThread::withoutGlobalScopes()->count())->toBe(0);
});

it('a gate-passing creator GET with no prior thread does NOT persist a row (transient feed)', function (): void {
    ['agency' => $agency, 'creatorUser' => $creatorUser] = relationshipSetup();

    $this->actingAs($creatorUser)->getJson(creatorRelUrl($agency))
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonPath('meta.thread.unread_count', 0)
        ->assertJsonPath('meta.thread.last_message_at', null);

    expect(RelationshipThread::withoutGlobalScopes()->count())->toBe(0);
});

it('mark-read on a not-yet-existing thread does NOT persist a row', function (): void {
    ['agency' => $agency, 'creatorUser' => $creatorUser] = relationshipSetup();

    $this->actingAs($creatorUser)->postJson(creatorRelUrl($agency, '/read'))
        ->assertOk()
        ->assertJsonPath('meta.unread_count', 0);

    expect(RelationshipThread::withoutGlobalScopes()->count())->toBe(0);
});

it('open-then-send provisions exactly one thread (the row materializes on send)', function (): void {
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relationshipSetup();

    // Open (no row yet).
    $this->actingAs($admin)->getJson(agencyRelUrl($agency, $creator))->assertOk();
    expect(RelationshipThread::withoutGlobalScopes()->count())->toBe(0);

    // Send (row materializes).
    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'now we begin'])->assertCreated();
    expect(RelationshipThread::withoutGlobalScopes()->count())->toBe(1);
});

it('an attachment-upload provisions the thread (intent), even before the send lands', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relationshipSetup();

    // No thread on open…
    $this->actingAs($admin)->getJson(agencyRelUrl($agency, $creator))->assertOk();
    expect(RelationshipThread::withoutGlobalScopes()->count())->toBe(0);

    // …but uploading an attachment is intent → provisions (Q1).
    $this->actingAs($admin)
        ->postJson(agencyRelUrl($agency, $creator, '/attachments/init'), ['mime_type' => 'application/pdf', 'size_bytes' => 1024])
        ->assertOk();

    expect(RelationshipThread::withoutGlobalScopes()->count())->toBe(1);
});

// ── Inbox shows only threads with ≥1 message (AH-012 D2) ─────────────────────

it('the agency inbox HIDES a provisioned-but-empty thread (no ghosts)', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'creator' => $creator, 'admin' => $admin] = relationshipSetup();

    // Provision an empty thread via an attachment-upload (no message sent).
    $this->actingAs($admin)
        ->postJson(agencyRelUrl($agency, $creator, '/attachments/init'), ['mime_type' => 'application/pdf', 'size_bytes' => 1024])
        ->assertOk();
    expect(RelationshipThread::withoutGlobalScopes()->count())->toBe(1);

    // The empty thread is invisible in the inbox until a message exists.
    $this->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/relationship-threads")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // After a send, it appears.
    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'hello'])->assertCreated();
    $this->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/relationship-threads")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('the creator inbox HIDES a provisioned-but-empty thread (no ghosts)', function (): void {
    Storage::fake('media');
    ['agency' => $agency, 'creator' => $creator, 'creatorUser' => $creatorUser, 'admin' => $admin] = relationshipSetup();

    // Provision empty via the agency attachment-upload path.
    $this->actingAs($admin)
        ->postJson(agencyRelUrl($agency, $creator, '/attachments/init'), ['mime_type' => 'application/pdf', 'size_bytes' => 1024])
        ->assertOk();

    $this->actingAs($creatorUser)->getJson('/api/v1/creators/me/relationship-threads')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($admin)->postJson(agencyRelUrl($agency, $creator), ['body' => 'hi'])->assertCreated();
    $this->actingAs($creatorUser)->getJson('/api/v1/creators/me/relationship-threads')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
