<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Resources;

use App\Modules\Brands\Http\Resources\BrandResource;
use App\Modules\Brands\Services\BrandLogoUploadService;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Creators\Enums\JobLifecycleState;
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
 * `assignment_state` is the coarse lifecycle reflection (AH-059, D5) and the
 * field that settles D1's contradiction — see {@see callerAssignmentState()} for
 * both, including why it lives on the CARD while `assignment_ulid` does not.
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
            // The coarse lifecycle reflection (AH-059, D5) — see below.
            'assignment_state' => $this->callerAssignmentState($campaign),
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

    /**
     * The COARSE lifecycle reflection (AH-059, D5) — `in_progress` / `completed`
     * / `ended`, or null when the caller's pair has no assignment.
     *
     * Three of the sixteen assignment statuses reach a creator here, by
     * {@see JobLifecycleState}'s single exhaustive mapping. The 16-state machine
     * is an agency-side instrument and stays one: a creator does not need to be
     * told the difference between `producing` and `revision_requested` on a job
     * card, and the fine states are readable on the assignment itself, which the
     * detail links to.
     *
     * ⚠ **This field is also what settles D1.** The SPA renders it BEFORE the
     * application's own status, so a rejected application can never put
     * "Not selected" beside a live invitation for the same campaign. The
     * consequence is deliberate (Q2): whenever an assignment exists it wins —
     * including an `ended` one — so a pair that was ever invited never reads
     * "Not selected" again. The agency's last act on that pair was an invitation,
     * not a refusal.
     *
     * ⚠ **Emitted on the card as well as the detail**, unlike `assignment_ulid`.
     * That is not a reversal of chunk 4's D7 (which put the BRIDGE on the detail
     * because the card has no link to give) — it is the same reasoning applied to
     * a different field: without the state on the card, the card keeps telling the
     * contradiction the detail has stopped telling.
     *
     * Read-only and derived at read time. No column, no event, no sync: nothing
     * persists this, so it cannot go stale against the assignment it reflects.
     */
    private function callerAssignmentState(Campaign $campaign): ?string
    {
        return JobLifecycleState::tryFromAssignmentStatusValue(
            $campaign->getAttribute('caller_assignment_status'),
        )?->value;
    }
}
