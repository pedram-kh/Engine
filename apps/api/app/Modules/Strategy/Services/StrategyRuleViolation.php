<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Strategy\Http\Controllers\Concerns\ResolvesStrategyRoute;
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

    public static function personaMaxReached(string $market): self
    {
        return new self('persona.max_reached', "A market has at most 8 personas ({$market}).", 422, ['market' => $market]);
    }

    public static function personaMarketNotInWave(string $market): self
    {
        return new self('persona.market_not_in_wave', "The market {$market} is not one of this wave's markets.", 422, ['market' => $market]);
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

    public static function conflict(): self
    {
        return new self('strategy.conflict', 'Someone changed this at the same moment; reload and try again.', 409);
    }
}
