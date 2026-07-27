<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Resources;

use App\Modules\Brands\Http\Resources\BrandResource;
use App\Modules\Brands\Services\BrandLogoUploadService;
use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One CARD on the creator's jobs board (Jobs Board chunk 3, AH-056, D3/D4).
 *
 * ⚠ This resource is the arc's first AH-005-class boundary crossing: brand data
 * reaching a CREATOR audience. Before it, a creator could see exactly ONE brand
 * field anywhere on the platform — `brand_name`, hand-copied onto an assignment
 * row they were already party to. {@see BrandResource} emits the whole brand
 * (slug, description, industry, safety rules, currency/language defaults,
 * client-portal flag) and has only ever been served behind the brand policy,
 * which a creator is not behind.
 *
 * So the crossing is made by a DEDICATED NARROW resource, never by serving
 * `BrandResource` to a creator (D3). The card carries exactly two brand fields:
 *
 *   - `name`     — the creator is applying to work for a brand; withholding its
 *                  name would make the whole board unreadable.
 *   - `logo_url` — a short-lived signed GET, minted per-emission on the private
 *                  media disk. The MECHANISM is unchanged from AH-053 (D7);
 *                  only the audience is new.
 *
 * And NOTHING else. No description, no monthly-deliverables copy (agency
 * internal), no slug, status, industry, safety rules, default currency,
 * default language or portal flag. The detail resource
 * ({@see CreatorJobDetailResource}) adds `website_url` and nothing more.
 *
 * The keyset is pinned by an exact-equality assertion in the feature test — not
 * a "has these keys" check — so a brand field cannot join this payload by
 * accretion. That assertion is the enforcement; this docblock is only the
 * reason.
 *
 * ── Two things the card deliberately shows ──────────────────────────────────
 *
 * `applicant_count` is the first creator-visible aggregate over OTHER
 * creators' behaviour on this platform. It is non-identifying (a bare integer),
 * it counts every application status (pending + accepted + rejected — "how much
 * interest does this job have", D4), and it is deliberate rather than
 * incidental.
 *
 * `listed_at` powers the recency chip and is the honest answer to it: it is
 * stamped only on the listing flip, so it cannot drift the way `updated_at`
 * would (which moves on every unrelated Settings save and would let a campaign
 * listed in March claim "Listed today"). Emitted as an ISO timestamp; the SPA
 * owns the "Listed today / N days ago" wording because that is i18n. Null is
 * renderable — the chip simply does not appear.
 *
 * @mixin Campaign
 */
class CreatorJobCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $campaign = $this->resource;
        assert($campaign instanceof Campaign);

        return [
            'id' => $campaign->ulid,
            'type' => 'creator_job',
            'attributes' => $this->cardAttributes($campaign),
        ];
    }

    /**
     * The card attribute block, shared with the detail resource so the two
     * shapes cannot drift on the fields they have in common.
     *
     * @return array<string, mixed>
     */
    final protected function cardAttributes(Campaign $campaign): array
    {
        return [
            'name' => $campaign->name,
            'listing_fee' => $campaign->listing_fee,
            'listing_duration' => $campaign->listing_duration,
            // Annotated by the controller via withCount — never lazy-loaded,
            // so a 25-card page stays one query.
            'applicant_count' => (int) ($campaign->getAttribute('applications_count') ?? 0),
            'listed_at' => $campaign->listed_at?->toIso8601String(),
            // The CALLER's own application status for this job, annotated by
            // the controller in one correlated subquery. null ⟹ never applied.
            // It is the caller's own datum — never any other creator's.
            'application_status' => $this->callerApplicationStatus($campaign),
            'brand' => $this->brandSubset($campaign),
        ];
    }

    /**
     * The D3 brand subset — two fields on the card. Read from the campaign's
     * `withTrashed()` brand relation on purpose: archiving a brand is a soft
     * delete, and a campaign that is still LISTED must keep rendering its brand
     * as stored. Listing state alone decides visibility; an archived brand does
     * not silently blank a live card (and the July-Wave-4 production incident
     * is why `Campaign::brand()` is `withTrashed()` at all).
     *
     * @return array<string, mixed>|null
     */
    final protected function brandSubset(Campaign $campaign): ?array
    {
        $brand = $campaign->brand;

        if ($brand === null) {
            return null;
        }

        return [
            'name' => $brand->name,
            'logo_url' => BrandLogoUploadService::signedViewUrl($brand->logo_path),
        ];
    }

    private function callerApplicationStatus(Campaign $campaign): ?string
    {
        $status = $campaign->getAttribute('caller_application_status');

        return is_string($status) ? $status : null;
    }
}
