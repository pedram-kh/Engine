<?php

declare(strict_types=1);

namespace App\Modules\Creators\Support;

/**
 * Immutable outcome of an incomplete-creator nudge run (send or dry-run).
 *
 * `$disabled` is only ever true for a real send while the Pennant flag is OFF
 * (the explicit no-op). A dry-run computes eligibility regardless of the flag
 * so an operator can preview volume before enabling — it never reports disabled.
 */
final readonly class IncompleteNudgeReport
{
    public function __construct(
        public int $verify,
        public int $finish,
        public bool $disabled = false,
    ) {}

    public static function disabled(): self
    {
        return new self(verify: 0, finish: 0, disabled: true);
    }

    public function total(): int
    {
        return $this->verify + $this->finish;
    }
}
