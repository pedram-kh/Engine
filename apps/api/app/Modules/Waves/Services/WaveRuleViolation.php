<?php

declare(strict_types=1);

namespace App\Modules\Waves\Services;

use RuntimeException;

/**
 * A refused wave write, carrying the API error `code` (422). Thrown by {@see WaveService}, mapped by the controller.
 */
final class WaveRuleViolation extends RuntimeException
{
    private function __construct(public readonly string $errorCode, string $title)
    {
        parent::__construct($title);
    }

    public static function brandArchived(): self
    {
        return new self('wave.brand_archived', 'An archived brand is read-only; restore it before changing its waves.');
    }

    public static function campaignNotAvailable(): self
    {
        return new self('wave.campaign_not_available', 'That campaign cannot be linked to a wave of this brand.');
    }

    public static function campaignAlreadyLinked(): self
    {
        return new self('wave.campaign_already_linked', 'That campaign already has a wave.');
    }

    public static function campaignLinkLocked(): self
    {
        return new self('wave.campaign_link_locked', 'The link cannot change while a creator of the campaign is Selected or further.');
    }

    // PL-1.3 — the tracker's refusals (all 422).
    public static function stepOwnerNotAMember(): self
    {
        return new self('step.owner_not_a_member', 'The owner must be an active member of this agency, or the word Client.');
    }

    public static function stepOwnerConflict(): self
    {
        return new self('step.owner_conflict', 'A step has a member as owner or the Client, not both.');
    }

    public static function stepStrategyLocked(): self
    {
        return new self('step.strategy_locked', 'A strategy step can be renamed, but not removed or moved to another stream.');
    }

    public static function stepDatesInvalid(): self
    {
        return new self('step.dates_invalid', 'The due date cannot be before the start date.');
    }

    public static function streamNotRemovable(): self
    {
        return new self('stream.not_removable', 'The UGC video stream cannot be removed: the Brand page progress bar depends on it.');
    }
}
