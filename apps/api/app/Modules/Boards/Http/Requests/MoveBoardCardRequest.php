<?php

declare(strict_types=1);

namespace App\Modules\Boards\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a manual card move (§5.4 / D-8). `target_column_id` is the
 * destination column ULID (the controller resolves it + asserts it belongs to
 * this board). `reason` is OPTIONAL (§ schema — NOT requiresReason()).
 *
 * A manual move is a VISUALIZATION change only (§4.4): it records a movement +
 * an audit row but drives NO business logic — there is no path from here to the
 * assignment state machine.
 */
final class MoveBoardCardRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target_column_id' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
