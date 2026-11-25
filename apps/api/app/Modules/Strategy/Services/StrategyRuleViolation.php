<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Strategy\Http\Controllers\Concerns\ResolvesStrategyRoute;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\WaveMarket;
use RuntimeException;

/**
 * A refused strategy write, carrying the API error `code`, the HTTP status (422 unless stated) and optional `meta`.
 * Thrown by the strategy services, mapped by the controllers through {@see ResolvesStrategyRoute}.
 */
final class StrategyRuleViolation extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function __construct(
        public readonly string $errorCode,
        string $title,
        public readonly int $status = 422,
        public readonly ?array $meta = null,
    ) {
        parent::__construct($title);
    }

    public static function brandArchived(): self
    {
        return new self('wave.brand_archived', 'An archived brand is read-only; restore it before changing its waves.');
    }

    public static function uspMaxReached(): self
    {
        return new self('usp.max_reached', 'A wave has at most 6 USPs.');
    }

    public static function uspOrderMismatch(): self
    {
        return new self('usp.order_mismatch', 'The USPs changed since the page was loaded; reload and drag again.');
    }

    /**
     * @param  list<array{kind: string, persona: string, market: string, code: string, title: string, level: string|null, explanation: string}>  $affected
     */
    public static function uspInUse(array $affected): self
    {
        return new self(
            'usp.in_use',
            'Personas or matrix cells use this USP; confirm to remove it together with them.',
            409,
            ['count' => count($affected), 'affected' => $affected],
        );
    }

    public static function uspInUseForbidden(): self
    {
        return new self('forbidden', 'Only an admin or manager can delete a USP that personas use.', 403);
    }

    public static function personaMaxReached(WaveMarket $market): self
    {
        return new self('persona.max_reached', "A market has at most 8 personas ({$market->name}).", 422, ['market' => $market->ulid]);
    }

    public static function personaMarketNotInWave(string $market): self
    {
        return new self('persona.market_not_in_wave', 'That market is not one of this wave\'s markets.', 422, ['market' => $market]);
    }

    public static function personaCodeTaken(string $code): self
    {
        return new self('persona.code_taken', "The code {$code} is already used by another persona of this market.");
    }

    public static function personaCopyExceedsCap(int $target, int $copying): self
    {
        return new self(
            'persona.copy_exceeds_cap',
            'Copying would give the market more than 8 personas; nothing was copied.',
            422,
            ['target_count' => $target, 'copy_count' => $copying, 'max' => 8],
        );
    }

    public static function personaCopyInvalid(): self
    {
        return new self('persona.copy_invalid', 'Copy needs two different markets of this wave, and personas of the source market.');
    }

    public static function angleAlreadyLinked(): self
    {
        return new self('angle.already_linked', 'This persona already has an angle on that USP.');
    }

    public static function angleSyncedReadOnly(): self
    {
        return new self('angle.synced_read_only', 'This angle is synced from the messaging matrix; change it there.');
    }

    public static function angleNoMatrixLine(): self
    {
        return new self('angle.no_matrix_line', 'The matrix has no Priority or Support message line for this persona and USP.');
    }

    public static function angleNothingToResolve(): self
    {
        return new self('angle.nothing_to_resolve', 'The angle and the matrix line already say the same.');
    }

    public static function conflict(): self
    {
        return new self('strategy.conflict', 'Someone changed this at the same moment; reload and try again.', 409);
    }

    // ---- PL-2.3: concepts ----

    public static function conceptMarketRequired(): self
    {
        return new self('concept.market_required', 'Pass the market (its id) to list its concepts.');
    }

    public static function conceptNotFound(): self
    {
        return new self('not_found', 'Not found.', 404);
    }

    public static function conceptMarketFull(WaveMarket $market): self
    {
        return new self('concept.market_full', 'A market has at most 30 concepts ('.$market->name.').', 422, ['market' => $market->ulid, 'max' => StrategyLimits::CONCEPT_MAX_PER_MARKET]);
    }

    public static function conceptPersonaInvalid(): self
    {
        return new self('concept.persona_invalid', 'The persona must be one of the personas of the concept\'s market.');
    }

    public static function conceptUspModeRequired(): self
    {
        return new self('concept.usp_mode_required', 'Changing the persona needs a choice for the USP chips: keep them or replace them.');
    }

    public static function conceptDeleteDecidedForbidden(): self
    {
        return new self('forbidden', 'Only an admin or manager can delete a concept the client has decided on.', 403);
    }

    public static function conceptDeleteFailed(): self
    {
        return new self('concept.delete_failed', 'A stored image could not be deleted; nothing was removed. Try again.', 500);
    }

    public static function conceptDuplicateFailed(): self
    {
        return new self('concept.duplicate_failed', 'The images could not be copied; no copy was made. Try again.', 500);
    }

    /**
     * @param  array<int, array{concept: string, number: int, title: string, status: string}>  $affected
     */
    public static function personaInUseByConcepts(array $affected): self
    {
        return new self(
            'persona.in_use_by_concepts',
            'Concepts use this persona. Move them to another persona or delete them first.',
            422,
            ['count' => count($affected), 'affected' => $affected],
        );
    }

    public static function linkInvalidUrl(string $why): self
    {
        return new self('link.invalid_url', $why);
    }

    public static function linksFull(): self
    {
        return new self('concept_link.limit_count', 'A concept has at most '.StrategyLimits::LINKS_PER_CONCEPT.' links.', 422, ['max' => StrategyLimits::LINKS_PER_CONCEPT]);
    }

    public static function imageLimitCount(int $used): self
    {
        return new self('concept_image.limit_count', 'A concept has at most '.StrategyLimits::IMAGES_PER_CONCEPT.' images.', 422, ['used' => $used, 'max' => StrategyLimits::IMAGES_PER_CONCEPT]);
    }

    public static function imageLimitBytes(int $usedBytes, int $askedBytes): self
    {
        return new self('concept_image.limit_bytes', 'The images of a concept add up to at most 300 MB.', 422, ['used_bytes' => $usedBytes, 'asked_bytes' => $askedBytes, 'max_bytes' => StrategyLimits::IMAGE_TOTAL_BYTES]);
    }

    public static function imageInvalid(string $why): self
    {
        return new self('concept_image.invalid', $why);
    }

    public static function imageUnknownUpload(): self
    {
        return new self('concept_image.unknown_upload', 'This upload is not reserved on this concept, or it expired. Start the upload again.');
    }

    public static function imageAlreadyCompleted(): self
    {
        return new self('concept_image.already_completed', 'This upload was already completed.');
    }

    public static function decisionInvalid(string $why): self
    {
        return new self('concept.decision_invalid', $why);
    }

    public static function conflict404(): self
    {
        return new self('not_found', 'Not found.', 404);
    }

    public static function conceptUspInvalid(): self
    {
        return new self('concept.usp_invalid', 'A USP chip must be a USP of this wave.');
    }

    public static function imageDeleteFailed(): self
    {
        return new self('concept_image.delete_failed', 'The stored image could not be deleted; nothing was removed. Try again.', 500);
    }

    /** PL-2.5 (Q1): marking a step that has no data at all (no USP, no persona in the market, no cell set) is refused. */
    public static function nothingToComplete(): self
    {
        return new self('strategy.nothing_to_complete', 'There is nothing to mark as complete yet.');
    }

    /** PL-2.5: a per-market step needs its market; USPs are per wave and refuse one. */
    public static function stepMarket(bool $required): self
    {
        return $required
            ? new self('strategy.market_required', 'This step is per market: say which market.')
            : new self('strategy.market_not_allowed', 'USPs are per wave, not per market.');
    }

    /** PL-2.5: a thread holds at most 100 notes. */
    public static function noteThreadFull(): self
    {
        return new self('note.thread_full', 'A thread holds at most 100 notes.');
    }

    /** PL-2.5 (K4): only the author edits a note; only the author, an admin or a manager deletes it. */
    public static function noteForbidden(): self
    {
        return new self('note.forbidden', 'You cannot change this note.', 403);
    }

    /** PL-2.5: a note body is 1 to 2000 characters of plain text. */
    public static function noteBodyInvalid(): self
    {
        return new self('note.body_invalid', 'A note is 1 to 2000 characters.');
    }

    // ── PL-3.1 creators ──────────────────────────────────────────────────────────────────────────────────────────────

    public static function creatorCampaignRequired(): self
    {
        return new self('wave_creator.campaign_required', 'This wave has no campaign yet. Link or create one first.');
    }

    /** One body for "does not exist", "not eligible" and "erased": the endpoint must not probe the creators table. */
    public static function creatorNotEligible(): self
    {
        return new self('wave_creator.not_eligible', 'That creator cannot be added to this wave.');
    }

    /**
     * @param  string|null  $market  the ulid of the market the creator is already in
     */
    public static function creatorAlreadyInWave(?string $market): self
    {
        return new self('wave_creator.already_in_wave', 'That creator is already in this wave.', 422, $market === null ? null : ['market' => $market]);
    }

    public static function creatorCapReached(string $scope, int $max): self
    {
        return new self('wave_creator.cap_reached', $scope === 'market' ? "A market has at most {$max} creators." : "A wave has at most {$max} creators.", 422, ['scope' => $scope, 'max' => $max]);
    }

    public static function creatorPersonaMarketMismatch(): self
    {
        return new self('wave_creator.persona_market_mismatch', 'That persona does not belong to this market of the wave.');
    }

    public static function creatorSlotCodeTaken(string $code): self
    {
        return new self('wave_creator.slot_code_taken', "The code {$code} is already used in this market.", 422, ['code' => $code]);
    }

    public static function creatorRemoveBlockedInWork(): self
    {
        return new self('wave_creator.remove_blocked_in_work', 'This creator\'s card is in Filming or later. Move the card back first.');
    }

    public static function creatorReplaceSame(): self
    {
        return new self('wave_creator.replace_same_creator', 'Pick a different creator to replace this one.');
    }

    public static function creatorDecisionInvalid(string $message): self
    {
        return new self('wave_creator.decision_invalid', $message);
    }

    /** A persona that creators are matched to cannot be deleted (the same rule as concepts: unmatch them first). */
    public static function personaInUseByCreators(int $count): self
    {
        return new self('persona.in_use_by_creators', 'Creators are matched to this persona. Match them to another persona or none first.', 422, ['count' => $count]);
    }

    // ── PL-3.2a scripts ──────────────────────────────────────────────────────────────────────────────────────────────

    /** The creator already has the most LIVE scripts a wave allows (archived scripts do not count). */
    public static function scriptLimitReached(): self
    {
        $max = StrategyLimits::SCRIPTS_PER_CREATOR;

        return new self('wave_script.limit_reached', "A creator has at most {$max} scripts in a wave.", 422, ['max' => $max]);
    }

    /** Restore an archived script of a creator who already has three live ones. */
    public static function scriptRestoreLimitReached(): self
    {
        $max = StrategyLimits::SCRIPTS_PER_CREATOR;

        return new self('wave_script.restore_limit_reached', "This creator already has {$max} scripts. Archive one of them first, then restore this one.", 422, ['max' => $max]);
    }

    /** Restore an archived script whose creator is no longer in its market: it comes back when the creator is added again. */
    public static function scriptRestoreCreatorMissing(): self
    {
        return new self('wave_script.restore_creator_missing', 'This creator is not in the market any more. The script comes back when the creator is added again.');
    }

    public static function scriptHooksLimit(): self
    {
        $max = StrategyLimits::HOOKS_PER_SCRIPT;

        return new self('wave_script.hooks_limit', "A script has at most {$max} hooks.", 422, ['max' => $max]);
    }

    public static function scriptLastHook(): self
    {
        return new self('wave_script.last_hook', 'A script keeps at least one hook.');
    }

    /** A hook text in a language other than English: the market languages arrive with PL-3.2b. */
    public static function scriptLanguageNotAvailable(string $language): self
    {
        return new self('wave_script.language_not_available', 'Only English text can be written for now.', 422, ['language' => $language]);
    }

    /**
     * 409: the block was changed by someone else since it was read. Nothing was written; `current` is the block as it is now.
     *
     * @param  array<string, mixed>  $current
     */
    public static function scriptConflict(string $block, array $current): self
    {
        return new self('wave_script.conflict', 'This was changed by someone else. Review the latest version.', 409, ['block' => $block, 'current' => $current]);
    }

    /** Remove a creator who has scripts without saying what happens to them. */
    public static function scriptsChoiceRequired(int $count): self
    {
        return new self('wave_creator.scripts_choice_required', 'This creator has scripts. Choose to archive them or delete them.', 422, ['scripts' => $count]);
    }

    /** Deleting scripts together with a slot, or deleting an archived script: admin and manager only. */
    public static function scriptsDeleteForbidden(): self
    {
        return new self('forbidden', 'Only an admin or manager can delete scripts.', 403);
    }
}
