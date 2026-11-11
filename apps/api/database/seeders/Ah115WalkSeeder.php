<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorAvailabilityBlockFactory;
use App\Modules\Creators\Database\Factories\CreatorPayoutMethodFactory;
use App\Modules\Creators\Database\Factories\CreatorPortfolioItemFactory;
use App\Modules\Creators\Database\Factories\CreatorSocialAccountFactory;
use App\Modules\Creators\Database\Factories\CreatorTaxProfileFactory;
use App\Modules\Creators\Enums\Kind;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Enums\SocialPlatform;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageSenderRole;
use App\Modules\Messaging\Features\MessagesV2CreatorInboxEnabled;
use App\Modules\Messaging\Features\MessagesV2InboxEnabled;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\RelationshipMessage;
use App\Modules\Messaging\Models\RelationshipThread;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use RuntimeException;

/**
 * AH-115 — local data for the creator walk (web and native build 5).
 *
 *   - ONE creator, "Lena Marques", approved, KYC verified, full profile (bio, contact, two social
 *     accounts, two portfolio links, tax profile, verified payout method).
 *   - TWO agencies ("North", "South"), each with an admin, each rostering Lena, each with a Direct
 *     thread whose last words are the agency's (unread for the creator).
 *   - TWO campaign chats (one per agency), contracted, agency messages unread by the creator.
 *   - ONE invitation (status Invited) from North.
 *   - ONE job post per agency on the board (so the Job posts page has cards).
 *   - ONE availability block (vacation, 5 to 9 days ahead).
 *   - ONE job post per agency on the jobs board (so the Job posts page has cards).
 *   - FIVE notifications (four unread, one read) carrying the same data keys the real senders write
 *     (`assignment_ulid`, `thread_ulid`), so a tap on each lands on its screen.
 *   - Flags `messages_v2_inbox_enabled` and `messages_v2_creator_inbox_enabled` ON, for everyone.
 *
 * Everything is invented and lives under `@ah115-walk.local`; {@see Ah115WalkCleanupSeeder}
 * removes exactly that. Additive: it never touches a row it did not make.
 *
 *     php artisan db:seed --class='Database\Seeders\Ah115WalkSeeder'
 *
 * Local + testing only. Idempotent: a second run only re-asserts the flags.
 */
final class Ah115WalkSeeder extends Seeder
{
    public const string DOMAIN = '@ah115-walk.local';

    public const string PASSWORD = 'password-12chars';

    public const string CREATOR_EMAIL = 'lena'.self::DOMAIN;

    public const string NORTH_SLUG = 'ah115-walk-north';

    public const string SOUTH_SLUG = 'ah115-walk-south';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Ah115WalkSeeder is only allowed in local and testing environments.');
        }

        if (! User::query()->where('email', self::CREATOR_EMAIL)->exists()) {
            DB::transaction(function (): void {
                $this->seed();
            });
        }

        Feature::activate(MessagesV2InboxEnabled::NAME);
        Feature::activate(MessagesV2CreatorInboxEnabled::NAME);
    }

    private function seed(): void
    {
        $user = User::factory()->creator()->create([
            'email' => self::CREATOR_EMAIL,
            'name' => 'Lena Marques',
            'password' => self::PASSWORD,
        ]);
        $creator = Creator::factory()->approved()->kycVerified()->withContact()->create([
            'user_id' => $user->id,
            'display_name' => 'Lena Marques',
            'bio' => 'Lisbon-based lifestyle and travel creator. Short-form video, honest reviews, a loyal audience of 48k.',
            'country_code' => 'PT',
            'region' => 'Lisbon',
            'primary_language' => 'en',
            'secondary_languages' => ['pt'],
            'categories' => ['travel', 'lifestyle'],
            'profile_completeness_score' => 100,
            'tax_profile_complete' => true,
            'payout_method_set' => true,
        ]);

        CreatorSocialAccountFactory::new()->primary()->create([
            'creator_id' => $creator->id, 'handle' => 'lena.marques', 'sync_status' => 'synced',
            'profile_url' => 'https://instagram.com/lena.marques',
        ]);
        CreatorSocialAccountFactory::new()->platform(SocialPlatform::TikTok)->create([
            'creator_id' => $creator->id, 'handle' => 'lenamarques', 'sync_status' => 'synced',
            'profile_url' => 'https://tiktok.com/@lenamarques',
        ]);
        foreach ([1 => 'Lisbon weekend reel', 2 => 'Skincare routine'] as $position => $title) {
            CreatorPortfolioItemFactory::new()->link()->atPosition($position)->create([
                'creator_id' => $creator->id,
                'title' => $title,
                'external_url' => 'https://example.com/lena/'.$position,
            ]);
        }
        CreatorTaxProfileFactory::new()->create([
            'creator_id' => $creator->id, 'legal_name' => 'Lena Marques',
            'tax_id_country' => 'PT', 'tax_id' => 'PT123456789',
            'address' => ['line1' => 'Rua Augusta 10', 'city' => 'Lisbon', 'postal_code' => '1100-048', 'country' => 'PT'],
        ]);
        CreatorPayoutMethodFactory::new()->verified()->create(['creator_id' => $creator->id]);

        CreatorAvailabilityBlockFactory::new()->hard()->create([
            'creator_id' => $creator->id,
            'starts_at' => now()->addDays(5)->startOfDay(),
            'ends_at' => now()->addDays(9)->endOfDay(),
            'is_all_day' => true,
            'kind' => Kind::Vacation,
            'reason' => 'Family trip',
        ]);

        $notes = [];
        foreach ([
            ['North', self::NORTH_SLUG, 'Nora North', 'nora', 'Spring Capsule', 'north-spring'],
            ['South', self::SOUTH_SLUG, 'Sam South', 'sam', 'Summer Launch', 'south-summer'],
        ] as $i => [$label, $slug, $adminName, $adminLocal, $campaignName, $brandSlug]) {
            $agency = AgencyFactory::new()->createOne([
                'name' => "AH-115 Walk Agency {$label}", 'slug' => $slug, 'country_code' => 'PT',
            ]);
            $admin = User::factory()->agencyAdmin($agency)->create([
                'email' => $adminLocal.self::DOMAIN, 'name' => $adminName, 'password' => self::PASSWORD,
            ]);
            $brand = Brand::factory()->forAgency($agency->id)->create([
                'name' => "{$label} Brand", 'slug' => $brandSlug, 'color' => $i === 0 ? 'violet' : 'teal',
            ]);
            $campaign = Campaign::factory()->active()->create([
                'agency_id' => $agency->id, 'brand_id' => $brand->id, 'name' => $campaignName,
                'created_by_user_id' => $admin->id, 'listed_on_jobs_board' => false,
            ]);

            // A job post on the board, so the Job posts page has cards to show.
            Campaign::factory()->active()->listed()->create([
                'agency_id' => $agency->id, 'brand_id' => $brand->id, 'name' => "{$label} Creator Search",
                'created_by_user_id' => $admin->id,
            ]);

            AgencyCreatorRelation::factory()->create([
                'agency_id' => $agency->id, 'creator_id' => $creator->id,
                'relationship_status' => RelationshipStatus::Roster, 'is_blacklisted' => false,
                'invited_by_user_id' => $admin->id, 'owner_user_id' => $admin->id,
            ]);

            // Direct thread: Lena spoke first, the agency has the last (unread) word.
            $direct = RelationshipThread::factory()->forPair($agency, $creator)->create();
            $at = now()->subHours(8 - $i)->startOfMinute();
            foreach ([
                [MessageSenderRole::Creator, 'Hi! Happy to join the roster. Any dates I should block?'],
                [MessageSenderRole::AgencyUser, "Welcome, Lena. We are lining up {$campaignName}; details coming."],
                [MessageSenderRole::AgencyUser, 'Could you send your rate card when you have a minute?'],
            ] as [$role, $body]) {
                $at = $at->copy()->addMinutes(12);
                RelationshipMessage::factory()->create([
                    'thread_id' => $direct->id,
                    'sender_user_id' => $role === MessageSenderRole::Creator ? $user->id : $admin->id,
                    'sender_role' => $role, 'body' => $body, 'created_at' => $at,
                ]);
            }
            $direct->forceFill(['last_message_at' => $at])->save();

            // Campaign chat on a contracted assignment: unread agency messages.
            $assignment = CampaignAssignment::factory()->create([
                'campaign_id' => $campaign->id, 'agency_id' => $agency->id, 'brand_id' => $brand->id,
                'creator_id' => $creator->id, 'status' => AssignmentStatus::Contracted,
                'invited_at' => now()->subDays(4), 'agreed_fee_minor_units' => 250_000 + $i * 100_000,
                'agreed_fee_currency' => 'EUR',
            ]);
            $chat = MessageThread::factory()->forAssignment($assignment)->create();
            $at = now()->subHours(4 - $i)->startOfMinute();
            foreach ([
                [MessageSenderRole::AgencyUser, 'The brief is attached. First draft due next Friday.'],
                [MessageSenderRole::Creator, 'Got it, filming this weekend.'],
                [MessageSenderRole::AgencyUser, 'Great. Please keep the product label visible in the intro.'],
            ] as [$role, $body]) {
                $at = $at->copy()->addMinutes(9);
                Message::factory()->create([
                    'thread_id' => $chat->id,
                    'sender_user_id' => $role === MessageSenderRole::Creator ? $user->id : $admin->id,
                    'sender_role' => $role, 'body' => $body, 'created_at' => $at,
                ]);
            }
            $chat->forceFill(['last_message_at' => $at])->save();

            $notes[] = [$admin, $agency, $campaign, $assignment, $label, $direct];

            if ($i === 0) {
                // The invitation: a second North campaign, offered and waiting.
                $invCampaign = Campaign::factory()->active()->create([
                    'agency_id' => $agency->id, 'brand_id' => $brand->id, 'name' => 'Autumn Edit',
                    'created_by_user_id' => $admin->id, 'listed_on_jobs_board' => false,
                ]);
                $invitation = CampaignAssignment::factory()->create([
                    'campaign_id' => $invCampaign->id, 'agency_id' => $agency->id, 'brand_id' => $brand->id,
                    'creator_id' => $creator->id, 'status' => AssignmentStatus::Invited,
                    'invited_at' => now()->subHours(2), 'agreed_fee_minor_units' => 180_000,
                    'agreed_fee_currency' => 'EUR',
                ]);
                $notes[] = [$admin, $agency, $invCampaign, $invitation, 'invite', $direct];
            }
        }

        // Notifications for Lena (data keys are the ones the templates read).
        foreach ([
            [NotificationType::AssignmentInvited, 'invite', 2, false],
            [NotificationType::MessageReceivedByCreator, 'North', 4, false],
            [NotificationType::MessageReceivedByCreator, 'South', 3, false],
            [NotificationType::MessageRelationshipReceivedByCreator, 'South', 7, false],
            [NotificationType::AssignmentDraftApproved, 'North', 26, true],
        ] as [$type, $key, $hoursAgo, $read]) {
            [$admin, $agency, $campaign, $assignment, , $direct] = collect($notes)->first(fn (array $n): bool => $n[4] === $key);
            $isDirect = $type === NotificationType::MessageRelationshipReceivedByCreator;
            $subject = $isDirect ? $direct : $assignment;
            // The data keys are exactly what the real senders write (SendMessageNotifications,
            // RelationshipMessageNotifications, SendAssignmentNotifications): the tap resolves its
            // destination from `assignment_ulid` / `thread_ulid`, so a row without them goes nowhere.
            $data = $isDirect
                ? [
                    'agency_name' => $agency->name,
                    'creator_name' => $user->name,
                    'sender_name' => $admin->name,
                    'thread_ulid' => $direct->ulid,
                ]
                : [
                    'campaign_name' => $campaign->name,
                    'sender_name' => $admin->name,
                    'creator_name' => $user->name,
                    'assignment_ulid' => $assignment->ulid,
                ];
            Notification::query()->create([
                'recipient_user_id' => $user->id,
                'actor_user_id' => $admin->id,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->id,
                'type' => $type,
                'data' => $data,
                'read_at' => $read ? now()->subHours($hoursAgo - 1) : null,
                'created_at' => now()->subHours($hoursAgo),
            ]);
        }
    }
}
