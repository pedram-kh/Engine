<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Creators\Features\CampaignBrandExclusivityEnabled;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Removes exactly what {@see Ah120WalkSeeder} made, and nothing else: the walk agency (by slug)
 * and the users on `@ah120-walk.local` (by email), with everything hanging off them, then clears
 * the exclusivity flag row so the flag is back to its default (OFF).
 *
 *     php artisan db:seed --class='Database\Seeders\Ah120WalkCleanupSeeder'
 *
 * Local + testing only. Safe to run twice, and safe when the seeder never ran.
 */
final class Ah120WalkCleanupSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Ah120WalkCleanupSeeder is only allowed in local and testing environments.');
        }

        DB::transaction(function (): void {
            $agencyIds = DB::table('agencies')->where('slug', Ah120WalkSeeder::AGENCY_SLUG)->pluck('id')->all();
            $userIds = DB::table('users')->where('email', 'like', '%'.Ah120WalkSeeder::DOMAIN)->pluck('id')->all();
            $creatorIds = DB::table('creators')->whereIn('user_id', $userIds)->pluck('id')->all();
            $campaignIds = DB::table('campaigns')->whereIn('agency_id', $agencyIds)->pluck('id')->all();

            DB::table('campaign_exclusive_brands')->whereIn('campaign_id', $campaignIds)->delete();
            DB::table('campaign_applications')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('campaign_applications')->whereIn('creator_id', $creatorIds)->delete();
            DB::table('message_threads')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('relationship_threads')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('relationship_threads')->whereIn('creator_id', $creatorIds)->delete();
            DB::table('campaign_assignments')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('campaign_assignments')->whereIn('creator_id', $creatorIds)->delete();
            DB::table('campaigns')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('brands')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('agency_creator_relations')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('agency_creator_relations')->whereIn('creator_id', $creatorIds)->delete();

            DB::table('notifications')->whereIn('recipient_user_id', $userIds)->delete();
            DB::table('agency_users')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('agency_users')->whereIn('user_id', $userIds)->delete();
            DB::table('creators')->whereIn('id', $creatorIds)->delete();
            DB::table('agencies')->whereIn('id', $agencyIds)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();

            DB::table('features')->where('name', CampaignBrandExclusivityEnabled::NAME)->delete();
        });
    }
}
