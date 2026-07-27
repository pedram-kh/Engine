<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Requests;

use App\Core\Enums\Locale;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Http\Controllers\BrandController;
use App\Modules\Brands\Models\Brand;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Brand edit, post-floor (AH-053, D6/A2).
 *
 * Partial PATCH semantics are PRESERVED — every field stays `sometimes`, so an
 * omitted field keeps its stored value (§5.34 preserve-by-omission). What is
 * new is a refusal: {@see withValidator()} rejects any edit that would LEAVE
 * the brand below the floor. Two consequences, both intended:
 *
 *   - An already-complete brand is unaffected unless the edit itself empties a
 *     floor field.
 *   - An existing incomplete brand — the production reality this ships into —
 *     hard-blocks on its next edit until the missing fields are supplied. It
 *     is never touched at rest: reading, listing, campaign attachment and
 *     archiving all keep working. This is a NEW REFUSAL, not a new write.
 *
 * Shape choice (A2): a merged-state PREDICATE, not "require the full payload".
 * Demanding all six fields on every PATCH would force clients to echo values
 * they never fetched — the exact mechanic behind the AH-032 brief wipe, where
 * a form that re-sent a field it did not render blanked stored content. The
 * predicate asks the only question that matters: after this write lands, is
 * the brand complete?
 *
 * `logo_path` is part of the floor but is not settable here (D7 — the upload
 * endpoint owns it), so a logo-less brand is blocked from editing until a logo
 * is uploaded. The SPA mirrors this by requiring the logo control before save.
 *
 * NOT gated: restore. A restore is a lifecycle action on the archived row, not
 * an edit of its content, and it routes through
 * {@see BrandController::restore()},
 * which takes no payload and never reaches this request. Un-archiving an
 * incomplete brand therefore succeeds; the NEXT real edit is what gates. That
 * is pinned by a named test rather than left to routing accident.
 */
final class UpdateBrandRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $agency = $this->route('agency');
        $agencyId = $agency instanceof Agency ? $agency->id : null;

        $brand = $this->route('brand');
        $brandId = $brand instanceof Brand ? $brand->id : null;

        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'slug' => [
                'sometimes',
                'string',
                'max:64',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('brands', 'slug')
                    ->where('agency_id', $agencyId)
                    ->ignore($brandId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:64'],
            'website_url' => ['sometimes', 'nullable', 'string', 'url', 'max:2048'],
            'default_currency' => ['sometimes', 'string', 'size:3'],
            'default_language' => ['sometimes', 'string', Rule::enum(Locale::class)],
            'brand_safety_rules' => ['sometimes', 'nullable', 'array'],
            'client_portal_enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $brand = $this->route('brand');
            if (! $brand instanceof Brand) {
                return;
            }

            // Only the fields the payload actually carries count as overrides;
            // everything else is read from the stored row. `$this->all()` is
            // used rather than `validated()` because this runs after the field
            // rules and must see even values that failed them (a floor field
            // rejected for length is already reported; naming it twice adds
            // nothing, and it is genuinely present).
            $overrides = array_intersect_key($this->all(), array_flip(Brand::FLOOR_FIELDS));

            foreach ($brand->floorMissingFields($overrides) as $field) {
                // The logo has no payload field to attach an inline error to on
                // the form, so it gets a message of its own.
                $validator->errors()->add($field, $field === 'logo_path'
                    ? 'A brand logo is required. Upload one before saving.'
                    : 'This field is required — a brand cannot be left incomplete.');
            }
        });
    }
}
