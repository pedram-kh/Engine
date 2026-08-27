<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the mandatory reason on all three admin member-2FA operations
 * (AH-087): reset, exempt, and remove-exemption.
 *
 * One request class rather than three identical ones — the payload is the
 * same single field with the same bounds (the SuspendAgencyRequest shape:
 * min 10, max 2000, mirrored by the dialogs so the UI catches it before a
 * 422). All three verbs are `requiresReason()`, including the un-exempt
 * direction: restoring a requirement on a person is not the same as
 * restoring an org's default state, which is why this diverges from the
 * agency.reactivate precedent (which takes no reason at all).
 *
 * Authorization is the controller's policy check (AgencyPolicy::
 * manageMemberMfa) plus its target checks; this request only shapes the
 * payload.
 */
final class MemberMfaActionRequest extends FormRequest
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
