<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Execution;

use App\Modules\Erasure\Selection\AuditRowScrub;
use App\Modules\Erasure\Selection\Selection;
use App\Modules\Erasure\Selection\Selections;
use App\Modules\Erasure\Subject\SubjectSet;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Stage 4e: the audit scrub (PII map §4.1, predicates S1 to S5).
 *
 * `audit_logs` is append-only by model and by intent, but in production the
 * application role holds UPDATE on it (read-back C), so no database grant stands
 * between this code and the table. The protection is therefore in the code:
 *
 *  - the only writes are `UPDATE audit_logs SET <ip, user_agent, metadata,
 *    before, after> WHERE id = ?`, through the raw query builder (never the
 *    model, whose update() throws and whose Audited trait is not involved);
 *  - {@see self::write()} refuses any other column before a statement is sent;
 *  - an architecture spec asserts that no other file under `app/` updates or
 *    deletes `audit_logs`.
 *
 * Idempotent: a clean row yields no change, so a second run writes nothing.
 * Batched by primary key in chunks of {@see self::CHUNK}, one transaction per
 * chunk; a failure part-way leaves some rows scrubbed and the rest for the
 * next run, which is safe because the account is still intact (the run calls
 * this FIRST).
 */
final class DatabaseAuditScrub implements AuditScrub
{
    public const int CHUNK = 500;

    public function available(): bool
    {
        return true;
    }

    public function run(SubjectSet $subject): array
    {
        $db = DB::connection();
        $counts = ['audit_logs.union' => 0];
        foreach (AuditRowScrub::PREDICATES as $p) {
            $counts['audit_logs.'.$p] = 0;
        }

        $union = $this->selection('audit_logs.union');

        $union->builder($subject, $db)->chunkById(self::CHUNK, function ($rows) use ($subject, $db, &$counts): void {
            $db->transaction(function () use ($rows, $subject, $db, &$counts): void {
                foreach ($rows as $row) {
                    if (! $row instanceof stdClass) {
                        continue;
                    }
                    $changes = AuditRowScrub::changes($row, $subject);
                    if ($changes === []) {
                        continue;
                    }
                    $this->write($db, (int) $row->id, $changes);
                    $counts['audit_logs.union']++;
                    foreach (AuditRowScrub::PREDICATES as $p) {
                        if (AuditRowScrub::changes($row, $subject, $p) !== []) {
                            $counts['audit_logs.'.$p]++;
                        }
                    }
                }
            });
        });

        return $counts;
    }

    /**
     * The single place `audit_logs` is written.
     *
     * @param  array<string, string|null>  $changes
     */
    private function write(ConnectionInterface $db, int $id, array $changes): void
    {
        $forbidden = array_values(array_diff(array_keys($changes), AuditRowScrub::COLUMNS));
        if ($forbidden !== []) {
            throw AuditScrubViolation::forColumns($forbidden);
        }
        if ($id <= 0) {
            throw AuditScrubViolation::noRow();
        }

        $db->table('audit_logs')->where('id', $id)->update($changes);
    }

    private function selection(string $id): Selection
    {
        foreach (Selections::all() as $selection) {
            if ($selection->id === $id) {
                return $selection;
            }
        }

        throw new \LogicException("No selection {$id}.");
    }
}
