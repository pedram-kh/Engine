<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Requests;

use App\Core\Enums\Locale;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Brand creation, post-floor (AH-053, D6).
 *
 * Every floor field except the logo is REQUIRED here: a brand becomes a
 * creator-visible job card in chunk 3, so the platform stops accepting
 * half-filled ones. {@see Brand::FLOOR_FIELDS} is the floor; this request
 * covers five of its six entries.
 *
 * `logo_path` is the exception, and deliberately so. The logo is written only
 * by the upload endpoint (D7), which needs a brand row to hang the object off
 * — so the create flow is: SPA requires a file to be chosen before submit →
 * POST /brands → POST /brands/{brand}/logo. The row therefore exists
 * momentarily without a logo. That non-atomicity is accepted and recorded: a
 * create whose logo upload then fails leaves a floor-incomplete brand, which
 * the D6 edit gate will demand be completed on the next edit. The alternative
 * (multipart brand-create, or a two-phase staging area) buys atomicity at the
 * cost of a second upload pathway.
 *
 * `default_currency` / `default_language` remain accepted (`sometimes`) even
 * though the SPA form no longer offers them (D8): the columns, their defaults
 * and their emission all stay, and an API client that still sends them is
 * still honored. The contract only relaxes.
 */
final class CreateBrandRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $agency = $this->route('agency');
        $agencyId = $agency instanceof Agency ? $agency->id : null;

        return [
            'name' => ['required', 'string', 'max:160'],
            'slug' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('brands', 'slug')->where('agency_id', $agencyId),
            ],
            // The D6 floor — required at create, no longer nullable.
            'description' => ['required', 'string', 'max:2000'],
            'industry' => ['required', 'string', 'max:64'],
            'website_url' => ['required', 'string', 'url', 'max:2048'],
            'default_currency' => ['sometimes', 'string', 'size:3'],
            'default_language' => ['sometimes', 'string', Rule::enum(Locale::class)],
            'brand_safety_rules' => ['nullable', 'array'],
            'client_portal_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
