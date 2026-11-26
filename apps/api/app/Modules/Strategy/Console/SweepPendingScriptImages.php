<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Console;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Strategy\Models\WaveScriptImage;
use App\Modules\Strategy\Services\ConceptImageStorage;
use App\Modules\Strategy\Services\ScriptImageStorage;
use Illuminate\Console\Command;

/**
 * Clears abandoned script image uploads (PL-3.2b): a `pending` row more than one hour past its `expires_at`. Object first (a
 * missing object is a success), then the row; when an object delete is not confirmed the row stays and the next run retries, so an
 * object is never left without a row. Scheduled every fifteen minutes in `bootstrap/app.php`. Safe by hand:
 * `php artisan strategy:sweep-pending-script-images --dry-run`.
 */
final class SweepPendingScriptImages extends Command
{
    protected $signature = 'strategy:sweep-pending-script-images {--dry-run : List what would be removed; delete nothing}';

    protected $description = 'Delete abandoned script image uploads (pending reservations long past their expiry).';

    public function handle(): int
    {
        $rows = WaveScriptImage::query()->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('status', WaveScriptImage::PENDING)
            ->where('expires_at', '<', now()->subHour())
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
            if (! ConceptImageStorage::deleteAll(ScriptImageStorage::keysOf($row))) {
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
