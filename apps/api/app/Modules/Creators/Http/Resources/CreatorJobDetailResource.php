<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Resources;

use App\Modules\Campaigns\Http\Resources\CreatorCampaignAttachmentResource;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
 *   - `assignment_ulid`       — the D7 bridge (chunk 4, AH-058): the caller's
 *                               OWN assignment on this campaign, annotated by
 *                               the controller in one correlated subquery.
 *                               ALWAYS present, null when the pair has no
 *                               assignment — a key that appeared only for
 *                               accepted applicants would make the exact-keyset
 *                               assertion data-dependent, and the page's
 *                               accepted notice renders with or without the
 *                               link. Never inferred from
 *                               `application_status === 'accepted'`: the
 *                               application and the assignment are separate
 *                               rows and can disagree.
 *   - `attachments`           — Campaign attachments from the Files library,
 *                               chunk B (AH-092, D4). The controller loads the
 *                               relation pre-filtered through
 *                               `CampaignAttachment::scopeCreatorVisible()`
 *                               (flag ON + live file), AFTER its own
 *                               `JobsBoardVisibility` gate has already run —
 *                               this key only ever renders what already
 *                               cleared both doors. Always an array, `[]`
 *                               when nothing is attached or nothing is
 *                               flagged visible.
 *
 * `assignment_state` (AH-059, D5) is NOT in that list, because it is not a detail
 * addition: it lives on the shared card block, so both shapes carry it. The
 * asymmetry with `assignment_ulid` is deliberate and documented on
 * {@see CreatorJobCardResource::callerAssignmentState()} — the card renders the
 * STATE, the detail additionally offers the LINK.
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
                'assignment_ulid' => $this->callerAssignmentUlid($campaign),
                'attachments' => CreatorCampaignAttachmentResource::collection($this->creatorVisibleAttachments($campaign))->resolve($request),
                'brand' => $brand,
            ],
        ];
    }

    private function callerAssignmentUlid(Campaign $campaign): ?string
    {
        $ulid = $campaign->getAttribute('caller_assignment_ulid');

        return is_string($ulid) ? $ulid : null;
    }

    /**
     * Deliberately reads ONLY the eager-loaded, pre-filtered relation — never
     * `$campaign->attachments` directly, which would trigger a fresh,
     * UNFILTERED lazy-load bypassing `creatorVisible()` if this resource is
     * ever reached without the controller's own filtered `->load()` call.
     * Failing to `[]` when unloaded is the fail-closed posture (D4 review
     * priority #1): a missing eager-load must never silently leak every
     * attachment instead of none.
     *
     * @return Collection<int, CampaignAttachment>
     */
    private function creatorVisibleAttachments(Campaign $campaign): Collection
    {
        return $campaign->relationLoaded('attachments') ? $campaign->attachments : collect();
    }
}
