<?php

declare(strict_types=1);

namespace App\Modules\TalentPools\Http\Requests;

use App\Modules\Agencies\Models\Agency;
use App\Modules\TalentPools\Models\TalentPool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a pool edit (Sprint 6 Chunk 2b, D-2b-6). All fields are
 * `sometimes` so a partial edit is valid. The name-uniqueness rule ignores
 * the pool being edited so re-saving the same name is not a false 422.
 */
final class UpdateTalentPoolRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $agency = $this->route('agency');
        $agencyId = $agency instanceof Agency ? $agency->id : null;

        $pool = $this->route('talentPool');
        $poolId = $pool instanceof TalentPool ? $pool->id : null;

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:160',
                Rule::unique('talent_pools', 'name')
                    ->where('agency_id', $agencyId)
                    ->ignore($poolId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'brand_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('brands', 'ulid')->where('agency_id', $agencyId),
            ],
        ];
    }
}
