<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Strategy\Services\ConceptImageStorage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Removes exactly what {@see Pl32WalkSeeder} made, and what the walk made under it: both walk agencies (by slug), the users
 * on `@pl32-walk.local` and `@pl32-walk-b.local` (by email) with their creators, and everything under the walk agencies
 * (brands, waves, strategy rows, scripts with their hooks, lines, B-roll and texts, the reference images (the stored objects first, then the rows), creators in the wave and their decisions, campaigns, boards, applications, assignments).
 *
 *     php artisan db:seed --class='Database\Seeders\Pl32WalkCleanupSeeder'
 *
 * It does NOT touch any feature flag. Local + testing only. Safe to run twice, and safe when the seeder never ran.
 */
final class Pl32WalkCleanupSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pl32WalkCleanupSeeder is only allowed in local and testing environments.');
        }

        // PL-3.2b: the stored objects of the script images go first. A delete that is not confirmed stops the clean-up before any row goes,
        // so no object is left without a row.
        $walkAgencies = DB::table('agencies')->whereIn('slug', [Pl32WalkSeeder::AGENCY_A_SLUG, Pl32WalkSeeder::AGENCY_B_SLUG])->pluck('id')->all();
        $keys = [];
        foreach (DB::table('wave_script_images')->whereIn('agency_id', $walkAgencies)->get(['storage_key', 'thumb_key']) as $image) {
            array_push($keys, ...array_values(array_filter([$image->storage_key, $image->thumb_key])));
        }
        if (! ConceptImageStorage::deleteAll($keys)) {
            throw new RuntimeException('A stored script image could not be deleted; nothing was removed. Run the clean-up again.');
        }

        DB::transaction(function (): void {
            $agencyIds = DB::table('agencies')
                ->whereIn('slug', [Pl32WalkSeeder::AGENCY_A_SLUG, Pl32WalkSeeder::AGENCY_B_SLUG])
                ->pluck('id')->all();
            $userIds = DB::table('users')
                ->where(fn ($q) => $q->where('email', 'like', '%'.Pl32WalkSeeder::DOMAIN)->orWhere('email', 'like', '%'.Pl32WalkSeeder::DOMAIN_B))
                ->pluck('id')->all();
            $creatorIds = DB::table('creators')->whereIn('user_id', $userIds)->pluck('id')->all();
            $brandIds = DB::table('brands')->whereIn('agency_id', $agencyIds)->pluck('id')->all();
            $campaignIds = DB::table('campaigns')->whereIn('agency_id', $agencyIds)->pluck('id')->all();

            // Children first: the foreign keys of the strategy tables are RESTRICT. Scripts: texts, hooks, scripts, then the slots.
            DB::table('wave_script_images')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_script_broll')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_script_line_texts')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_script_lines')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_script_hook_texts')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_script_hooks')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_scripts')->whereIn('agency_id', $agencyIds)->delete();
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
            DB::table('wave_market_script_languages')->whereIn('agency_id', $agencyIds)->delete();
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

            DB::table('admin_profiles')->whereIn('user_id', $userIds)->delete();
            DB::table('notifications')->whereIn('recipient_user_id', $userIds)->delete();
            DB::table('agency_users')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('agency_users')->whereIn('user_id', $userIds)->delete();
            DB::table('creators')->whereIn('id', $creatorIds)->delete();
            DB::table('agencies')->whereIn('id', $agencyIds)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
        });
    }
}
