<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Resources;

use App\Modules\Brands\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of a Brand for the public API.
 *
 * Follows the JSON:API-inspired envelope from docs/04-API-DESIGN.md §4:
 *   { "data": { "id": "<ulid>", "type": "brands", "attributes": {...} } }
 *
 * ULIDs are used as public identifiers (docs/02-CONVENTIONS.md §2.6).
 * Integer `id` is never exposed.
 *
 * @mixin Brand
 */
final class BrandResource extends JsonResource
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
                'slug' => $brand->slug,
                'description' => $brand->description,
                'industry' => $brand->industry,
                'website_url' => $brand->website_url,
                'logo_path' => $brand->logo_path,
                'default_currency' => $brand->default_currency,
                'default_language' => $brand->default_language,
                'status' => $brand->status->value,
                'brand_safety_rules' => $brand->brand_safety_rules,
                'client_portal_enabled' => $brand->client_portal_enabled,
                'created_at' => $brand->created_at->toIso8601String(),
                'updated_at' => $brand->updated_at->toIso8601String(),
            ],
            'relationships' => [
                'agency' => [
                    'data' => [
                        'id' => $brand->agency->ulid,
                        'type' => 'agencies',
                    ],
                ],
            ],
        ];
    }
}
