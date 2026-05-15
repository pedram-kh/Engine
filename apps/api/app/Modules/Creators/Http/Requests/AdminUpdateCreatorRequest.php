<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation for PATCH /api/v1/admin/creators/{creator} — Sprint 3 Chunk 4.
 *
 * Per-field admin edit endpoint. The body MUST contain exactly one of the
 * 7 editable fields (display_name | bio | country_code | region |
 * primary_language | secondary_languages | categories) plus an optional
 * `reason` field. `application_status` is intentionally absent from the
 * editable set — status transitions use the dedicated approve / reject
 * endpoints per Decision E2=b. Submitting `application_status` here returns
 * 422 + `creator.admin.field_status_immutable` (Q-chunk-4-2 answer = (a):
 * the API surface itself enforces the separation).
 *
 * CROSS-LAYER CONTRACT (Sprint 3 § b — avatar-completeness lesson):
 * Each field's validation rules MUST match the wizard's corresponding
 * form-request rules so a value the admin can save is one the wizard
 * would have accepted. Source of truth for parity:
 *   {@see UpdateProfileRequest::rules()}  (PATCH /api/v1/creators/me/wizard/profile)
 *
 * REASON REQUIREMENT (Q-chunk-4-3 = (b)):
 * Frontend pre-validates + backend re-validates. The set of fields that
 * REQUIRE a non-empty reason lives in {@see self::REASON_REQUIRED_FIELDS}.
 * For now: bio (PII-adjacent free-text) and categories (downstream
 * matching impact). Mirrors the frontend's REASON_REQUIRED_FIELDS constant
 * at `apps/admin/src/modules/creators/config/field-edit.ts`.
 */
final class AdminUpdateCreatorRequest extends FormRequest
{
    /**
     * Fields the generic PATCH endpoint accepts. Closed set — anything
     * outside this list returns 422 with a structured error.
     *
     * @var list<string>
     */
    public const array EDITABLE_FIELDS = [
        'display_name',
        'bio',
        'country_code',
        'region',
        'primary_language',
        'secondary_languages',
        'categories',
    ];

    /**
     * Fields whose update REQUIRES a non-empty `reason` payload field.
     * The frontend mirrors this constant in
     * `apps/admin/src/modules/creators/config/field-edit.ts`. Both layers
     * enforce; backend is the trust boundary.
     *
     * @var list<string>
     */
    public const array REASON_REQUIRED_FIELDS = ['bio', 'categories'];

    /**
     * The 16-category enum shared with the wizard's Step 2. Keep this list
     * in sync with {@see UpdateProfileRequest::rules()} `categories.*` rule.
     *
     * @var list<string>
     */
    private const array CATEGORY_ENUM = [
        'lifestyle', 'sports', 'beauty', 'fashion', 'food', 'travel',
        'gaming', 'tech', 'music', 'art', 'fitness', 'parenting',
        'business', 'education', 'comedy', 'other',
    ];

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
            // Cross-layer contract: rules MUST match UpdateProfileRequest.
            // The `sometimes` qualifier lets a single PATCH carry one field
            // at a time without forcing all fields' presence.
            'display_name' => ['sometimes', 'string', 'max:120'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'country_code' => ['sometimes', 'string', 'size:2'],
            'region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'primary_language' => ['sometimes', 'string', 'size:2'],
            'secondary_languages' => ['sometimes', 'array'],
            'secondary_languages.*' => ['string', 'size:2'],
            'categories' => ['sometimes', 'array', 'min:1', 'max:8'],
            'categories.*' => ['string', Rule::in(self::CATEGORY_ENUM)],

            // Reason metadata for the audit row. Conditionally required
            // by withValidator() based on REASON_REQUIRED_FIELDS.
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $payload = $this->all();

            // Refuse application_status outright — separation of concerns
            // per Q-chunk-4-2 (a). The dedicated approve/reject endpoints
            // carry the additional invariants (welcome_message, rejection
            // _reason, distinct audit codes) and are the only legitimate
            // path for status transitions.
            if (array_key_exists('application_status', $payload)) {
                $v->errors()->add(
                    'application_status',
                    'Status transitions use the dedicated approve / reject endpoints.',
                );
                // Signal the controller to convert this validation error
                // into the `creator.admin.field_status_immutable` code via
                // the failedValidation override below.
                $this->merge(['__status_immutable' => true]);

                return;
            }

            // Require exactly one editable field per request — admin
            // per-field edit shape (Decision E1=a). Empty body and
            // multi-field bodies are 422.
            $present = array_values(array_filter(
                self::EDITABLE_FIELDS,
                static fn (string $field): bool => array_key_exists($field, $payload),
            ));

            if (count($present) === 0) {
                $v->errors()->add(
                    'field',
                    'Request must include exactly one editable field.',
                );

                return;
            }

            if (count($present) > 1) {
                $v->errors()->add(
                    'field',
                    'Request must include exactly one editable field. Got: '
                        .implode(', ', $present).'.',
                );

                return;
            }

            // Reason required for sensitive fields.
            $field = $present[0];
            $reason = $this->input('reason');
            $reasonProvided = is_string($reason) && trim($reason) !== '';
            if (in_array($field, self::REASON_REQUIRED_FIELDS, true) && ! $reasonProvided) {
                $v->errors()->add(
                    'reason',
                    "Updating `{$field}` requires a non-empty reason.",
                );
            }
        });
    }

    /**
     * Returns the name of the single editable field being updated. Caller
     * relies on withValidator() having already confirmed presence + uniqueness.
     */
    public function editableField(): string
    {
        foreach (self::EDITABLE_FIELDS as $field) {
            if ($this->has($field)) {
                return $field;
            }
        }

        // Defensive: withValidator() should have rejected this case.
        throw new \LogicException('No editable field present after validation.');
    }

    /**
     * Override the standard failed-validation flow when the body carries
     * `application_status` — the controller maps this to the structured
     * error code `creator.admin.field_status_immutable` (consumed by the
     * admin SPA's `useErrorMessage` resolver).
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->boolean('__status_immutable')) {
            throw new ValidationException(
                $validator,
                response()->json([
                    'errors' => [[
                        'status' => '422',
                        'code' => 'creator.admin.field_status_immutable',
                        'title' => 'Status transitions use approve / reject endpoints.',
                        'source' => ['pointer' => '/data/attributes/application_status'],
                    ]],
                ], 422),
            );
        }

        parent::failedValidation($validator);
    }
}
