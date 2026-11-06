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
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageSenderRole;
use App\Modules\Messaging\Features\MessagesV2EditSearchEnabled;
use App\Modules\Messaging\Features\MessagesV2InboxEnabled;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\RelationshipMessage;
use App\Modules\Messaging\Models\RelationshipThread;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use RuntimeException;

/**
 * AH-119 — local data for the Messages v2 walk (device walk W1–W9 and the web walk).
 *
 *   - ONE agency ("AH-119 Walk Agency"): an admin and a manager.
 *   - TWO brands (violet, teal), ONE campaign each.
 *   - THREE creators, each rostered, each with a Direct thread AND one assignment chat, every
 *     thread carrying messages from the other side that nobody has read (so unread badges,
 *     the unread facet and the Mine chip all have something to show). The admin owns two of the
 *     relationships, the manager one.
 *   - Both AH-119 flags ON, for everyone.
 *
 * Everything is invented and every account lives under `@ah119-walk.local`, which is what
 * {@see Ah119WalkCleanupSeeder} removes it by. Additive: it never touches a row it did not make.
 *
 *     php artisan db:seed --class='Database\Seeders\Ah119WalkSeeder'
 *
 * GUARDED to local + testing only, like {@see StoreScreenshotSeeder}. Idempotent: a second run
 * does nothing while the walk agency exists.
 */
final class Ah119WalkSeeder extends Seeder
{
    public const string DOMAIN = '@ah119-walk.local';

    public const string AGENCY_SLUG = 'ah119-walk-agency';

    public const string PASSWORD = 'password-12chars';

    public const string ADMIN_EMAIL = 'admin'.self::DOMAIN;

    public const string MANAGER_EMAIL = 'manager'.self::DOMAIN;

    /** @var list<array{string, string, string, string}> email local part, name, country, owner key */
    private const array CREATORS = [
        ['ana', 'Ana Duarte', 'PT', 'admin'],
        ['bruno', 'Bruno Keller', 'DE', 'admin'],
        ['chiara', 'Chiara Rossi', 'IT', 'manager'],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Ah119WalkSeeder is only allowed in local and testing environments.');
        }

        if (Agency::query()->where('slug', self::AGENCY_SLUG)->exists()) {
            $this->flags();

            return;
        }

        DB::transaction(function (): void {
            $agency = AgencyFactory::new()->createOne([
                'name' => 'AH-119 Walk Agency',
                'slug' => self::AGENCY_SLUG,
                'country_code' => 'PT',
            ]);

            $admin = User::factory()->agencyAdmin($agency)->create([
                'email' => self::ADMIN_EMAIL,
                'name' => 'Mara Admin',
                'password' => self::PASSWORD,
            ]);
            $manager = User::factory()->agencyManager($agency)->create([
                'email' => self::MANAGER_EMAIL,
                'name' => 'Marco Manager',
                'password' => self::PASSWORD,
            ]);

            $brands = [
                Brand::factory()->forAgency($agency->id)->create([
                    'name' => 'Walk Brand Violet', 'slug' => 'walk-violet', 'color' => 'violet',
                ]),
                Brand::factory()->forAgency($agency->id)->create([
                    'name' => 'Walk Brand Teal', 'slug' => 'walk-teal', 'color' => 'teal',
                ]),
            ];
            $campaigns = [
                Campaign::factory()->active()->create([
                    'agency_id' => $agency->id, 'brand_id' => $brands[0]->id,
                    'name' => 'Violet Spring Launch', 'created_by_user_id' => $admin->id,
                    'listed_on_jobs_board' => false,
                ]),
                Campaign::factory()->active()->create([
                    'agency_id' => $agency->id, 'brand_id' => $brands[1]->id,
                    'name' => 'Teal Summer Drop', 'created_by_user_id' => $admin->id,
                    'listed_on_jobs_board' => false,
                ]),
            ];

            foreach (self::CREATORS as $i => [$local, $name, $country, $ownerKey]) {
                $owner = $ownerKey === 'admin' ? $admin : $manager;
                $campaignIndex = $i === 1 ? 1 : 0;
                $this->seedCreator($agency, $owner, $campaigns[$campaignIndex], $local, $name, $country, $i);
            }
        });

        $this->flags();
    }

    private function seedCreator(
        Agency $agency,
        User $owner,
        Campaign $campaign,
        string $local,
        string $name,
        string $country,
        int $index,
    ): void {
        $user = User::factory()->creator()->create([
            'email' => $local.self::DOMAIN,
            'name' => $name,
            'password' => self::PASSWORD,
        ]);
        $creator = Creator::factory()->approved()->kycVerified()->withContact()->create([
            'user_id' => $user->id,
            'display_name' => $name,
            'country_code' => $country,
            'primary_language' => 'en',
        ]);

        AgencyCreatorRelation::factory()->create([
            'agency_id' => $agency->id,
            'creator_id' => $creator->id,
            'relationship_status' => RelationshipStatus::Roster,
            'is_blacklisted' => false,
            'invited_by_user_id' => $owner->id,
            'owner_user_id' => $owner->id,
        ]);

        // Direct thread: the agency opens, the creator answers twice, and the last word is the
        // creator's (unread for the agency); the agency's earlier line is unread for the creator.
        $direct = RelationshipThread::factory()->forPair($agency, $creator)->create();
        $at = now()->subHours(6 + $index)->startOfMinute();
        $directLast = $at;
        foreach ([
            [MessageSenderRole::AgencyUser, "Hi {$name}, welcome. Here is the plan for the month."],
            [MessageSenderRole::Creator, 'Thanks! Can you send the brief as a link?'],
            [MessageSenderRole::Creator, 'Also: is the 15th still the shoot date?'],
        ] as [$role, $body]) {
            $directLast = $at = $at->copy()->addMinutes(11);
            RelationshipMessage::factory()->create([
                'thread_id' => $direct->id,
                'sender_user_id' => $role === MessageSenderRole::Creator ? $user->id : $owner->id,
                'sender_role' => $role,
                'body' => $body,
                'created_at' => $at,
            ]);
        }
        $direct->forceFill(['last_message_at' => $directLast])->save();

        // Assignment chat on the campaign, contracted so it is open; same unread shape.
        $assignment = CampaignAssignment::factory()->create([
            'campaign_id' => $campaign->id,
            'agency_id' => $agency->id,
            'brand_id' => $campaign->brand_id,
            'creator_id' => $creator->id,
            'status' => AssignmentStatus::Contracted,
            'invited_at' => now()->subDays(3),
            'agreed_fee_minor_units' => 300_000 + $index * 50_000,
            'agreed_fee_currency' => 'EUR',
        ]);
        $chat = MessageThread::factory()->forAssignment($assignment)->create();
        $at = now()->subHours(3 + $index)->startOfMinute();
        $chatLast = $at;
        foreach ([
            [MessageSenderRole::AgencyUser, 'The brief is attached. First draft due next Friday.'],
            [MessageSenderRole::Creator, 'Got it. I will start filming this weekend.'],
            [MessageSenderRole::Creator, 'Quick question about the usage rights.'],
        ] as [$role, $body]) {
            $chatLast = $at = $at->copy()->addMinutes(9);
            Message::factory()->create([
                'thread_id' => $chat->id,
                'sender_user_id' => $role === MessageSenderRole::Creator ? $user->id : $owner->id,
                'sender_role' => $role,
                'body' => $body,
                'created_at' => $at,
            ]);
        }
        $chat->forceFill(['last_message_at' => $chatLast])->save();
    }

    private function flags(): void
    {
        Feature::activate(MessagesV2InboxEnabled::NAME);
        Feature::activate(MessagesV2EditSearchEnabled::NAME);
    }
}
