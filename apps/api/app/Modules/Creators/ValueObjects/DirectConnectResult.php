<?php

declare(strict_types=1);

namespace App\Modules\Creators\ValueObjects;

use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Enums\DirectConnectOutcome;
use App\Modules\Creators\Services\CreatorAgencyDirectConnectService;

/**
 * What {@see CreatorAgencyDirectConnectService::connect()} returns (AH-090, D2).
 *
 * The extraction's whole point: Door 2's logic used to return a `JsonResponse`,
 * which a queued job has no use for. It returns this instead, and the two
 * callers present it — the controller as an AH-051 envelope, the job as one
 * structured log line.
 *
 * `relation` is null only when the refusal happened before any row was read
 * (a non-approved creator, a suspended agency). A refusal that inspected an
 * existing row carries it, so a caller that wants to log the state it refused
 * on has it without a second query.
 */
final readonly class DirectConnectResult
{
    private function __construct(
        public DirectConnectOutcome $outcome,
        public ?AgencyCreatorRelation $relation,
        /** True only when the relation row did not exist before this call (the 201 signal). */
        public bool $wasNetNew,
    ) {}

    public static function connected(AgencyCreatorRelation $relation, bool $wasNetNew): self
    {
        return new self(DirectConnectOutcome::Connected, $relation, $wasNetNew);
    }

    public static function alreadyConnected(AgencyCreatorRelation $relation): self
    {
        return new self(DirectConnectOutcome::AlreadyConnected, $relation, false);
    }

    public static function refused(DirectConnectOutcome $outcome, ?AgencyCreatorRelation $relation = null): self
    {
        return new self($outcome, $relation, false);
    }
}
