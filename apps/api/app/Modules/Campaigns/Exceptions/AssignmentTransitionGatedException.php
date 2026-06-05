<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Exceptions;

use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;

/**
 * Thrown by {@see CampaignAssignmentStateMachine}
 * when a transition is LEGAL by source state but VENDOR-GATED and not yet
 * reachable under own power (Sprint 8 Chunk 1, D-6).
 *
 * This is the footgun guard: there is NO manual path to `live_verified` /
 * `payment_held` / `payment_released` until the social adapter (parked) and
 * the Stripe escrow integration (Sprint 10) exist. `contracted` is gated on
 * the `contract_signing_enabled` flag (the e-sign mock exists, so it IS
 * reachable when the flag is on).
 *
 * Distinct from {@see AssignmentTransitionException} (illegal source) so a
 * test can assert that the SOURCE guard passed but the VENDOR gate refused.
 *
 * Allowed codes:
 *   assignment.social_adapter_unavailable    — live_verified (social, parked).
 *   assignment.escrow_unavailable            — payment_* (Stripe escrow, S10).
 *   assignment.contract_signing_disabled     — contracted (flag off).
 */
final class AssignmentTransitionGatedException extends AssignmentTransitionException
{
    public static function socialAdapterUnavailable(): self
    {
        return new self(
            'assignment.social_adapter_unavailable',
            'live_verified requires the social-verification adapter, which is parked. No manual path is permitted.',
        );
    }

    public static function escrowUnavailable(): self
    {
        return new self(
            'assignment.escrow_unavailable',
            'payment transitions require the Stripe escrow integration (Sprint 10). No manual path is permitted.',
        );
    }

    public static function contractSigningDisabled(): self
    {
        return new self(
            'assignment.contract_signing_disabled',
            'contracted requires the contract_signing_enabled flag (the e-sign flow).',
        );
    }
}
