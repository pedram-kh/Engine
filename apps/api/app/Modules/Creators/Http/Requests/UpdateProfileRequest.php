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
    /**
     * The fixed "Who appears in your content?" companion registry (AH-050).
     * SOURCE OF TRUTH for the 11 keys — the admin request's read-only view and
     * the wizard's FE copy (`ProfileBasicsForm.vue` COMPANION_KEYS) mirror this
     * list. The FE-copy drift is the same recorded AH-019 debt class as
     * categories (no source-parse parity spec on the wizard file — see
     * docs/tech-debt.md).
     *
     * Deliberately coarse: no exact counts, no ages, no partner attributes —
     * casting-purpose signal only (the AH-050 review file carries the GDPR
     * purpose reasoning). Empty selection and null both mean "undisclosed".
     *
     * @var list<string>
     */
    public const array CONTENT_COMPANION_KEYS = [
        'partner',
        'baby_toddler',
        'young_kids',
        'teens',
        'adult_children',
        'parents_grandparents',
        'extended_family_friends',
        'pets_dogs',
        'pets_cats',
        'pets_other',
        'roommates',
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
            // Spoken-language metadata: validated against the full world
            // ISO 639-1 set (creators come from anywhere, so speaker
            // metadata is legitimately the world set).
            'primary_language' => ['sometimes', 'string', Rule::in(Locale::WORLD_LANGUAGES)],
            'secondary_languages' => ['sometimes', 'array'],
            'secondary_languages.*' => ['string', Rule::in(Locale::WORLD_LANGUAGES)],
            // Free-text accent / dialect hint shown next to the spoken
            // language (e.g. "British", "Brazilian"). Display-only signal,
            // deliberately not an enum.
            'accent' => ['sometimes', 'nullable', 'string', 'max:80'],
            'categories' => ['sometimes', 'array', 'min:1', 'max:28'],
            'categories.*' => [
                'string',
                'in:lifestyle,sports,beauty,fashion,food,travel,gaming,tech,music,art,fitness,parenting,business,education,comedy,pets,photography,home,health,finance,automotive,entertainment,design,dance,sustainability,news,science,other',
            ],
            // AH-050 — "Who appears in your content?" companion multi-select.
            // No min:1 (unlike categories): an EMPTY array is a valid save
            // meaning "undisclosed", identical to null. Every stored value is
            // a deliberate individual disclosure (D3).
            'content_companions' => ['sometimes', 'nullable', 'array', 'max:11'],
            'content_companions.*' => ['string', Rule::in(self::CONTENT_COMPANION_KEYS)],
        ];
    }
}
