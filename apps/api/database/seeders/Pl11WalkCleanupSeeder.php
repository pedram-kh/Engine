<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Removes exactly what {@see Pl11WalkSeeder} made, and what the walk made under it: both walk
 * agencies (by slug), the users on `@pl11-walk.local` (by email), and their brands, campaigns,
 * waves and client-file rows.
 *
 *     php artisan db:seed --class='Database\Seeders\Pl11WalkCleanupSeeder'
 *
 * It does NOT touch the `waves_enabled` flag: Pedram turns it off by hand in the admin app (plan
 * correction C5). Objects uploaded to the local MinIO bucket stay there. Audit rows stay too (their
 * agency link is nulled when the agency goes). Local + testing only. Safe to run twice, and safe
 * when the seeder never ran.
 */
final class Pl11WalkCleanupSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pl11WalkCleanupSeeder is only allowed in local and testing environments.');
        }

        DB::transaction(function (): void {
            $agencyIds = DB::table('agencies')
                ->whereIn('slug', [Pl11WalkSeeder::AGENCY_A_SLUG, Pl11WalkSeeder::AGENCY_B_SLUG])
                ->pluck('id')->all();
            $userIds = DB::table('users')->where('email', 'like', '%'.Pl11WalkSeeder::DOMAIN)->pluck('id')->all();

            DB::table('wave_market_countries')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('wave_markets')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('waves')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('brand_files')->whereIn('agency_id', $agencyIds)->delete();
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
