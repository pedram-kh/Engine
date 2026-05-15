<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Requests;

use App\Modules\Creators\Policies\CreatorPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/v1/admin/creators/{creator}/reject — Sprint 3
 * Chunk 4. Dedicated reject workflow per Decision E2=b.
 *
 * `rejection_reason` is REQUIRED — admin must explain why so the creator
 * gets actionable feedback on the rejected-state dashboard surface
 * (`apps/main/src/modules/creator/pages/CreatorDashboardPage.vue`).
 * Min 10 / max 2000 — matches the admin SPA's frontend mirror at
 * `apps/admin/src/modules/creators/components/RejectCreatorDialog.vue`.
 *
 * Authorization is enforced by the controller via the `reject` policy
 * gate ({@see CreatorPolicy::reject()}).
 */
final class AdminRejectCreatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
