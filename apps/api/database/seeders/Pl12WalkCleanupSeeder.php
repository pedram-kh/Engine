<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Removes exactly what {@see Pl12WalkSeeder} made, and what the walk made under it: both walk agencies (by slug), the
 * users on `@pl12-walk.local` (by email), their creators and roster rows, and the brands, campaigns, boards, cards,
 * applications, assignments and waves under the walk agencies.
 *
 *     php artisan db:seed --class='Database\Seeders\Pl12WalkCleanupSeeder'
 *
 * It does NOT touch the `waves_enabled` flag: Pedram turns it off by hand in the admin app. Audit rows stay (their
 * agency link is nulled when the agency goes). Local + testing only. Safe to run twice, and safe when the seeder
 * never ran. Rows of any other agency or user are never selected.
 */
final class Pl12WalkCleanupSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pl12WalkCleanupSeeder is only allowed in local and testing environments.');
        }

        DB::transaction(function (): void {
            $agencyIds = DB::table('agencies')
                ->whereIn('slug', [Pl12WalkSeeder::AGENCY_A_SLUG, Pl12WalkSeeder::AGENCY_B_SLUG])
                ->pluck('id')->all();
            $userIds = DB::table('users')->where('email', 'like', '%'.Pl12WalkSeeder::DOMAIN)->pluck('id')->all();
            $creatorIds = DB::table('creators')->whereIn('user_id', $userIds)->pluck('id')->all();

            DB::table('wave_market_countries')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_markets')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('waves')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('message_threads')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('campaign_applications')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('campaign_applications')->whereIn('creator_id', $creatorIds)->delete();
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
        });
    }
}
