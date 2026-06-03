<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the agency rating/notes edit (Sprint 6 Chunk 2a, D-2a-3).
 *
 * The SCOPE GUARD lives here AND in the controller: this request only
 * declares rules for the two editable fields, and the controller pulls ONLY
 * those two keys into the model update. Anything else in the payload (a
 * blacklist flag, a counter, relationship_status) has no validation rule and
 * no path to the model — it is silently ignored (break-revert: assert a
 * blacklist/counter field in the payload does NOT change the relation).
 *
 *   - internal_rating : 1–5, nullable (clearing the rating is allowed).
 *   - internal_notes  : free text, nullable. NOT audit-allowlisted (D-2a-5).
 *
 * Both use `sometimes` so a partial PATCH (rating only / notes only) is valid;
 * the controller detects an actual notes CHANGE to decide whether to emit the
 * redacted notes-audit event.
 *
 * Authorization is handled by the controller's `Gate::authorize('update', ...)`
 * (admin/manager) AFTER the relation-exists tenancy check, so this request
 * authorizes unconditionally.
 */
final class UpdateAgencyCreatorRelationRequest extends FormRequest
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
            'internal_rating' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
