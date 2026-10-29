<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorPortfolioItemFactory;
use App\Modules\Creators\Database\Factories\CreatorSocialAccountFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Enums\SocialPlatform;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageSenderRole;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\RelationshipMessage;
use App\Modules\Messaging\Models\RelationshipThread;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * AH-105 S5 — the fictional dataset the App Store screenshots are captured from.
 *
 * ALL of it is invented: a made-up creator, a made-up agency, four made-up brands. No real
 * person, brand, handle or face. Avatars, portfolio images and brand marks are generated
 * gradients (GD) written to the local `media` disk (MinIO), never downloaded or photographed.
 *
 *   - ONE approved creator (the profile is complete except the master contract, so the
 *     dashboard shows a strength below 100%), with 4 portfolio items and two social accounts.
 *   - ONE demo agency with an admin, rostered with that creator.
 *   - FOUR listed jobs (the jobs board).
 *   - THREE campaign invitations in different states: invited, countered, contracted.
 *   - A relationship thread (about six messages) and a campaign thread (on the contracted one).
 *   - EIGHT notifications for the creator, a mix of read and unread.
 *
 * NOT called by DatabaseSeeder; run it explicitly:
 *
 *     php artisan db:seed --class='Database\Seeders\StoreScreenshotSeeder'
 *
 * GUARDED to local + testing only, exactly like {@see Sprint1IdentitySeeder}. A stray run in
 * production would put a known-credential creator and a fake agency next to real data, so it
 * refuses. The PRODUCTION demo account (the App Review account) is built by hand from
 * docs/store/demo-account-runbook.md, not by this class.
 *
 * Idempotent in the cheap way: if the demo creator already exists it does nothing.
 * No new table, so the erasure registry is untouched.
 */
final class StoreScreenshotSeeder extends Seeder
{
    public const string CREATOR_EMAIL = 'alessia.demo@store-screenshots.local';

    public const string AGENCY_EMAIL = 'agency.demo@store-screenshots.local';

    public const string PASSWORD = 'password-12chars';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'StoreScreenshotSeeder is only allowed in local and testing environments. '.
                'The production demo account is built by hand: see docs/store/demo-account-runbook.md.',
            );
        }

        if (User::query()->where('email', self::CREATOR_EMAIL)->exists()) {
            return;
        }

        DB::transaction(function (): void {
            $agency = AgencyFactory::new()->createOne([
                'name' => 'Studio Lumora',
                'slug' => 'studio-lumora-demo',
                'country_code' => 'PT',
            ]);

            $agencyAdmin = User::factory()->agencyAdmin($agency)->create([
                'email' => self::AGENCY_EMAIL,
                'name' => 'Marta Rocha',
            ]);

            $creator = $this->seedCreator();

            AgencyCreatorRelation::factory()->create([
                'agency_id' => $agency->id,
                'creator_id' => $creator->id,
                'relationship_status' => RelationshipStatus::Roster,
                'is_blacklisted' => false,
            ]);

            $brands = $this->seedBrands($agency);

            $this->seedListedJobs($agency, $brands);
            $contracted = $this->seedInvitations($agency, $brands, $creator, $agencyAdmin);

            $this->seedRelationshipThread($agency, $creator, $agencyAdmin);
            $this->seedCampaignThread($contracted, $creator, $agencyAdmin);
            $this->seedNotifications($creator, $agencyAdmin, $contracted);

            // The score is a stored column; derive it from the real calculator so the number
            // on the dashboard is the one the product would compute, not one typed here.
            $creator->forceFill([
                'profile_completeness_score' => app(CompletenessScoreCalculator::class)->score($creator->refresh()),
            ])->save();
        });
    }

    private function seedCreator(): Creator
    {
        $user = User::factory()->creator()->create([
            'email' => self::CREATOR_EMAIL,
            'name' => 'Alessia Moretti',
        ]);

        $creator = Creator::factory()->approved()->kycVerified()->withContact()->create([
            'user_id' => $user->id,
            'display_name' => 'Alessia Moretti',
            'bio' => 'Lifestyle and travel creator based in Milan. Slow mornings, small cafes, long train rides.',
            'country_code' => 'IT',
            'region' => 'Lombardy',
            'date_of_birth' => '1996-04-12',
            'primary_language' => 'en',
            'secondary_languages' => ['it'],
            'categories' => ['lifestyle', 'travel'],
            'tax_profile_complete' => true,
            'payout_method_set' => true,
            // The master contract is deliberately NOT accepted: the strength card then shows a
            // next step instead of 100%.
            'signed_master_contract_id' => null,
        ]);

        $avatarPath = sprintf('creators/%s/avatar/demo.png', $creator->ulid);
        Storage::disk('media')->put($avatarPath, $this->avatarPng([232, 164, 124], [196, 94, 118]));
        $creator->forceFill(['avatar_path' => $avatarPath])->save();

        $palettes = [
            [[246, 196, 132], [214, 120, 98]],
            [[132, 190, 214], [70, 110, 168]],
            [[170, 214, 160], [72, 140, 120]],
            [[214, 170, 220], [128, 92, 176]],
        ];
        foreach ($palettes as $index => [$from, $to]) {
            $path = sprintf('creators/%s/portfolio/demo-%d.png', $creator->ulid, $index + 1);
            Storage::disk('media')->put($path, $this->gradientPng(900, 1200, $from, $to));

            CreatorPortfolioItemFactory::new()->create([
                'creator_id' => $creator->id,
                'title' => ['Milan mornings', 'Lake weekend', 'Market haul', 'Night train'][$index],
                'description' => null,
                's3_path' => $path,
                'thumbnail_path' => $path,
                'mime_type' => 'image/png',
                'size_bytes' => strlen((string) Storage::disk('media')->get($path)),
                'position' => $index + 1,
            ]);
        }

        CreatorSocialAccountFactory::new()->create([
            'creator_id' => $creator->id,
            'platform' => SocialPlatform::Instagram,
            'handle' => 'alessia.demo',
            'profile_url' => 'https://instagram.com/alessia.demo',
            'platform_user_id' => '10000001',
            'is_primary' => true,
        ]);
        CreatorSocialAccountFactory::new()->create([
            'creator_id' => $creator->id,
            'platform' => SocialPlatform::TikTok,
            'handle' => 'alessia.demo',
            'profile_url' => 'https://tiktok.com/@alessia.demo',
            'platform_user_id' => '10000002',
        ]);

        return $creator;
    }

    /**
     * @return array<string, Brand>
     */
    private function seedBrands(Agency $agency): array
    {
        $definitions = [
            'lumora' => ['Lumora Skincare', 'beauty', [244, 190, 170], [190, 110, 130]],
            'brisa' => ['Brisa Coffee Co.', 'food', [210, 160, 110], [120, 78, 56]],
            'nordlys' => ['Nordlys Outdoor', 'outdoor', [130, 190, 200], [48, 96, 130]],
            'verdant' => ['Verdant Table', 'food', [170, 210, 150], [70, 130, 96]],
        ];

        $brands = [];
        foreach ($definitions as $key => [$name, $industry, $from, $to]) {
            $slug = $key.'-demo';
            $logoPath = sprintf('agencies/%s/brands/%s/logo/demo.png', $agency->ulid, $slug);
            Storage::disk('media')->put($logoPath, $this->brandMarkPng($from, $to));

            $brands[$key] = Brand::factory()->forAgency($agency->id)->create([
                'name' => $name,
                'slug' => $slug,
                'industry' => $industry,
                'website_url' => 'https://'.$slug.'.example.com',
                'logo_path' => $logoPath,
            ]);
        }

        return $brands;
    }

    /**
     * @param  array<string, Brand>  $brands
     */
    private function seedListedJobs(Agency $agency, array $brands): void
    {
        $jobs = [
            ['lumora', 'Autumn skincare routine reels', '€250 per video', '4 weeks', 'Three short reels showing your evening routine with our new barrier cream.'],
            ['brisa', 'Weekend coffee rituals', '€180 per video', '3 weeks', 'Two relaxed weekend-morning videos at home, one pour-over and one cold brew.'],
            ['nordlys', 'Trail season launch', '€400 per video', '6 weeks', 'A hike-day vlog and two stories featuring the new lightweight shell.'],
            ['verdant', 'Seasonal table stories', '€220 per video', '4 weeks', 'Four stories cooking one seasonal dish from the recipe card in the box.'],
        ];

        foreach ($jobs as $index => [$key, $name, $fee, $duration, $description]) {
            Campaign::factory()->listed()->create([
                'agency_id' => $agency->id,
                'brand_id' => $brands[$key]->id,
                'name' => $name,
                'description' => $description,
                'status' => CampaignStatus::Active,
                'published_at' => now()->subDays(10 - $index),
                'listed_at' => now()->subDays(6 - $index),
                'listing_fee' => $fee,
                'listing_duration' => $duration,
                'listing_languages' => ['en', 'it'],
                'listing_regions' => ['IT', 'PT'],
                'ends_at' => null,
            ]);
        }
    }

    /**
     * The three invitations are on UNLISTED campaigns (a listed campaign fans out to the roster
     * on listing; these were invited one-to-one).
     *
     * @param  array<string, Brand>  $brands
     * @return CampaignAssignment the contracted one (it carries the campaign thread)
     */
    private function seedInvitations(Agency $agency, array $brands, Creator $creator, User $agencyAdmin): CampaignAssignment
    {
        $rows = [
            ['lumora', 'Winter glow collection', AssignmentStatus::Invited, 450_000],
            ['brisa', 'Morning brew challenge', AssignmentStatus::Countered, 320_000],
            ['nordlys', 'Fjord weekend gear review', AssignmentStatus::Contracted, 600_000],
        ];

        $contracted = null;
        foreach ($rows as [$key, $name, $status, $fee]) {
            $campaign = Campaign::factory()->active()->create([
                'agency_id' => $agency->id,
                'brand_id' => $brands[$key]->id,
                'name' => $name,
                'created_by_user_id' => $agencyAdmin->id,
                'listed_on_jobs_board' => false,
            ]);

            $assignment = CampaignAssignment::factory()->create([
                'campaign_id' => $campaign->id,
                'agency_id' => $agency->id,
                'brand_id' => $brands[$key]->id,
                'creator_id' => $creator->id,
                'status' => $status,
                'invited_at' => now()->subDays(3),
                'agreed_fee_minor_units' => $fee,
                'agreed_fee_currency' => 'EUR',
            ]);

            if ($status === AssignmentStatus::Contracted) {
                $contracted = $assignment;
            }
        }

        if ($contracted === null) {
            throw new RuntimeException('StoreScreenshotSeeder: no contracted assignment was seeded.');
        }

        return $contracted;
    }

    private function seedRelationshipThread(Agency $agency, Creator $creator, User $agencyAdmin): void
    {
        $thread = RelationshipThread::factory()->forPair($agency, $creator)->create();

        $lines = [
            [MessageSenderRole::AgencyUser, 'Hi Alessia, welcome to Studio Lumora. Glad to have you on the roster.'],
            [MessageSenderRole::Creator, 'Thank you, Marta. Happy to be here. What do you have coming up?'],
            [MessageSenderRole::AgencyUser, 'A winter skincare collection and a coffee challenge. Invitations are in your app.'],
            [MessageSenderRole::Creator, 'I saw them. I will review the fee on the coffee one today.'],
            [MessageSenderRole::AgencyUser, 'Perfect. Ping me here if you want to talk it through.'],
            [MessageSenderRole::Creator, 'Will do, thanks.'],
        ];

        $at = now()->subDays(2)->startOfHour();
        foreach ($lines as [$role, $body]) {
            $at = $at->copy()->addMinutes(37);
            RelationshipMessage::factory()->create([
                'thread_id' => $thread->id,
                'sender_user_id' => $role === MessageSenderRole::Creator ? $creator->user_id : $agencyAdmin->id,
                'sender_role' => $role,
                'body' => $body,
                'created_at' => $at,
            ]);
        }

        $thread->forceFill(['last_message_at' => $at])->save();
    }

    private function seedCampaignThread(CampaignAssignment $assignment, Creator $creator, User $agencyAdmin): void
    {
        $thread = MessageThread::factory()->forAssignment($assignment)->create();

        $at = now()->subDay()->startOfHour();

        $at = $at->copy()->addMinutes(5);
        Message::factory()->system('assignment.contracted')->create([
            'thread_id' => $thread->id,
            'created_at' => $at,
        ]);

        $lines = [
            [MessageSenderRole::AgencyUser, 'The brief is attached. Shoot window is the first two weeks of the month.'],
            [MessageSenderRole::Creator, 'Great. I will send a first draft by the end of next week.'],
            [MessageSenderRole::AgencyUser, 'Sounds good.'],
        ];
        foreach ($lines as [$role, $body]) {
            $at = $at->copy()->addMinutes(41);
            Message::factory()->create([
                'thread_id' => $thread->id,
                'sender_user_id' => $role === MessageSenderRole::Creator ? $creator->user_id : $agencyAdmin->id,
                'sender_role' => $role,
                'body' => $body,
                'created_at' => $at,
            ]);
        }

        $thread->forceFill(['last_message_at' => $at])->save();
    }

    private function seedNotifications(Creator $creator, User $agencyAdmin, CampaignAssignment $contracted): void
    {
        $recipient = (int) $creator->user_id;
        $rows = [
            [NotificationType::CreatorApproved, ['welcome_message' => 'Welcome to Catalyst.'], 9 * 24 * 60, true],
            [NotificationType::CampaignJobPosted, ['campaign_name' => 'Trail season launch', 'agency_name' => 'Studio Lumora'], 6 * 24 * 60, true],
            [NotificationType::AssignmentContracted, ['campaign_name' => 'Fjord weekend gear review', 'assignment_ulid' => $contracted->ulid], 30 * 60, true],
            [NotificationType::CampaignJobPosted, ['campaign_name' => 'Seasonal table stories', 'agency_name' => 'Studio Lumora'], 24 * 60, false],
            [NotificationType::MessageRelationshipReceivedByCreator, ['agency_name' => 'Studio Lumora', 'sender_name' => 'Marta Rocha'], 5 * 60, false],
            [NotificationType::AssignmentInvited, ['campaign_name' => 'Winter glow collection'], 4 * 60, false],
            [NotificationType::AssignmentCountered, ['campaign_name' => 'Morning brew challenge'], 3 * 60, false],
            [NotificationType::AssignmentInvited, ['campaign_name' => 'Morning brew challenge'], 2 * 60, false],
        ];

        foreach ($rows as [$type, $data, $minutesAgo, $read]) {
            $at = Carbon::now()->subMinutes($minutesAgo);

            Notification::factory()->ofType($type)->create([
                'recipient_user_id' => $recipient,
                'actor_user_id' => $agencyAdmin->id,
                'data' => $data,
                'read_at' => $read ? $at->copy()->addMinutes(20) : null,
                'created_at' => $at,
            ]);
        }
    }

    /**
     * A vertical two-colour gradient. Pure GD; no font, no network, no real image.
     *
     * @param  array{0:int,1:int,2:int}  $from
     * @param  array{0:int,1:int,2:int}  $to
     */
    private function gradientPng(int $width, int $height, array $from, array $to): string
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        if ($image === false) {
            throw new RuntimeException('StoreScreenshotSeeder: GD could not allocate an image.');
        }

        $this->paintGradient($image, $width, $height, $from, $to);

        return $this->encode($image);
    }

    /**
     * A generic person silhouette (a head and shoulders) on a gradient. No letters, so no font
     * is needed and no real face is involved.
     *
     * @param  array{0:int,1:int,2:int}  $from
     * @param  array{0:int,1:int,2:int}  $to
     */
    private function avatarPng(array $from, array $to): string
    {
        $size = 512;
        $image = imagecreatetruecolor($size, $size);
        if ($image === false) {
            throw new RuntimeException('StoreScreenshotSeeder: GD could not allocate an image.');
        }

        $this->paintGradient($image, $size, $size, $from, $to);
        $ink = (int) imagecolorallocatealpha($image, 255, 255, 255, 48);
        imagefilledellipse($image, (int) ($size / 2), (int) ($size * 0.38), (int) ($size * 0.34), (int) ($size * 0.34), $ink);
        imagefilledellipse($image, (int) ($size / 2), (int) ($size * 0.86), (int) ($size * 0.74), (int) ($size * 0.62), $ink);

        return $this->encode($image);
    }

    /**
     * A brand mark: a gradient square with a plain white disc.
     *
     * @param  array{0:int,1:int,2:int}  $from
     * @param  array{0:int,1:int,2:int}  $to
     */
    private function brandMarkPng(array $from, array $to): string
    {
        $size = 256;
        $image = imagecreatetruecolor($size, $size);
        if ($image === false) {
            throw new RuntimeException('StoreScreenshotSeeder: GD could not allocate an image.');
        }

        $this->paintGradient($image, $size, $size, $from, $to);
        $ink = (int) imagecolorallocatealpha($image, 255, 255, 255, 30);
        imagefilledellipse($image, (int) ($size / 2), (int) ($size / 2), (int) ($size * 0.5), (int) ($size * 0.5), $ink);

        return $this->encode($image);
    }

    /**
     * @param  array{0:int,1:int,2:int}  $from
     * @param  array{0:int,1:int,2:int}  $to
     */
    private function paintGradient(\GdImage $image, int $width, int $height, array $from, array $to): void
    {
        for ($y = 0; $y < $height; $y++) {
            $t = $height > 1 ? $y / ($height - 1) : 0.0;
            $colour = (int) imagecolorallocate(
                $image,
                $this->channel($from[0] + ($to[0] - $from[0]) * $t),
                $this->channel($from[1] + ($to[1] - $from[1]) * $t),
                $this->channel($from[2] + ($to[2] - $from[2]) * $t),
            );
            imageline($image, 0, $y, $width - 1, $y, $colour);
        }
    }

    /**
     * @return int<0, 255>
     */
    private function channel(float $value): int
    {
        return max(0, min(255, (int) round($value)));
    }

    private function encode(\GdImage $image): string
    {
        ob_start();
        imagepng($image);
        $binary = ob_get_clean();

        if ($binary === false || $binary === '') {
            throw new RuntimeException('StoreScreenshotSeeder: GD could not encode the image.');
        }

        return $binary;
    }
}
