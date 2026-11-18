<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Controllers\Concerns;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Boards\Exceptions\BoardRuleViolation;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardAutomation;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Cross-tenant + cross-parent guards for the board surface (Sprint 12 Chunk 1).
 * Every mismatch is a 404 (ABSENCE, not 403) — the assertBelongsToAgency
 * precedent: never leak ULID validity across tenant or parent boundaries
 * (docs/05-SECURITY-COMPLIANCE.md §7).
 *
 * BoardColumn / BoardCard are BelongsToAgency-scoped, so a cross-AGENCY ULID is
 * already filtered to a 404 at route-model binding; these asserts additionally
 * defend the cross-CAMPAIGN edge (same agency, wrong board). BoardAutomation is
 * NOT scoped, so its assert is the sole tenant + parent guard for that entity.
 */
trait ResolvesBoardEntities
{
    /**
     * PL-1.2 (Q6) — the structure of a for-a-wave board (columns, order, automations, reset) is fixed: 422
     * `board.wave_columns_fixed`. A normal board is untouched.
     */
    private function refuseWaveStructureEdit(Request $request, Board $board): ?JsonResponse
    {
        if (! $board->isWaveBoard()) {
            return null;
        }

        $violation = BoardRuleViolation::waveColumnsFixed();

        return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, $violation->errorCode, $violation->getMessage());
    }

    private function assertCampaignBelongsToAgency(Campaign $campaign, Agency $agency): void
    {
        if ($campaign->agency_id !== $agency->id) {
            abort(404);
        }
    }

    private function assertColumnOnBoard(BoardColumn $column, Board $board): void
    {
        if ($column->board_id !== $board->id) {
            abort(404);
        }
    }

    private function assertCardOnBoard(BoardCard $card, Board $board): void
    {
        if ($card->board_id !== $board->id) {
            abort(404);
        }
    }

    private function assertAutomationOnBoard(BoardAutomation $automation, Board $board): void
    {
        if ($automation->board_id !== $board->id) {
            abort(404);
        }
    }
}
