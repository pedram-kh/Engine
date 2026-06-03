<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for GET /api/v1/creators/me/availability (Sprint 5 Chunk A).
 *
 * The list endpoint returns expanded occurrences for a window (D-a4), so it
 * accepts an optional `from`/`to` window. Both are optional; the controller
 * applies a default window and clamps the span to bound recurrence expansion.
 */
final class ListAvailabilityBlocksRequest extends FormRequest
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
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ];
    }
}
