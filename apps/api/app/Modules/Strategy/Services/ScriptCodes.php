<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

/**
 * The derived labels of a script (PL-3.2b): the line number and the B-roll code. Neither is stored. The hook is line 1, so the
 * first line of the list is line 2, and the first B-roll row of line 2 is `2_1`. One place, so a reorder, a delete or a duplicate
 * cannot make a stored code drift; the presenter and the tests read these two functions only.
 */
final class ScriptCodes
{
    public const int FIRST_LINE_NUMBER = 2;

    public static function lineNumber(int $linePosition): int
    {
        return $linePosition + self::FIRST_LINE_NUMBER;
    }

    public static function brollCode(int $linePosition, int $brollPosition): string
    {
        return self::lineNumber($linePosition).'_'.($brollPosition + 1);
    }
}
