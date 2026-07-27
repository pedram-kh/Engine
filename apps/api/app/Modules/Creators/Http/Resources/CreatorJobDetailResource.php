<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Resources;

use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Http\Request;

/**
 * The creator's JOB DETAIL page (Jobs Board chunk 3, AH-056, D3/D4).
 *
 * Extends the card rather than restating it, so the two shapes cannot drift on
 * their shared fields, and adds exactly what the detail page renders:
 *
 *   - `description`           — the job copy. This is the CAMPAIGN's
 *                               description (the field AH-053 D8 relabelled and
 *                               AH-054 made a listing-floor requirement), NOT
 *                               the brand's. The brand's description is
 *                               agency-internal and never crosses.
 *   - `listing_languages`     — the 24-EU-validated production languages.
 *   - `listing_regions`       — ISO-3166-1 alpha-2 operating markets.
 *   - `listing_examples_url`  — the agency's reference-work link. Rendered as
 *                               an external anchor with `rel="noopener"` by the
 *                               SPA; emitted raw here.
 *   - `brand.website_url`     — the third and LAST brand field to cross to a
 *                               creator audience (D3: the client's "link to the
 *                               brand's website" ask, detail page only). Adding
 *                               a fourth is a decision, not a patch.
 *
 * Everything the card withholds, the detail withholds too. The exact-keyset
 * assertion in the feature test covers BOTH shapes.
 *
 * @mixin Campaign
 */
final class CreatorJobDetailResource extends CreatorJobCardResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $campaign = $this->resource;
        assert($campaign instanceof Campaign);

        $brand = $this->brandSubset($campaign);

        if ($brand !== null) {
            $brand['website_url'] = $campaign->brand?->website_url;
        }

        return [
            'id' => $campaign->ulid,
            'type' => 'creator_job',
            'attributes' => [
                ...$this->cardAttributes($campaign),
                'description' => $campaign->description,
                'listing_languages' => $campaign->listing_languages,
                'listing_regions' => $campaign->listing_regions,
                'listing_examples_url' => $campaign->listing_examples_url,
                'brand' => $brand,
            ],
        ];
    }
}
