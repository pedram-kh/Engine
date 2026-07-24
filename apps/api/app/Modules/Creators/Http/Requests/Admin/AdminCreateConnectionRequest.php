<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * AH-051 (D-4/D-5) — validates the admin connection payload (both doors).
 *
 *   mode=request  → Door 1: send a connection request (rides ConnectionRequestMail).
 *   mode=direct   → Door 2: direct-connect (records an offline agreement). The
 *                   `reason` is MANDATORY here — it is the consent paper-trail
 *                   (the audit `reason` for the reason-required admin_connected
 *                   verb) — min:10 mirroring the suspend-agency pattern.
 *
 * `agency_id` is the picked agency's ULID (resolved to an Agency in the
 * controller). Authorization is the route's web_admin + MFA + the controller's
 * platform_admin guard; this request only shapes the payload.
 */
final class AdminCreateConnectionRequest extends FormRequest
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
            'agency_id' => ['required', 'string', 'max:40'],
            'mode' => ['required', 'string', 'in:request,direct'],
            // Mandatory ONLY for Door 2 (direct-connect). Door 1 needs no reason
            // (it is an ordinary request the creator can decline).
            'reason' => ['required_if:mode,direct', 'nullable', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function mode(): string
    {
        return (string) $this->input('mode');
    }

    public function agencyUlid(): string
    {
        return (string) $this->input('agency_id');
    }

    public function reason(): ?string
    {
        $reason = $this->input('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}
