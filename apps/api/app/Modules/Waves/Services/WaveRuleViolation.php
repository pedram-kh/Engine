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
}
