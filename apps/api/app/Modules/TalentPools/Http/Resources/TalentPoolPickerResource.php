<?php

declare(strict_types=1);

namespace App\Modules\TalentPools\Http\Resources;

use App\Modules\TalentPools\Models\TalentPool;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row in the add-to-pool picker dialog (Sprint 6 Chunk 2b, D-2b-9): the
 * pool plus an `is_member` flag for the creator the dialog was opened for.
 *
 * `is_member` is computed in ONE query via `withExists` scoped to the creator
 * (the controller aliases it to the `creators_exists` attribute) — NOT an N+1
 * across pools (the honest-deviation fetch-shape flag). The picker only needs
 * the toggle state, so this is a slim shape: no counts, no member preview.
 *
 * @mixin TalentPool
 */
final class TalentPoolPickerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pool = $this->resource;
        assert($pool instanceof TalentPool);

        return [
            'id' => $pool->ulid,
            'type' => 'talent_pools',
            'attributes' => [
                'name' => $pool->name,
                'brand_name' => $pool->brand?->name,
                'is_member' => (bool) ($pool->creators_exists ?? false),
            ],
        ];
    }
}
