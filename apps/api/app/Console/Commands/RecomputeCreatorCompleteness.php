<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use Illuminate\Console\Command;

/**
 * One-shot cohort recompute (D5).
 *
 * The completeness formula changed (region joined the floor + the profile
 * unit now awards partial optional-field credit — AH-026 D1/D4), so every
 * existing creator's persisted `profile_completeness_score` may be stale. The
 * score is denormalised on the row and surfaced to agencies on discovery, so a
 * stale value is user-visible; this command re-derives it for the whole cohort
 * using the current {@see CompletenessScoreCalculator}.
 *
 * IDEMPOTENT: it writes only rows whose recomputed score differs from the
 * stored one, so a second run reports zero changes. It is a documented
 * POST-DEPLOY step, deliberately NOT a migration side effect — the formula
 * lives in application code, so the recompute belongs to the app, not the
 * schema, and can be re-run safely at any time.
 *
 * `--dry-run` reports what would change without writing (safe to run first).
 */
final class RecomputeCreatorCompleteness extends Command
{
    protected $signature = 'creators:recompute-completeness
        {--dry-run : Report how many scores would change without writing}';

    protected $description = 'Recompute every creator\'s profile_completeness_score with the current formula (idempotent).';

    public function handle(CompletenessScoreCalculator $calculator): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $processed = 0;
        $changed = 0;

        Creator::query()
            ->orderBy('id')
            ->chunkById(200, function ($creators) use ($calculator, $dryRun, &$processed, &$changed): void {
                foreach ($creators as $creator) {
                    $processed++;

                    $current = $creator->profile_completeness_score;
                    $recomputed = $calculator->score($creator);

                    if ($recomputed === $current) {
                        continue;
                    }

                    $changed++;

                    if (! $dryRun) {
                        $creator->profile_completeness_score = $recomputed;
                        $creator->save();
                    }
                }
            });

        $this->info(sprintf(
            '%s %d creator(s); %d score(s) %s.',
            $dryRun ? 'Checked' : 'Recomputed',
            $processed,
            $changed,
            $dryRun ? 'would change' : 'updated',
        ));

        return self::SUCCESS;
    }
}
