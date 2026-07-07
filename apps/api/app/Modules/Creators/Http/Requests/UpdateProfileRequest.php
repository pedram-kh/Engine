<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Requests;

use App\Core\Enums\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // AH-005 — optional contact details (all nullable; partial entry
            // is fine). phone / whatsapp are validated as LENIENT phone-ish
            // strings: an E.164-friendly character set with a digit floor (so
            // an all-punctuation value like "()- " is rejected) — deliberately
            // NOT strict libphonenumber parsing.
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[+]?[0-9 ()\-]{6,32}$/', 'regex:/[0-9].*[0-9].*[0-9]/'],
            'whatsapp' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[+]?[0-9 ()\-]{6,32}$/', 'regex:/[0-9].*[0-9].*[0-9]/'],
            'address_street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            // Content-language metadata: validated against the full 24 EU
            // languages (speaker metadata is legitimately the full set).
            'primary_language' => ['sometimes', 'string', Rule::enum(Locale::class)],
            'secondary_languages' => ['sometimes', 'array'],
            'secondary_languages.*' => ['string', Rule::enum(Locale::class)],
            'categories' => ['sometimes', 'array', 'min:1', 'max:16'],
            'categories.*' => [
                'string',
                'in:lifestyle,sports,beauty,fashion,food,travel,gaming,tech,music,art,fitness,parenting,business,education,comedy,other',
            ],
        ];
    }
}
