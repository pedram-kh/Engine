<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Requests;

use App\Modules\Agencies\Models\Agency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'description' => ['nullable', 'string', 'max:2000'],
            'industry' => ['nullable', 'string', 'max:64'],
            'website_url' => ['nullable', 'string', 'url', 'max:2048'],
            'default_currency' => ['sometimes', 'string', 'size:3'],
            'default_language' => ['sometimes', 'string', Rule::in(['en', 'pt', 'it'])],
            'brand_safety_rules' => ['nullable', 'array'],
            'client_portal_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
