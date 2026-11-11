<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\ContractKind;
use App\Modules\Creators\Enums\ContractStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Contract;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/_test/agencies/{agency}/contracted-assignment
 *
 * One-shot provisioning for the AH-069 (D9) hand-off-lifecycle E2E leg: on the
 * GIVEN agency, an active campaign with its board provisioned, plus the named
 * creator approved, rostered, and holding a CONTRACTED assignment — the exact
 * state from which a creator may submit a draft.
 *
 * ── Why it starts at `contracted` ──────────────────────────────────────────
 *
 * The leg under test is approval → completion, and everything before it —
 * listing, applying, offering, accepting — already has an end-to-end spec of
 * its own (jobs-board-full-lifecycle). Re-walking those four steps here would
 * add four minutes of runtime and four unrelated reasons to go red. So the
 * helper drops the spec at the last state before the part that is new.
 *
 * ── Why `creator_posts_content` is left ON ─────────────────────────────────
 *
 * Deliberately, and it is the point of the leg: the spec turns it off ITSELF,
 * through the real Settings switch, which is how D1's write path gets covered
 * in the same run. A helper that pre-set the toggle would delete the step under
 * test — the {@see CreateListableCampaignController} reasoning, verbatim.
 *
 * ── Why the board is provisioned here ──────────────────────────────────────
 *
 * {@see BoardService::forCampaign()} is what the GET does anyway (lazy
 * provisioning, D-4). Calling it here means the card exists and is healed onto
 * the column its status implies BEFORE the spec looks, so an assertion about
 * which columns render is not racing the provisioner.
 *
 * Gated by VerifyTestHelperToken (404 when closed), validates its inputs (422
 * on failure), no production wiring.
 *
 * Request body:
 *   - `email`          — string, required. The signed-in CREATOR's account email.
 *   - `campaign_name`  — string|null, optional.
 *   - `brand_name`     — string|null, optional.
 *   - `status`         — `invited` | `contracted` (default), optional (AH-118).
 *   - `contract_sent`  — bool, optional (AH-118). Adds a `sent` contract (lock reason `contract_pending`).
 *   - `restrict_to_listing_regions`, `listing_regions`, `creator_country` — optional (AH-118).
 *   - `exclusive_brand_ids` — string[], optional (AH-120). ULIDs of THIS agency's brands the new
 *                             campaign blocks (the `contracted` creator is selected on it, so
 *                             they are blocked from those brands). Written directly, bypassing
 *                             the flag: arming it is the spec's own step.
 *   - `exclusivity_ends_on` — Y-m-d|null, optional (AH-120). Written as given, past dates included.
 *
 * Response (201):
 *   { "data": { "agency_ulid", "campaign_ulid", "campaign_name", "brand_name",
 *               "creator_ulid", "creator_display_name", "assignment_ulid" } }
 */
final class CreateContractedAssignmentController
{
    public function __invoke(Request $request, Agency $agency, BoardService $boards): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'string', 'email:rfc', 'max:254'],
                'campaign_name' => ['nullable', 'string', 'max:255'],
                'brand_name' => ['nullable', 'string', 'max:255'],
                // AH-118 — `invited` seeds an editable offer (EUR 100.00); default stays contracted.
                'status' => ['sometimes', 'string', 'in:invited,contracted'],
                // AH-118 — a contract in `sent` status on the assignment (lock reason `contract_pending`).
                'contract_sent' => ['sometimes', 'boolean'],
                'restrict_to_listing_regions' => ['sometimes', 'boolean'],
                'listing_regions' => ['sometimes', 'array', 'max:10'],
                'listing_regions.*' => ['string', 'size:2'],
                'creator_country' => ['nullable', 'string', 'size:2'],
                // AH-120 — the blocked-brand list and end date of the campaign this creates.
                'exclusive_brand_ids' => ['sometimes', 'array', 'max:50'],
                'exclusive_brand_ids.*' => ['string', 'distinct'],
                'exclusivity_ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            ]);
        } catch (ValidationException $e) {
            return new JsonResponse([
                'error' => 'validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        /** @var array<int, string> $exclusiveUlids */
        $exclusiveUlids = $validated['exclusive_brand_ids'] ?? [];
        $exclusiveBrandIds = $exclusiveUlids === []
            ? []
            : Brand::query()->where('agency_id', $agency->id)->whereIn('ulid', $exclusiveUlids)->pluck('id')->all();

        if (count($exclusiveBrandIds) !== count($exclusiveUlids)) {
            return new JsonResponse([
                'error' => 'validation failed',
                'errors' => ['exclusive_brand_ids' => ['Every brand must belong to this agency.']],
            ], 422);
        }

        /** @var string $email */
        $email = $validated['email'];

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();
        $creator = $user?->creator;

        if ($creator === null) {
            return new JsonResponse([
                'error' => 'creator not found',
                'errors' => ['email' => ['No creator is associated with this email.']],
            ], 422);
        }

        $creator->application_status = ApplicationStatus::Approved;
        if ($creator->approved_at === null) {
            $creator->approved_at = now();
        }
        if (isset($validated['creator_country'])) {
            $creator->country_code = $validated['creator_country'];
        }
        $creator->save();

        AgencyCreatorRelation::query()->updateOrCreate(
            ['agency_id' => $agency->id, 'creator_id' => $creator->id],
            ['relationship_status' => RelationshipStatus::Roster, 'is_blacklisted' => false],
        );

        /** @var Brand $brand */
        $brand = Brand::factory()->forAgency($agency->id)->create([
            'name' => $this->text($validated, 'brand_name', 'Halden Studio'),
            'website_url' => 'https://halden.example',
        ]);

        /** @var Campaign $campaign */
        $campaign = Campaign::factory()->create([
            'agency_id' => $agency->id,
            'brand_id' => $brand->id,
            'name' => $this->text($validated, 'campaign_name', 'Spring lookbook'),
            'status' => 'active',
            'description' => 'One short-form video, delivered as a link.',
            'requires_per_campaign_contract' => false,

            // ON — the spec turns it off through the Settings switch. See the
            // class docblock.
            'creator_posts_content' => true,

            // AH-118 — optional region rule.
            'listing_regions' => $validated['listing_regions'] ?? null,
            'restrict_to_listing_regions' => (bool) ($validated['restrict_to_listing_regions'] ?? false),

            'ends_at' => null,
            'exclusivity_ends_on' => $validated['exclusivity_ends_on'] ?? null,
        ]);

        if ($exclusiveBrandIds !== []) {
            $campaign->exclusiveBrands()->sync($exclusiveBrandIds);
        }

        $invited = ($validated['status'] ?? 'contracted') === 'invited';

        /** @var CampaignAssignment $assignment */
        $assignment = CampaignAssignment::factory()
            ->status($invited ? AssignmentStatus::Invited : AssignmentStatus::Contracted)
            ->create([
                'agency_id' => $agency->id,
                'campaign_id' => $campaign->id,
                'brand_id' => $brand->id,
                'creator_id' => $creator->id,
                'invited_at' => now()->subDay(),
                'accepted_at' => $invited ? null : now()->subDay(),
                'agreed_fee_minor_units' => 10000,
                'agreed_fee_currency' => 'EUR',
            ]);

        if ((bool) ($validated['contract_sent'] ?? false)) {
            Contract::query()->create([
                'agency_id' => $agency->id,
                'kind' => ContractKind::PerCampaign,
                'subject_type' => Contract::SUBJECT_CAMPAIGN_ASSIGNMENT,
                'subject_id' => $assignment->id,
                'version' => 1,
                'title' => 'Addendum',
                'body_markdown' => 'Terms.',
                'signature_provider' => Contract::PROVIDER_INTERNAL,
                'status' => ContractStatus::Sent,
                'sent_at' => now(),
            ]);
        }

        $boards->forCampaign($campaign);

        return new JsonResponse([
            'data' => [
                'agency_ulid' => $agency->ulid,
                'campaign_ulid' => $campaign->ulid,
                'campaign_name' => $campaign->name,
                'brand_name' => $brand->name,
                'creator_ulid' => $creator->ulid,
                'creator_display_name' => $creator->display_name,
                'assignment_ulid' => $assignment->ulid,
            ],
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function text(array $validated, string $key, string $fallback): string
    {
        $value = $validated[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
