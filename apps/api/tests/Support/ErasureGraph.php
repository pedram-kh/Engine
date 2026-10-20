<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignJobNotification;
use App\Modules\Creators\Enums\SocialPlatform;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorAvailabilityBlock;
use App\Modules\Creators\Models\CreatorKycVerification;
use App\Modules\Creators\Models\CreatorPayoutMethod;
use App\Modules\Creators\Models\CreatorPortfolioItem;
use App\Modules\Creators\Models\CreatorSocialAccount;
use App\Modules\Creators\Models\CreatorTaxProfile;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageEmailDebounce;
use App\Modules\Messaging\Models\MessageReadReceipt;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\RelationshipMessage;
use App\Modules\Messaging\Models\RelationshipMessageReadReceipt;
use App\Modules\Messaging\Models\RelationshipThread;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\PushDelivery;
use App\Modules\Notifications\Models\UserPushToken;
use App\Modules\TalentPools\Models\TalentPoolMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A seeded graph for the erasure specs: one creator ("subject") and everything
 * the PII map says erasure reaches, built with SYMMETRIC bystanders.
 *
 * `seedSubject()` is called once per creator with identical shape, so every
 * selection should count exactly the same number of rows for each. A selection
 * that leaks across creators doubles its count; one that misses halves it. That
 * is a sharper negative than a hand-written "this other row is not touched".
 *
 * The agency member who sent or received rows about the creator is shared
 * context, created per call because each subject lives in its own agency.
 */
final class ErasureGraph
{
    public function __construct(
        public readonly User $user,
        public readonly Creator $creator,
        public readonly string $email,
        public readonly string $kycSessionId,
        public readonly User $agencyMember,
    ) {}

    /**
     * Rows the shape below creates for ONE subject, per selection id. A zero is
     * deliberate (a row that exists but must NOT be selected: already erased,
     * or not the subject's).
     *
     * @return array<string, int>
     */
    public static function expectedCounts(): array
    {
        $counts = [
            'users' => 1,
            'creators' => 1,
            'sessions' => 2,
            'personal_access_tokens' => 2,
            'password_reset_tokens' => 1,
            'user_push_tokens' => 2,
            'push_deliveries' => 2,
            'user_notification_preferences' => 2,
            'features' => 1,
            'failed_jobs' => 2,
            'creator_tax_profiles' => 1,
            'creator_payout_methods' => 1,
            'creator_kyc_verifications' => 1,
            'creator_social_accounts' => 2,
            'creator_portfolio_items' => 2,
            'creator_availability_blocks' => 2,
            'talent_pool_creators' => 1,
            'campaign_job_notifications' => 1,
            'message_read_receipts' => 1,
            'relationship_message_read_receipts' => 1,
            'message_email_debounces' => 1,
            'agency_creator_relations.invitation' => 1,
            'campaign_applications.note' => 1,
            'messages.attachments' => 1,
            'relationship_messages.attachments' => 1,
            'notifications.own_feed' => 2,
            'notifications.actor' => 1,
            'notifications.subject' => 2,
            'tracked_jobs.failures' => 1,
            'integration_events.payload' => 1,
            'audit_logs.S1' => 2,
            'audit_logs.S2' => 1,
            'audit_logs.S3' => 1,
            // S4 = tax profile 1 + payout method 1 + two relations (each emitted a created row via the Audited trait).
            'audit_logs.S4' => 4,
            'audit_logs.S5' => 1,
            // S1 2 + S2 1 + S3 1 + S4 4 + S5 1, and no row matches two predicates.
            'audit_logs.union' => 9,
        ];
        ksort($counts);

        return $counts;
    }

    public static function seedSubject(string $tag): self
    {
        $email = "subject-{$tag}@example.test";
        $user = User::factory()->creator()->createOne(['email' => $email, 'name' => "Subject {$tag}"]);
        $creator = Creator::factory()->createOne(['user_id' => $user->id, 'display_name' => "Display {$tag}", 'phone' => '+39 333 000 '.str_pad((string) (crc32($tag) % 10000), 4, '0', STR_PAD_LEFT)]);
        $agencyMember = User::factory()->agencyAdmin()->createOne();

        $campaign = Campaign::factory()->createOne();
        $kycSessionId = "kyc-session-{$tag}-".bin2hex(random_bytes(4));

        // 4a — identity and tokens.
        $tokens = [$user->createToken('phone'), $user->createToken('tablet')];
        for ($i = 0; $i < 2; $i++) {
            DB::table('sessions')->insert(['id' => "sess-{$tag}-{$i}", 'user_id' => $user->id, 'ip_address' => '203.0.113.9', 'user_agent' => 'phpunit', 'payload' => 'x', 'last_activity' => time()]);
        }
        DB::table('password_reset_tokens')->insert(['email' => strtoupper($email), 'token' => 'hashed', 'created_at' => now()]);
        foreach ($tokens as $token) {
            UserPushToken::factory()->createOne(['user_id' => $user->id, 'personal_access_token_id' => $token->accessToken->id]);
        }
        PushDelivery::factory()->count(2)->create(['user_id' => $user->id]);
        NotificationPreference::factory()->createOne(['user_id' => $user->id, 'channel' => 'in_app']);
        NotificationPreference::factory()->createOne(['user_id' => $user->id, 'channel' => 'push']);
        DB::table('features')->insert(['name' => 'some-flag', 'scope' => $user->getMorphClass().'|'.$user->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()]);
        self::failedJob("payload carries {$email} in a queued mail", 'ok');
        self::failedJob('ok', "Exception: could not deliver to {$email}.");
        self::failedJob("payload for not{$email} (a different person)", 'ok');

        // 4b — satellites.
        CreatorTaxProfile::factory()->createOne(['creator_id' => $creator->id]);
        CreatorPayoutMethod::factory()->createOne(['creator_id' => $creator->id]);
        CreatorKycVerification::factory()->createOne(['creator_id' => $creator->id, 'provider_session_id' => $kycSessionId]);
        CreatorSocialAccount::factory()->createOne(['creator_id' => $creator->id, 'platform' => SocialPlatform::Instagram]);
        CreatorSocialAccount::factory()->createOne(['creator_id' => $creator->id, 'platform' => SocialPlatform::TikTok])->delete();
        CreatorPortfolioItem::factory()->createOne(['creator_id' => $creator->id]);
        CreatorPortfolioItem::factory()->createOne(['creator_id' => $creator->id])->delete();
        CreatorAvailabilityBlock::factory()->count(2)->create(['creator_id' => $creator->id]);
        TalentPoolMembership::factory()->createOne(['creator_id' => $creator->id]);
        CampaignJobNotification::factory()->createOne(['creator_id' => $creator->id]);

        $assignment = CampaignAssignment::factory()->createOne(['campaign_id' => $campaign->id, 'creator_id' => $creator->id]);
        $thread = MessageThread::factory()->createOne(['assignment_id' => $assignment->id]);
        $ownMessage = Message::factory()->fromCreator()->createOne(['thread_id' => $thread->id, 'sender_user_id' => $user->id]);
        MessageReadReceipt::factory()->createOne(['message_id' => $ownMessage->id, 'user_id' => $user->id]);
        MessageEmailDebounce::factory()->createOne(['thread_id' => $thread->id, 'recipient_user_id' => $user->id]);

        $relationshipThread = RelationshipThread::factory()->createOne(['creator_id' => $creator->id]);
        $ownRelationshipMessage = RelationshipMessage::factory()->fromCreator()->createOne(['thread_id' => $relationshipThread->id, 'sender_user_id' => $user->id]);
        RelationshipMessageReadReceipt::factory()->createOne(['message_id' => $ownRelationshipMessage->id, 'user_id' => $user->id]);

        // 4c — rows an agency holds.
        $relation = AgencyCreatorRelation::factory()->prospect("token-{$tag}")->createOne(['creator_id' => $creator->id]);
        AgencyCreatorRelation::factory()->pendingRequest()->createOne(['creator_id' => $creator->id]);
        CampaignApplication::factory()->withNote("pitch {$tag}")->createOne(['creator_id' => $creator->id, 'campaign_id' => $campaign->id]);
        CampaignApplication::factory()->createOne(['creator_id' => $creator->id]);
        $attachment = [['path' => "messages/{$tag}/file.pdf", 'name' => 'file.pdf']];
        $ownMessage->forceFill(['attachments' => $attachment])->save();
        Message::factory()->createOne(['thread_id' => $thread->id, 'sender_user_id' => $user->id]);
        Message::factory()->attachmentOnly($attachment)->createOne(['thread_id' => $thread->id, 'sender_user_id' => $agencyMember->id]);
        $ownRelationshipMessage->forceFill(['attachments' => $attachment])->save();
        RelationshipMessage::factory()->createOne(['thread_id' => $relationshipThread->id, 'sender_user_id' => $user->id]);
        RelationshipMessage::factory()->attachmentOnly($attachment)->createOne(['thread_id' => $relationshipThread->id, 'sender_user_id' => $agencyMember->id]);

        // 4d — JSON scrubs.
        Notification::factory()->count(2)->create(['recipient_user_id' => $user->id, 'data' => ['agency_name' => 'Acme']]);
        Notification::factory()->ofType(NotificationType::AssignmentDraftApproved)->createOne([
            'recipient_user_id' => $agencyMember->id, 'actor_user_id' => $user->id,
            'subject_type' => $thread->getMorphClass(), 'subject_id' => $thread->id,
            'data' => ['sender_name' => "Subject {$tag}", 'campaign_name' => 'C'],
        ]);
        Notification::factory()->createOne([
            'recipient_user_id' => $agencyMember->id, 'actor_user_id' => $user->id, 'data' => ['campaign_name' => 'no name key here'],
        ]);
        Notification::factory()->createOne([
            'recipient_user_id' => $agencyMember->id, 'actor_user_id' => null,
            'subject_type' => $assignment->getMorphClass(), 'subject_id' => $assignment->id,
            'data' => ['creator_name' => "Display {$tag}", 'campaign_name' => 'C'],
        ]);
        Notification::factory()->createOne([
            'recipient_user_id' => $agencyMember->id, 'actor_user_id' => null,
            'subject_type' => $relation->getMorphClass(), 'subject_id' => $relation->id,
            'data' => ['counterparty_name' => "Display {$tag}"],
        ]);
        DB::table('tracked_jobs')->insert([
            'ulid' => (string) Str::ulid(), 'kind' => 'bulk_creator_invitation', 'status' => 'completed', 'progress' => 1,
            'result' => json_encode(['failures' => [['row' => 2, 'email' => $email, 'reason' => 'x'], ['row' => 3, 'email' => "other-{$tag}@example.test", 'reason' => 'y']]], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('integration_events')->insert([
            ['provider' => 'mock', 'provider_event_id' => "evt-{$tag}-1", 'event_type' => 'kyc.decision', 'payload' => json_encode(['session' => $kycSessionId, 'result' => 'approved'], JSON_THROW_ON_ERROR), 'received_at' => now()],
            ['provider' => 'mock', 'provider_event_id' => "evt-{$tag}-2", 'event_type' => 'kyc.decision', 'payload' => json_encode(['erased' => true], JSON_THROW_ON_ERROR), 'received_at' => now()],
            ['provider' => 'mock', 'provider_event_id' => "evt-{$tag}-3", 'event_type' => 'kyc.decision', 'payload' => json_encode(['session' => 'unrelated-session-id-1234'], JSON_THROW_ON_ERROR), 'received_at' => now()],
        ]);

        // 4e — audit rows (S1 x2 here; S2, S3 and S4 arrive from the model trait on create).
        AuditLog::factory()->count(2)->create(['actor_type' => 'user', 'actor_id' => $user->id, 'action' => AuditAction::AuthLoginSucceeded]);
        AuditLog::factory()->createOne(['actor_type' => 'system', 'actor_id' => null, 'action' => AuditAction::AuthLoginFailed, 'metadata' => ['email' => $email, 'reason' => 'unknown_email']]);

        return new self($user, $creator, $email, $kycSessionId, $agencyMember);
    }

    private static function failedJob(string $payload, string $exception): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'sync', 'queue' => 'default',
            'payload' => $payload, 'exception' => $exception, 'failed_at' => now(),
        ]);
    }
}
