<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a column delete (§7.4 / §14.3). `destination_column_id` is the
 * column non-empty cards re-home into (as manual movements) before the delete;
 * it is OPTIONAL here (an empty column needs none) — the controller enforces
 * "non-empty requires a destination" with a 422, and that the destination is a
 * different, real column on the same board.
 */
final class DeleteBoardColumnRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'destination_column_id' => ['nullable', 'string'],
        ];
    }
}
