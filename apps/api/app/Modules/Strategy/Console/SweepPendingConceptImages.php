<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Console;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Strategy\Models\WaveConceptImage;
use App\Modules\Strategy\Services\ConceptImageStorage;
use Illuminate\Console\Command;

/**
 * Clears abandoned concept image uploads (PL-2.3 plan section 1.7): a `pending` row more than one hour past its
 * `expires_at`. Object first (a missing object is a success), then the row; when an object delete is not confirmed the
 * row stays and the next run retries. Safe to run by hand: `php artisan strategy:sweep-pending-concept-images --dry-run`.
 */
final class SweepPendingConceptImages extends Command
{
    protected $signature = 'strategy:sweep-pending-concept-images {--dry-run : List what would be removed; delete nothing}';

    protected $description = 'Delete abandoned concept image uploads (pending reservations long past their expiry).';

    public function handle(): int
    {
        $cutoff = now()->subHour();
        $rows = WaveConceptImage::query()->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('status', WaveConceptImage::PENDING)
            ->where('expires_at', '<', $cutoff)
            ->orderBy('id')
            ->limit(500)
            ->get();

        $removed = 0;
        $kept = 0;
        foreach ($rows as $row) {
            if ($this->option('dry-run')) {
                $this->line("would remove image {$row->ulid}");

                continue;
            }
            if (! ConceptImageStorage::deleteAll(ConceptImageStorage::keysOf($row))) {
                $kept++;

                continue;
            }
            $row->delete();
            $removed++;
        }

        $this->info($this->option('dry-run') ? 'Dry run: nothing deleted.' : "Removed {$removed}; kept {$kept} (object delete not confirmed).");

        return self::SUCCESS;
    }
}
