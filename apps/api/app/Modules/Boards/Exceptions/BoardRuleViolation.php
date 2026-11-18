<?php

declare(strict_types=1);

namespace App\Modules\Boards\Exceptions;

use RuntimeException;

/**
 * PL-1.2 — a refused board write on a for-a-wave board, carrying the API error `code` (422).
 */
final class BoardRuleViolation extends RuntimeException
{
    private function __construct(public readonly string $errorCode, string $title)
    {
        parent::__construct($title);
    }

    public static function waveColumnsFixed(): self
    {
        return new self('board.wave_columns_fixed', 'The columns and automations of a campaign for a wave are fixed.');
    }

    public static function selectionRequiresAcceptance(): self
    {
        return new self('board.selection_requires_acceptance', 'Only a creator who has accepted can be moved into a working column.');
    }
}
