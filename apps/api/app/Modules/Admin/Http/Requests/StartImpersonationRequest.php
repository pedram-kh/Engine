<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an impersonation start (Sprint 13, D-9).
 *
 * The target is addressed by its public ULID; the reason is MANDATORY (the
 * admin.impersonation_started verb requiresReason) — impersonation is a
 * privilege-sensitive action and the reason is the incident-review record
 * of WHY support assumed a user's identity. Authorization (platform_admin +
 * MFA) is the route stack; this request only shapes the payload.
 */
final class StartImpersonationRequest extends FormRequest
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
            'user_ulid' => ['required', 'string', 'size:26'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function userUlid(): string
    {
        return (string) $this->input('user_ulid');
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason'));
    }
}
