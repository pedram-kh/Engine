<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for PATCH /api/v1/creators/me/wizard/profile.
 *
 * All fields are optional — PATCH semantics. The server applies only
 * the fields that were actually submitted, so a creator can re-save
 * partial updates without re-entering everything.
 *
 * Categories list is the canonical Sprint 3 enum from
 * 20-PHASE-1-SPEC.md § 6.1 Step 2.
 */
final class UpdateProfileRequest extends FormRequest
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
            'display_name' => ['sometimes', 'string', 'max:120'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'country_code' => ['sometimes', 'string', 'size:2'],
            'region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'primary_language' => ['sometimes', 'string', 'size:2'],
            'secondary_languages' => ['sometimes', 'array'],
            'secondary_languages.*' => ['string', 'size:2'],
            'categories' => ['sometimes', 'array', 'min:1', 'max:8'],
            'categories.*' => [
                'string',
                'in:lifestyle,sports,beauty,fashion,food,travel,gaming,tech,music,art,fitness,parenting,business,education,comedy,other',
            ],
        ];
    }
}
