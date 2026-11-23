<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Removes exactly what {@see Pl31WalkSeeder} made, and what the walk made under it: both walk agencies (by slug), the users
 * on `@pl31-walk.local` and `@pl31-walk-b.local` (by email) with their creators, and everything under the walk agencies
 * (brands, waves, strategy rows, creators in the wave and their decisions, campaigns, boards, applications, assignments).
 *
 *     php artisan db:seed --class='Database\Seeders\Pl31WalkCleanupSeeder'
 *
 * It does NOT touch any feature flag. Local + testing only. Safe to run twice, and safe when the seeder never ran.
 */
final class Pl31WalkCleanupSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pl31WalkCleanupSeeder is only allowed in local and testing environments.');
        }

        DB::transaction(function (): void {
            $agencyIds = DB::table('agencies')
                ->whereIn('slug', [Pl31WalkSeeder::AGENCY_A_SLUG, Pl31WalkSeeder::AGENCY_B_SLUG])
                ->pluck('id')->all();
            $userIds = DB::table('users')
                ->where(fn ($q) => $q->where('email', 'like', '%'.Pl31WalkSeeder::DOMAIN)->orWhere('email', 'like', '%'.Pl31WalkSeeder::DOMAIN_B))
                ->pluck('id')->all();
            $creatorIds = DB::table('creators')->whereIn('user_id', $userIds)->pluck('id')->all();
            $brandIds = DB::table('brands')->whereIn('agency_id', $agencyIds)->pluck('id')->all();
            $campaignIds = DB::table('campaigns')->whereIn('agency_id', $agencyIds)->pluck('id')->all();

            // Children first: the foreign keys of the strategy tables are RESTRICT.
            DB::table('wave_creator_decisions')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_creators')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_concept_decisions')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_concept_links')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_concept_images')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_concept_usps')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_concepts')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_matrix_cells')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_persona_angles')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_personas')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_usps')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_steps')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_market_countries')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_strategy_notes')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_strategy_completions')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_markets')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('waves')->whereIn('agency_id', $agencyIds)->delete();

            DB::table('message_threads')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('campaign_applications')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('campaign_applications')->whereIn('creator_id', $creatorIds)->delete();
            DB::table('campaign_assignments')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('campaign_assignments')->whereIn('creator_id', $creatorIds)->delete();
            DB::table('brand_creator_blacklists')->whereIn('brand_id', $brandIds)->delete();
            DB::table('campaign_exclusive_brands')->whereIn('campaign_id', $campaignIds)->delete();
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
