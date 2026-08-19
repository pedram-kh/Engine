<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Resources;

use App\Modules\Brands\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The thin `{id, name}` projection for `<select>` pickers (AH-085).
 *
 * Every brand-select consumer — the campaign create form, the campaigns-list
 * brand filter, the pool create/edit form, and the blacklist dialog's
 * brand-scope picker — needs nothing heavier than a brand's id and name: not
 * the signed `logo_url`, not `status`, not timestamps, not the `agency`
 * relationship {@see BrandResource} carries for the Brands admin page's own
 * data table. Keeping the same `{id, type, attributes}` envelope shape as
 * {@see BrandResource} (just with a narrower `attributes`) means every
 * existing consumer's `.attributes.name` mapping code needs no change beyond
 * which endpoint it calls.
 *
 * @mixin Brand
 */
final class BrandOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $brand = $this->resource;
        assert($brand instanceof Brand);

        return [
            'id' => $brand->ulid,
            'type' => 'brands',
            'attributes' => [
                'name' => $brand->name,
            ],
        ];
    }
}
