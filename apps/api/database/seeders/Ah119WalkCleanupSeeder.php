<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Messaging\Features\MessagesV2EditSearchEnabled;
use App\Modules\Messaging\Features\MessagesV2InboxEnabled;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Removes exactly what {@see Ah119WalkSeeder} made, and nothing else: the walk agency (by slug)
 * and the users on `@ah119-walk.local` (by email), with everything hanging off them, then clears
 * the two AH-119 flag rows so the flags are back to their default (OFF).
 *
 *     php artisan db:seed --class='Database\Seeders\Ah119WalkCleanupSeeder'
 *
 * Local + testing only. Safe to run twice, and safe when the seeder never ran. Rows of any other
 * agency or user are never selected, so the dev data around the walk is untouched.
 */
final class Ah119WalkCleanupSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Ah119WalkCleanupSeeder is only allowed in local and testing environments.');
        }

        DB::transaction(function (): void {
            $agencyIds = DB::table('agencies')->where('slug', Ah119WalkSeeder::AGENCY_SLUG)->pluck('id')->all();
            $userIds = DB::table('users')->where('email', 'like', '%'.Ah119WalkSeeder::DOMAIN)->pluck('id')->all();
            $creatorIds = DB::table('creators')->whereIn('user_id', $userIds)->pluck('id')->all();

            // Threads first (their messages and read receipts cascade), then the campaign tree.
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

            DB::table('features')->whereIn('name', [
                MessagesV2InboxEnabled::NAME,
                MessagesV2EditSearchEnabled::NAME,
            ])->delete();
        });
    }
}
