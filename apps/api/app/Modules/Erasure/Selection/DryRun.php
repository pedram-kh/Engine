<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Selection;

use App\Modules\Erasure\Subject\SubjectSet;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Runs every selection in count mode. It issues SELECT statements only and
 * touches no row, no ledger, no cache and no disk (a spec records every query
 * and compares a hash of every table before and after).
 */
final class DryRun
{
    public function run(SubjectSet $subject, ?ConnectionInterface $db = null): DryRunReport
    {
        $db ??= DB::connection();
        $lines = [];
        foreach (Selections::all() as $selection) {
            $measured = $selection->measure($subject, $db);
            $lines[] = [
                'id' => $selection->id,
                'table' => $selection->table,
                'disposition' => $selection->disposition->value,
                'stage' => $selection->stage,
                'description' => $selection->description,
                'count' => $measured['count'],
                'read' => $measured['read'],
            ];
        }

        return new DryRunReport($subject->userUlid, $subject->summary(), $lines);
    }
}
