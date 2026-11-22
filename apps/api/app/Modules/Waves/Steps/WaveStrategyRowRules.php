<?php

declare(strict_types=1);

namespace App\Modules\Waves\Steps;

use App\Modules\Waves\Enums\WaveStepSource;
use App\Modules\Waves\Enums\WaveStepStatus;

/**
 * The F1 table of PL-2.5 as a pure function (plan section 1.5), for the four rows that have a real step. Nothing here
 * reads or writes. Order: not_started < in_progress < done.
 *
 * `$before` is the real state before the change (null at catch-up, where there is no "before"), `$after` the real state
 * now. Returns what the row becomes, or null when it stays as it is.
 *
 *   system row:   follows the real state (row 1).
 *   manual row:   catch-up: taken back when the real state has reached or passed the hand-set status (row 8, row 6);
 *                 event: left alone while the real state did not change; when it changed, taken back if it now reaches or
 *                 passes the hand-set status (row 6), else left alone (rows 5 and 7).
 */
final class WaveStrategyRowRules
{
    public const REASON_SYSTEM = 'system';

    public const REASON_TOOK_BACK = 'system_took_back';

    /** @var list<string> */
    private const ORDER = ['not_started', 'in_progress', 'done'];

    public static function rank(string $state): int
    {
        $i = array_search($state, self::ORDER, true);

        return $i === false ? 0 : (int) $i;
    }

    /**
     * @return array{status: string, source: string, reason: string}|null
     */
    public static function decide(WaveStepStatus $status, WaveStepSource $source, ?string $before, string $after, bool $catchUp): ?array
    {
        if ($source === WaveStepSource::System) {
            return $status->value === $after ? null : ['status' => $after, 'source' => 'system', 'reason' => self::REASON_SYSTEM];
        }

        if (! $catchUp && $before === $after) {
            return null;
        }

        if (self::rank($after) >= self::rank($status->value)) {
            return ['status' => $after, 'source' => 'system', 'reason' => self::REASON_TOOK_BACK];
        }

        return null;
    }

    /** A hand-set beyond the real state needs the person's confirmation (rows 2 and 11). */
    public static function beyond(string $hand, string $real): bool
    {
        return self::rank($hand) > self::rank($real);
    }
}
