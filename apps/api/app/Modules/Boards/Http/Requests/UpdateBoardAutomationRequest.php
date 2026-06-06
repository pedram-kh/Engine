<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Requests;

use App\Modules\Boards\Enums\BoardAutomationActionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an automation edit (§8): enable/disable + set the target column.
 * All fields optional (partial update). `target_column_id` is a column ULID or
 * explicit null ("No automation"); the controller resolves + validates it
 * belongs to this board. `action_type` is the move/none toggle (D-1).
 */
final class UpdateBoardAutomationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_enabled' => ['sometimes', 'boolean'],
            'action_type' => ['sometimes', Rule::enum(BoardAutomationActionType::class)],
            'target_column_id' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
