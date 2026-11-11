<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Messaging\Features\MessagesV2CreatorInboxEnabled;
use App\Modules\Messaging\Features\MessagesV2InboxEnabled;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Removes exactly what {@see Ah115WalkSeeder} made: the two walk agencies (by slug) and the users on
 * `@ah115-walk.local` (by email) with everything hanging off them, then clears the two flag rows
 * (back to the default, OFF). Local + testing only; safe to run twice or when nothing was seeded.
 *
 *     php artisan db:seed --class='Database\Seeders\Ah115WalkCleanupSeeder'
 *
 * Note: `messages_v2_inbox_enabled` is also used by the AH-119 walk seeder; if you have that
 * data seeded, re-run `Ah119WalkSeeder` afterwards (it re-asserts its flags).
 */
final class Ah115WalkCleanupSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Ah115WalkCleanupSeeder is only allowed in local and testing environments.');
        }

        DB::transaction(function (): void {
            $agencyIds = DB::table('agencies')
                ->whereIn('slug', [Ah115WalkSeeder::NORTH_SLUG, Ah115WalkSeeder::SOUTH_SLUG])->pluck('id')->all();
            $userIds = DB::table('users')->where('email', 'like', '%'.Ah115WalkSeeder::DOMAIN)->pluck('id')->all();
            $creatorIds = DB::table('creators')->whereIn('user_id', $userIds)->pluck('id')->all();

            DB::table('message_threads')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('relationship_threads')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('relationship_threads')->whereIn('creator_id', $creatorIds)->delete();
            DB::table('campaign_assignments')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('campaign_assignments')->whereIn('creator_id', $creatorIds)->delete();
            DB::table('campaigns')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('brands')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('agency_creator_relations')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('agency_creator_relations')->whereIn('creator_id', $creatorIds)->delete();

            foreach ([
                'creator_availability_blocks', 'creator_kyc_verifications', 'creator_payout_methods',
                'creator_portfolio_items', 'creator_social_accounts', 'creator_tax_profiles',
            ] as $table) {
                DB::table($table)->whereIn('creator_id', $creatorIds)->delete();
            }

            DB::table('notifications')->whereIn('recipient_user_id', $userIds)->delete();
            DB::table('notifications')->whereIn('actor_user_id', $userIds)->delete();
            DB::table('agency_users')->whereIn('agency_id', $agencyIds)->delete();
            DB::table('agency_users')->whereIn('user_id', $userIds)->delete();
            DB::table('creators')->whereIn('id', $creatorIds)->delete();
            DB::table('agencies')->whereIn('id', $agencyIds)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();

            DB::table('features')->whereIn('name', [
                MessagesV2InboxEnabled::NAME,
                MessagesV2CreatorInboxEnabled::NAME,
            ])->delete();
        });
    }
}
