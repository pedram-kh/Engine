<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Requests;

use App\Modules\Boards\Support\BoardDefaults;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new board column (§7.1). Role authorization lives in the
 * controller (Gate::authorize). `name` ≤ 64 chars (§1.2); `color_token` must be
 * a design-system status token (§1.2 — the BoardDefaults palette SoT). Position
 * is server-assigned (end of board), never client-supplied.
 */
final class CreateBoardColumnRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'color_token' => ['required', 'string', Rule::in(BoardDefaults::colorTokens())],
            'is_terminal_success' => ['sometimes', 'boolean'],
            'is_terminal_failure' => ['sometimes', 'boolean'],
        ];
    }
}
