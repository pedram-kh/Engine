<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a column reorder (§7.3). The body is the full ordered list of
 * column ULIDs; the service reassigns positions 1..n and rejects a list that
 * does not exactly match the board's columns.
 */
final class ReorderBoardColumnsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'column_ids' => ['required', 'array', 'min:1'],
            'column_ids.*' => ['required', 'string'],
        ];
    }
}
