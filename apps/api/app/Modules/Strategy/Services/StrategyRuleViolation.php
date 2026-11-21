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
}
