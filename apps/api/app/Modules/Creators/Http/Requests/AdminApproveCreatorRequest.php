<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Requests;

use App\Modules\Creators\Policies\CreatorPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/v1/admin/creators/{creator}/approve — Sprint 3
 * Chunk 4. Dedicated approve workflow per Decision E2=b.
 *
 * `welcome_message` is optional. When present, it's persisted to
 * `creators.welcome_message` (column added by the Chunk 4 migration).
 * Max length 1000 to keep audit-metadata size reasonable.
 *
 * Authorization is enforced by the controller via the `approve` policy
 * gate ({@see CreatorPolicy::approve()}).
 */
final class AdminApproveCreatorRequest extends FormRequest
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
            'welcome_message' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
