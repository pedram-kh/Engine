<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Removes exactly what {@see Pl13WalkSeeder} made, and what the walk made under it: both walk agencies (by slug), the
 * users on `@pl13-walk.local` and `@pl13-walk-b.local` (by email), and the brands, waves and wave steps under the
 * walk agencies.
 *
 *     php artisan db:seed --class='Database\Seeders\Pl13WalkCleanupSeeder'
 *
 * It does NOT touch the `waves_enabled` flag: Pedram turns it off by hand in the admin app. Audit rows stay (their
 * agency link is nulled when the agency goes). Local + testing only. Safe to run twice, and safe when the seeder never
 * ran. Rows of any other agency or user are never selected.
 */
final class Pl13WalkCleanupSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pl13WalkCleanupSeeder is only allowed in local and testing environments.');
        }

        DB::transaction(function (): void {
            $agencyIds = DB::table('agencies')
                ->whereIn('slug', [Pl13WalkSeeder::AGENCY_A_SLUG, Pl13WalkSeeder::AGENCY_B_SLUG])
                ->pluck('id')->all();
            $userIds = DB::table('users')
                ->where(fn ($q) => $q->where('email', 'like', '%'.Pl13WalkSeeder::DOMAIN)->orWhere('email', 'like', '%'.Pl13WalkSeeder::DOMAIN_B))
                ->pluck('id')->all();

            DB::table('wave_steps')->whereIn('agency_id', $agencyIds)->delete();
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
