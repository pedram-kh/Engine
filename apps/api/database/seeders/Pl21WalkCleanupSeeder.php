<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Removes exactly what {@see Pl21WalkSeeder} made, and what the walk made under it: both walk agencies (by slug), the
 * users on `@pl21-walk.local` and `@pl21-walk-b.local` (by email), and the brands, waves, USPs, personas and angles
 * under the walk agencies.
 *
 *     php artisan db:seed --class='Database\Seeders\Pl21WalkCleanupSeeder'
 *
 * It does NOT touch the `waves_enabled` or `strategy_enabled` flags: Pedram turns them off by hand in the admin app.
 * Audit rows stay (their agency link is nulled when the agency goes). Local + testing only. Safe to run twice, and
 * safe when the seeder never ran. Rows of any other agency or user are never selected.
 */
final class Pl21WalkCleanupSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pl21WalkCleanupSeeder is only allowed in local and testing environments.');
        }

        DB::transaction(function (): void {
            $agencyIds = DB::table('agencies')
                ->whereIn('slug', [Pl21WalkSeeder::AGENCY_A_SLUG, Pl21WalkSeeder::AGENCY_B_SLUG])
                ->pluck('id')->all();
            $userIds = DB::table('users')
                ->where(fn ($q) => $q->where('email', 'like', '%'.Pl21WalkSeeder::DOMAIN)->orWhere('email', 'like', '%'.Pl21WalkSeeder::DOMAIN_B))
                ->pluck('id')->all();

            // Children first: every foreign key of the strategy tables is RESTRICT.
            DB::table('wave_matrix_cells')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_persona_angles')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_personas')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_usps')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_steps')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_market_countries')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_markets')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('waves')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('campaigns')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('brands')->whereIn('agency_id', $agencyIds)->delete();

            DB::table('notifications')->whereIn('recipient_user_id', $userIds)->delete();
            DB::table('agency_users')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('agency_users')->whereIn('user_id', $userIds)->delete();
            DB::table('agencies')->whereIn('id', $agencyIds)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
        });
    }
}
