<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Requests;

use App\Modules\Creators\Policies\CreatorPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/v1/admin/creators/{creator}/verify-identity —
 * Sprint 4 Chunk 3 (D-c3-3, manual KYC clearance).
 *
 * `note` is an optional free-text justification for the manual override.
 * When present it's captured in the creator.kyc.manually_verified audit
 * metadata (not persisted to a column — the audit trail is the record of
 * the operator's reasoning). Max length 1000 to keep audit-metadata size
 * reasonable, mirroring the approve welcome_message cap.
 *
 * Authorization is enforced by the controller via the `verifyIdentity`
 * policy gate ({@see CreatorPolicy::verifyIdentity()}).
 */
final class VerifyCreatorIdentityRequest extends FormRequest
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
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
