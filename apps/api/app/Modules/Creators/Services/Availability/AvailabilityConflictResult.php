<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services\Availability;

/**
 * Outcome of a hard-block conflict check over a date range
 * (see {@see AvailabilityConflictService}).
 *
 * Detection only — there is no modal, no invite-flow wiring (that surface
 * is Sprint 8, forward-blocked on campaign_assignments). The result carries
 * the overlapping hard occurrences so the Sprint 8 trigger can render them.
 */
final readonly class AvailabilityConflictResult
{
    /**
     * @param  list<AvailabilityOccurrence>  $conflicts  overlapping HARD occurrences
     */
    public function __construct(
        public bool $hasConflict,
        public array $conflicts,
    ) {}
}
