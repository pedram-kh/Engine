<?php

declare(strict_types=1);

namespace App\Modules\Waves\Services;

use RuntimeException;

/**
 * A refused wave write, carrying the API error `code` (422). Thrown by {@see WaveService}, mapped by the controller.
 */
final class WaveRuleViolation extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function __construct(public readonly string $errorCode, string $title, public readonly ?array $meta = null)
    {
        parent::__construct($title);
    }

    public static function brandArchived(): self
    {
        return new self('wave.brand_archived', 'An archived brand is read-only; restore it before changing its waves.');
    }

    /**
     * PL-2.1 — a wave edit would remove a market that still has personas (422). `meta.markets` lists the markets and
     * their persona counts so the dialog can say "Remove the personas of SK first (3)".
     *
     * @param  array<string, int>  $counts  market code => persona count
     */
    public static function marketHasPersonas(array $counts): self
    {
        $markets = [];
        foreach ($counts as $market => $n) {
            $markets[] = ['market' => (string) $market, 'persona_count' => $n];
        }

        return new self('wave.market_has_personas', 'Remove the personas of those markets before removing the markets from the wave.', ['markets' => $markets]);
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

    public static function streamDefaultsExist(): self
    {
        return new self('stream.defaults_exist', 'Every default step of this stream is already on the wave, moved to another stream. Move them back or add a step instead.');
    }
}
