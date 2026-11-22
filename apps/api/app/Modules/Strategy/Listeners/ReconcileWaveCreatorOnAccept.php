<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Listeners;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Campaigns\Events\AssignmentEventContract;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Services\WaveCreatorBoardRules;
use App\Modules\Strategy\Services\WaveCreatorGuard;
use App\Modules\Waves\Models\Wave;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * PL-3.1 (plan section 8): "the client approved AND the creator accepted moves the card to Selected" needs a listener on
 * {@see AssignmentTransitioned}, which fires inside the state machine's transaction for EVERY
 * campaign in production. It therefore obeys six binding conditions, each pinned by a test with a break-revert:
 *
 *   1. Any event but `assignment.accepted` returns before anything else; then the sprint flag is read FIRST, and when it is
 *      off (or strategy or waves is off) the listener returns without another query.
 *   2. All flags on: the real work is deferred, and it starts with ONE indexed read (the wave linked to the campaign). A
 *      campaign no wave uses returns there.
 *   3. It acts only on `assignment.accepted`.
 *   4. The work runs AFTER the accept's transaction has committed (`DB::afterCommit`); it takes the wave lock and reads the
 *      committed decision and acceptance under it.
 *   5. It catches every Throwable, reports it to Sentry with IDS ONLY, and never changes the outcome of the accept.
 *   6. It runs with no agency tenancy context (the creator's own request) and needs none.
 */
final class ReconcileWaveCreatorOnAccept
{
    public const EVENT_KEY = 'assignment.accepted';

    public function handle(object $event): void
    {
        try {
            if (! $event instanceof AssignmentEventContract || $event->eventKey() !== self::EVENT_KEY) {
                return;
            }
            if (! CreatorsScriptsEnabled::active()) {
                return;
            }

            $assignment = $event->assignment();
            $ids = ['assignment_id' => $assignment->id, 'campaign_id' => $assignment->campaign_id, 'creator_id' => $assignment->creator_id, 'agency_id' => $assignment->agency_id];

            DB::afterCommit(function () use ($ids): void {
                try {
                    $this->reconcile($ids);
                } catch (Throwable $e) {
                    self::report($e, $ids);
                }
            });
        } catch (Throwable $e) {
            self::report($e, []);
        }
    }

    /**
     * @param  array{assignment_id: int, campaign_id: int, creator_id: int, agency_id: int}  $ids
     */
    private function reconcile(array $ids): void
    {
        // The ONE cheap read: the live wave linked to this campaign. A normal campaign has none and stops here.
        $waveId = DB::table('waves')->where('campaign_id', $ids['campaign_id'])->whereNull('deleted_at')->value('id');
        if ($waveId === null) {
            return;
        }

        WaveCreatorGuard::transaction(
            Wave::query()->withoutGlobalScope(BelongsToAgencyScope::class)->whereKey((int) $waveId)->firstOrFail(),
            static function (Wave $locked) use ($ids): void {
                $slot = WaveCreator::query()->withoutGlobalScope(BelongsToAgencyScope::class)
                    ->where('wave_id', $locked->id)->where('creator_id', $ids['creator_id'])->first();
                if ($slot === null) {
                    return;
                }
                WaveCreatorBoardRules::reconcile($locked, $slot, WaveCreatorBoardRules::TRIGGER_ACCEPT);
            },
        );
    }

    /**
     * Ids and the exception's class and place only: a database error's message can carry bound values, so it is not sent.
     *
     * @param  array<string, int>  $ids
     */
    private static function report(Throwable $e, array $ids): void
    {
        try {
            $note = 'wave creator reconcile on accept failed: '.$e::class.' at '.basename($e->getFile()).':'.$e->getLine();
            foreach ($ids as $key => $value) {
                $note .= " {$key}={$value}";
            }
            \Sentry\captureException(new RuntimeException($note));
        } catch (Throwable) {
            // Reporting must never change the accept either.
        }
    }
}
