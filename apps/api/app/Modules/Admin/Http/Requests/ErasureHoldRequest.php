<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates placing or releasing an erasure hold (AH-104c C2).
 *
 * The reason is MANDATORY for both: it is the only place a hold's why is kept (the audit row; the
 * columns hold timestamps and an admin id), so a hold with no reason is a hold nobody can
 * justify later. It must carry no personal details; the form says so, and the audit row is
 * scrubbed by the erasure only for the rows the registry names. Authorization (platform admin and
 * MFA) is the route stack; this request only shapes the payload.
 */
final class ErasureHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason'));
    }
}
