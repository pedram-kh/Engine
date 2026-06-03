<?php

declare(strict_types=1);

namespace App\Modules\TalentPools\Http\Requests;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates pool creation (Sprint 6 Chunk 2b, D-2b-1). `name` is unique
 * within the agency (the DB constraint is unique_talent_pools_agency_name;
 * this gives the friendly 422 before the round-trip). `brand_id` is the
 * optional brand LABEL (D-2b-4) — accepted as the brand's ULID and resolved
 * to the integer FK by the controller; it must belong to THIS agency.
 */
final class CreateTalentPoolRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $agency = $this->route('agency');
        $agencyId = $agency instanceof Agency ? $agency->id : null;

        return [
            'name' => [
                'required',
                'string',
                'max:160',
                Rule::unique('talent_pools', 'name')->where('agency_id', $agencyId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'brand_id' => [
                'nullable',
                'string',
                Rule::exists('brands', 'ulid')->where('agency_id', $agencyId),
            ],
        ];
    }
}
