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
    private function __construct(public readonly string $errorCode, string $title, public readonly ?array $meta = null, public readonly int $status = 422)
    {
        parent::__construct($title);
    }

    /**
     * PL-2.5 (F1 row 2) — a status set by hand beyond what the real strategy step has reached (409). Not a block: the same
     * request with `confirm_incomplete: true` is saved. `meta` says where the real step stands.
     *
     * @param  array{step: string, real_state: string, markets_done: int, markets_total: int}  $meta
     */
    public static function stepIncomplete(array $meta): self
    {
        return new self('step.incomplete', 'This step is not complete yet. Confirm to set it anyway.', $meta, 409);
    }

    /**
     * PL-2.5 (Q5) — a wave edit would remove a market that has internal notes (422). Not a block: the same request with
     * `confirm_notes_deleted` equal to the total deletes them with the market. `meta.markets` lists the markets and counts.
     *
     * @param  list<array{market: string, name: string, code: string, note_count: int}>  $markets
     */
    public static function marketHasNotes(array $markets, int $total): self
    {
        return new self('wave.market_has_notes', 'Those markets have internal notes. Confirm to delete them with the markets.', ['markets' => $markets, 'note_count' => $total]);
    }

    /**
     * PL-3.2a (Q14) — a wave edit would remove a market that has ARCHIVED scripts (422). Not a block: the same request with
     * `confirm_scripts_deleted` equal to the total deletes them with the market. `meta.markets` lists the markets and counts.
     *
     * @param  list<array{market: string, name: string, code: string, script_count: int}>  $markets
     */
    public static function marketHasScripts(array $markets, int $total): self
    {
        return new self('wave.market_has_scripts', 'Those markets have archived scripts. Confirm to delete them with the markets.', ['markets' => $markets, 'script_count' => $total]);
    }

    public static function brandArchived(): self
    {
        return new self('wave.brand_archived', 'An archived brand is read-only; restore it before changing its waves.');
    }

    /**
     * PL-2.1 — a wave edit would remove a market that still has personas (422). `meta.markets` lists the markets (by ulid,
     * with name and short code, PL-2.4) and their counts so the dialog can say "Remove the personas of UK & Ireland first
     * (3 personas, 2 concepts)". The concept key is added only when above zero.
     *
     * @param  list<array{market: string, name: string, code: string, persona_count: int, concept_count?: int}>  $markets
     */
    public static function marketHasPersonas(array $markets): self
    {
        return new self('wave.market_has_personas', 'Remove the personas of those markets before removing the markets from the wave.', ['markets' => $markets]);
    }

    /**
     * PL-3.1 (Q16) — a wave edit would remove a market that still has creators (422). Remove or replace them first.
     *
     * @param  list<array{market: string, name: string, code: string, creator_count: int}>  $markets
     */
    public static function marketHasCreators(array $markets): self
    {
        return new self('wave.market_has_creators', 'Remove the creators of those markets before removing the markets from the wave.', ['markets' => $markets]);
    }

    /** PL-3.1 (Q9) — a wave that has creators is not deleted (422). */
    public static function hasCreators(): self
    {
        return new self('wave.has_creators', 'A wave that has creators cannot be deleted.');
    }

    /** PL-2.4 (M2) — a country is in two markets of the request (422). Names are for the message, never an audit field. */
    public static function countryInTwoMarkets(string $country, string $first, string $second): self
    {
        return new self('wave.country_in_two_markets', 'A country can belong to only one market of a wave.', ['country' => $country, 'markets' => [$first, $second]]);
    }

    public static function marketCodeTaken(string $code): self
    {
        return new self('wave.market_code_taken', 'Two markets of a wave cannot share a short code.', ['code' => $code]);
    }

    public static function marketNameTaken(string $name): self
    {
        return new self('wave.market_name_taken', 'Two markets of a wave cannot share a name.', ['name' => $name]);
    }

    /** PL-2.4 — a market id in the list is not a market of this wave (422; never says whether it exists elsewhere). */
    public static function marketUnknown(): self
    {
        return new self('wave.market_unknown', 'One of the markets is not a market of this wave.');
    }

    /** PL-2.4 (Q12) — the markets changed since the list was loaded (409; nothing saved). */
    public static function marketsChanged(): self
    {
        return new self('wave.markets_changed', 'The markets of this wave changed since you opened it. Review the list and save again.', null, 409);
    }

    /** PL-2.4 — a database key refused the write after every pre-check passed (422; should not happen under the lock). */
    public static function marketConflict(): self
    {
        return new self('wave.market_conflict', 'The markets of this wave changed while you were saving. Reload and try again.');
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
