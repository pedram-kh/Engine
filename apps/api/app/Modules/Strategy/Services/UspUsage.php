<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Strategy\Enums\AngleOrigin;
use App\Modules\Strategy\Models\WaveMatrixCell;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveUsp;

/**
 * Everything that hangs off a USP, in ONE place (plan section 3.4, S5). PL-2.1: the persona angles. PL-2.2: the
 * messaging-matrix cells (a cell and the synced angle it feeds are ONE item). Later chunks extend this method (PL-2.3
 * concepts, PL-5 approvals and comments); the confirmation list, the count and the audit payload all read from here, so
 * a new dependent cannot be forgotten.
 */
final class UspUsage
{
    /**
     * One item per hand-written angle (`kind` angle) and one per matrix cell (`kind` cell, with its level). A synced
     * angle is not listed on its own: it is part of its cell.
     *
     * @return list<array{kind: string, persona: string, market: string, code: string, title: string, level: string|null, explanation: string}>
     */
    public static function affected(WaveUsp $usp): array
    {
        $out = [];
        foreach (WavePersonaAngle::query()->where('usp_id', $usp->id)->where('origin', AngleOrigin::Manual->value)->with('persona')->orderBy('id')->get() as $a) {
            $out[] = [
                'kind' => 'angle',
                'persona' => $a->persona->ulid,
                'market' => $a->persona->market,
                'code' => $a->persona->code,
                'title' => $a->persona->title,
                'level' => null,
                'explanation' => $a->explanation,
            ];
        }
        foreach (WaveMatrixCell::query()->where('usp_id', $usp->id)->with('persona')->orderBy('id')->get() as $c) {
            $out[] = [
                'kind' => 'cell',
                'persona' => $c->persona->ulid,
                'market' => $c->persona->market,
                'code' => $c->persona->code,
                'title' => $c->persona->title,
                'level' => $c->level->value,
                'explanation' => $c->message_line ?? '',
            ];
        }

        return $out;
    }

    /** Every angle row on the USP (hand-written and synced), as the card shows "Used by N angles". */
    public static function angleCount(WaveUsp $usp): int
    {
        return WavePersonaAngle::query()->where('usp_id', $usp->id)->count();
    }

    public static function cellCount(WaveUsp $usp): int
    {
        return WaveMatrixCell::query()->where('usp_id', $usp->id)->count();
    }
}
