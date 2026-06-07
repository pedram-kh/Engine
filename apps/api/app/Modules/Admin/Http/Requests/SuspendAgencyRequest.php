<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the mandatory suspension reason (Sprint 13, D-3).
 *
 * Suspending an agency cuts off EVERY agency user's login, so the reason
 * is required — it is both the audit `reason` (the agency.suspended verb
 * requiresReason()) and the `suspended_reason` shown on the agency-detail
 * surface so a later admin can see why. Authorization is handled by the
 * controller's policy check (AgencyPolicy::suspend); this request only
 * shapes the payload.
 */
final class SuspendAgencyRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason'));
    }
}
