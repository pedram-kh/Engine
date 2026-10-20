<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Execution;

use App\Modules\Erasure\Selection\DryRunReport;

/**
 * What a run changed, as numbers per selection id. Comparable key for key with
 * {@see DryRunReport::counts()}: a run that did
 * what its dry-run said produces the same map.
 */
final readonly class ExecutionReport
{
    /** @param array<string, int> $changed selection id => rows changed */
    public function __construct(public array $changed) {}

    /** @return array<string, int> */
    public function counts(): array
    {
        $out = $this->changed;
        ksort($out);

        return $out;
    }

    public function total(): int
    {
        return array_sum($this->changed);
    }
}
