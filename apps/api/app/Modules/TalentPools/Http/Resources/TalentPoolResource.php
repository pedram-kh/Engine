<?php

declare(strict_types=1);

namespace App\Modules\TalentPools\Http\Resources;

use App\Modules\TalentPools\Models\TalentPool;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of a TalentPool (Sprint 6 Chunk 2b). Follows the same
 * JSON:API-inspired envelope as BrandResource. ULIDs are the public id;
 * integer ids are never exposed.
 *
 * The list carries `creators_count` (D-2b-7) — cheap `withCount('creators')`,
 * NOT a member preview. Member avatars/names are pool-DETAIL weight (the
 * paginated members endpoint), kept out of the list to avoid the N+1
 * signed-avatar trap.
 *
 * @mixin TalentPool
 */
final class TalentPoolResource extends JsonResource
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
                'description' => $pool->description,
                // brand-scope is a LABEL (D-2b-4): the brand's ULID + name when
                // set, null for an agency-wide pool.
                'brand_id' => $pool->brand?->ulid,
                'brand_name' => $pool->brand?->name,
                // No status column (D-2b-1): archive is pure soft-delete, so
                // `is_archived` is derived from deleted_at.
                'is_archived' => $pool->deleted_at !== null,
                // Present whenever the query used withCount('creators').
                'creators_count' => (int) ($pool->creators_count ?? 0),
                'created_at' => $pool->created_at->toIso8601String(),
                'updated_at' => $pool->updated_at->toIso8601String(),
            ],
        ];
    }
}
