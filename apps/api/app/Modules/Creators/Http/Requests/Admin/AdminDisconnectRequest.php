<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * AH-051 (D-6) — validates the mandatory disconnect reason.
 *
 * Disconnect is the platform's first relation-termination path: it severs a
 * live `roster` relationship, deletes the pair's pool memberships, and notifies
 * both parties. The reason is required (min:10, the suspend pattern) — it is the
 * audit `reason` for the reason-required `agency_creator_relation.disconnected`
 * verb and the termination paper-trail. Authorization is the route's web_admin +
 * MFA + the controller's platform_admin guard.
 */
final class AdminDisconnectRequest extends FormRequest
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
