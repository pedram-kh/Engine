<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveUsp;

/**
 * Everything that hangs off a USP, in ONE place (plan section 3.4, S5). PL-2.1: the persona angles. Later chunks extend
 * this method (PL-2.2 the messaging matrix rows, PL-2.3 concepts, PL-5 approvals and comments); the confirmation list,
 * the count and the audit payload all read from here, so a new dependent cannot be forgotten.
 */
final class UspUsage
{
    /**
     * @return list<array{persona: string, market: string, code: string, title: string, explanation: string}>
     */
    public static function affected(WaveUsp $usp): array
    {
        $out = [];
        foreach (WavePersonaAngle::query()->where('usp_id', $usp->id)->with('persona')->orderBy('id')->get() as $a) {
            $out[] = [
                'persona' => $a->persona->ulid,
                'market' => $a->persona->market,
                'code' => $a->persona->code,
                'title' => $a->persona->title,
                'explanation' => $a->explanation,
            ];
        }

        return $out;
    }

    public static function angleCount(WaveUsp $usp): int
    {
        return WavePersonaAngle::query()->where('usp_id', $usp->id)->count();
    }
}
