<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Requests;

use App\Modules\Boards\Support\BoardDefaults;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a column edit (§7.2 rename, recolor, terminal toggle). All fields
 * are optional (partial update). Reordering is the dedicated `reorder` endpoint,
 * not a `position` write here. The terminal-uniqueness swap (§7.5) is enforced
 * in the service, not by validation.
 */
final class UpdateBoardColumnRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:64'],
            'color_token' => ['sometimes', 'string', Rule::in(BoardDefaults::colorTokens())],
            'is_terminal_success' => ['sometimes', 'boolean'],
            'is_terminal_failure' => ['sometimes', 'boolean'],
        ];
    }
}
