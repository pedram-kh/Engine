<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Requests;

use App\Modules\Creators\Http\Requests\ListAvailabilityBlocksRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for GET /api/v1/agencies/{agency}/creators — the agency roster
 * list (Sprint 4 Chunk 5) + the availability range filter (Sprint 6.5, D-6).
 *
 * Only the availability window is validated here; the existing structured
 * filters (status / country / language / category / q) stay permissive
 * query-string reads in the controller (an unknown value yields an empty
 * page, never a 422 — the SPA only sends valid chips).
 *
 * The availability window mirrors {@see ListAvailabilityBlocksRequest}
 * exactly: both bounds optional, `available_to` must be on/after
 * `available_from`. Explicit `available_*` names (not bare from/to) avoid
 * collision with the generic filters and read unambiguously on the roster
 * surface. The filter activates only when BOTH bounds are present (a
 * one-sided range is ignored, never defaulted-forward — see the controller).
 */
final class ListAgencyRosterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'available_from' => ['sometimes', 'date'],
            'available_to' => ['sometimes', 'date', 'after_or_equal:available_from'],
        ];
    }
}
