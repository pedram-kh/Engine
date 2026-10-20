<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Execution;

use App\Modules\Erasure\Guards\Refusals;
use App\Modules\Erasure\Selection\Selection;
use App\Modules\Erasure\Selection\Selections;
use App\Modules\Erasure\Subject\SubjectSet;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Applies the database stages 4a to 4d to a resolved subject, in ONE
 * transaction: either every step happened or none did.
 *
 * Order: every step except the cascade parents and the two marker rows, then the
 * cascade parents, then `users` and `creators` last, so the marker (`erased_at`)
 * is set only when everything before it has been done.
 *
 * Not reachable from anywhere yet: there is no command, route or schedule. The
 * files (4f) and the vendors (4g) must be handled BEFORE this runs, because the
 * keys they need are read from rows this deletes; the command (Stage 5) owns
 * that order. The audit scrub (4e) needs its own connection and is refused here.
 */
final class ErasureEngine
{
    /** The stages this engine can execute on the application connection. */
    public const array STAGES = ['4a', '4b', '4c', '4d'];

    /** Selections that carry the erasure marker and therefore run last. */
    private const array MARKERS = ['users', 'creators'];

    /**
     * A parent row whose delete cascades into another selection's rows
     * (`user_push_tokens.personal_access_token_id` is ON DELETE CASCADE). It
     * runs after its children so each selection deletes, and reports, its own
     * rows; run first it would swallow the child's rows and the run would
     * report fewer than its dry-run counted.
     */
    private const array CASCADE_PARENTS = ['personal_access_tokens'];

    /**
     * @param  (Closure(string): void)|null  $afterStep  Called with the selection id after each step; a spec throws from it to prove the transaction is atomic.
     */
    public function __construct(private readonly ?Closure $afterStep = null) {}

    /**
     * @param  list<string>  $stages
     * @param  (Closure(array<string, int>): void)|null  $inTransaction  Called inside the transaction after every step with the per-selection counts: the ledger and the audit row of the run are written here, so they commit with the erasure or not at all.
     */
    public function run(SubjectSet $subject, ?ConnectionInterface $db = null, array $stages = self::STAGES, ?Closure $inTransaction = null): ExecutionReport
    {
        foreach ($stages as $stage) {
            if (! in_array($stage, self::STAGES, true)) {
                throw new InvalidArgumentException("Stage {$stage} cannot be executed by the database engine (4e needs the audit scrub connection, 4f the disk, 4g the vendor contracts).");
            }
        }

        $db ??= DB::connection();

        // Defence in depth: the command asks before it deletes any object; this is the
        // last door before an irreversible database write, so nothing can go around it.
        Refusals::assertNone($subject, $db);

        $now = now()->toDateTimeString();
        $writers = Steps::writers();

        $selections = array_values(array_filter(Selections::all(), fn (Selection $s): bool => in_array($s->stage, $stages, true)));
        $rank = fn (Selection $s): int => in_array($s->id, self::MARKERS, true) ? 2 : (in_array($s->id, self::CASCADE_PARENTS, true) ? 1 : 0);
        usort($selections, fn (Selection $a, Selection $b): int => $rank($a) <=> $rank($b));

        /** @var array<string, int> $changed */
        $changed = $db->transaction(function () use ($selections, $writers, $subject, $db, $now, $inTransaction): array {
            $out = [];
            foreach ($selections as $selection) {
                $write = $writers[$selection->id] ?? throw new InvalidArgumentException("No writer registered for selection {$selection->id}.");
                $out[$selection->id] = $write($selection, $subject, $db, $now);
                if ($this->afterStep !== null) {
                    ($this->afterStep)($selection->id);
                }
            }

            if ($inTransaction !== null) {
                $inTransaction($out);
            }

            return $out;
        });

        return new ExecutionReport($changed);
    }
}
