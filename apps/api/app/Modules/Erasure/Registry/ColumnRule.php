<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Registry;

/**
 * One column's decision. `anon` is set exactly when the disposition is ANON.
 * `detail` carries the selection or key list for SCRUB-JSON and any predicate
 * the column's rule only applies under.
 */
final readonly class ColumnRule
{
    public function __construct(
        public Disposition $disposition,
        public string $reason,
        public ?AnonValue $anon = null,
        public ?string $detail = null,
    ) {}
}
