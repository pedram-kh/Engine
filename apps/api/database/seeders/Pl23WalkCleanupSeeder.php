<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Strategy\Services\ConceptImageStorage;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Removes exactly what {@see Pl23WalkSeeder} made, and what the walk made under it: both walk agencies (by slug), the users
 * on `@pl23-walk.local` and `@pl23-walk-b.local` (by email), and the brands, waves, USPs, personas, angles, matrix cells,
 * concepts, chips, images, links and decisions under the walk agencies, plus the stored image objects of those concepts and
 * the walk files in `storage/app/pl23-walk-files`.
 *
 *     php artisan db:seed --class='Database\Seeders\Pl23WalkCleanupSeeder'
 *
 * It does NOT touch the `waves_enabled` or `strategy_enabled` flags: Pedram turns them off by hand in the admin app.
 * Audit rows stay (their agency link is nulled when the agency goes). Local + testing only. Safe to run twice, and safe when
 * the seeder never ran. Rows of any other agency or user are never selected.
 */
final class Pl23WalkCleanupSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pl23WalkCleanupSeeder is only allowed in local and testing environments.');
        }

        DB::transaction(function (): void {
            $agencyIds = DB::table('agencies')
                ->whereIn('slug', [Pl23WalkSeeder::AGENCY_A_SLUG, Pl23WalkSeeder::AGENCY_B_SLUG])
                ->pluck('id')->all();
            $userIds = DB::table('users')
                ->where(fn ($q) => $q->where('email', 'like', '%'.Pl23WalkSeeder::DOMAIN)->orWhere('email', 'like', '%'.Pl23WalkSeeder::DOMAIN_B))
                ->pluck('id')->all();

            // The stored objects first (the keys are read from the rows that are about to go).
            $keys = [];
            foreach (DB::table('wave_concept_images')->whereIn('agency_id', $agencyIds)->get(['storage_key', 'thumb_key']) as $row) {
                $keys[] = $row->storage_key;
                if ($row->thumb_key !== null) {
                    $keys[] = $row->thumb_key;
                }
            }
            if ($keys !== []) {
                try {
                    ConceptImageStorage::deleteAll($keys);
                } catch (Throwable $e) {
                    $this->say('warn', 'Some stored image objects could not be deleted ('.$e->getMessage().').');
                }
            }

            // Children first: every foreign key of the strategy tables is RESTRICT.
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

        $dir = storage_path('app/'.Pl23WalkSeeder::FILES_DIR);
        if (is_dir($dir)) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }
    }

    /** Writes to the console when run from `db:seed`; silent when a test calls the seeder directly (no command is bound). */
    private function say(string $style, string $message): void
    {
        $command = $this->command;
        if (! $command instanceof Command) {
            return;
        }
        if ($style === 'warn') {
            $command->warn($message);
        } else {
            $command->line($message);
        }
    }
}
