<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Http\Controllers\CampaignController;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/_test/agencies/{agency}/listable-campaign
 *
 * One-shot provisioning for the AH-059 full-lifecycle E2E leg (D6): on the
 * GIVEN agency, a campaign that is floor-COMPLETE but NOT YET LISTED, plus the
 * signed-in creator rostered and approved against that agency.
 *
 * ── Why "listable" and not "listed" ────────────────────────────────────────
 *
 * Every other jobs-board helper hands the spec a campaign that is already on
 * the board, because those specs start downstream of the listing. This one
 * exists precisely so the spec can perform the listing ITSELF — the D3 toggle
 * is the first step of the full loop, and a helper that pre-listed the campaign
 * would silently delete the step under test. So the floor is filled (the toggle
 * must be reachable, not refused) and the flag is left OFF (the toggle must
 * have something to do).
 *
 * `listed_at` is therefore NULL, deliberately: the flip through
 * {@see CampaignController::update()}
 * is what stamps it, and pre-stamping would hide a regression in the detector.
 * The sibling helpers stamp it by hand only because they never route through
 * that path at all.
 *
 * ── Why `requires_per_campaign_contract` is forced FALSE ────────────────────
 *
 * The loop ends with the creator ACCEPTING the offer. With the per-campaign
 * contract required, that accept is legitimately refused until a contract is
 * attached — a different, already-covered branch. Pinning it false here keeps
 * the E2E leg on the path it is meant to prove rather than making the spec
 * discover a gate it was never about.
 *
 * ── Why a sibling, not a mode on an existing helper ─────────────────────────
 *
 * It is AGENCY-keyed like {@see CreateRosterCreatorsController} and
 * {@see CreatePendingApplicationsController}, because a cross-role spec has to
 * sign in as the agency it seeded. {@see CreateListedJobController} is
 * creator-keyed and provisions its own fresh agency that no agency user can
 * sign into — the same reason AH-058 added a sibling rather than a second mode.
 * Teaching one helper both shapes would produce a controller whose behaviour is
 * selected by which field the caller sent (the §5.26 smell).
 *
 * What it does NOT do, on purpose: no application, no assignment, no board
 * card. Those are the loop, and the loop is the spec's job.
 *
 * Gated by VerifyTestHelperToken (404 when closed), validates its inputs (422
 * on failure), no production wiring.
 *
 * Request body:
 *   - `email`          — string, required. The signed-in CREATOR's account email.
 *   - `campaign_name`  — string|null, optional.
 *   - `brand_name`     — string|null, optional.
 *
 * Response (201):
 *   { "data": { "agency_ulid", "campaign_ulid", "campaign_name", "brand_name",
 *               "creator_ulid", "creator_display_name" } }
 */
final class CreateListableCampaignController
{
    public function __invoke(Request $request, Agency $agency): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'string', 'email:rfc', 'max:254'],
                'campaign_name' => ['nullable', 'string', 'max:255'],
                'brand_name' => ['nullable', 'string', 'max:255'],
            ]);
        } catch (ValidationException $e) {
            return new JsonResponse([
                'error' => 'validation failed',
                'errors' => $e->errors(),
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

        // The board's first leg is the approved gate, and no production path
        // approves a self-signed-up creator — that is admin-only, on a surface
        // this spec does not drive.
        $creator->application_status = ApplicationStatus::Approved;
        if ($creator->approved_at === null) {
            $creator->approved_at = now();
        }
        $creator->save();

        // Rostered against the agency the spec signs into: the board only shows
        // a creator jobs from agencies that already have them on the roster.
        AgencyCreatorRelation::query()->updateOrCreate(
            ['agency_id' => $agency->id, 'creator_id' => $creator->id],
            ['relationship_status' => RelationshipStatus::Roster, 'is_blacklisted' => false],
        );

        /** @var Brand $brand */
        $brand = Brand::factory()->forAgency($agency->id)->create([
            'name' => $this->text($validated, 'brand_name', 'Northwind Coffee'),
            'website_url' => 'https://northwind.example',
        ]);

        /** @var Campaign $campaign */
        $campaign = Campaign::factory()->create([
            'agency_id' => $agency->id,
            'brand_id' => $brand->id,
            'name' => $this->text($validated, 'campaign_name', 'Winter UGC push'),
            'status' => CampaignStatus::Active,

            // NOT listed, and NOT stamped — the spec's own toggle does both.
            'listed_on_jobs_board' => false,
            'listed_at' => null,

            // …but floor-COMPLETE, so the toggle is accepted rather than 422'd
            // on five field errors. Mirrors ValidatesJobsBoardListing's
            // LISTING_FLOOR_FIELDS exactly: description, duration, fee,
            // languages, regions.
            'description' => 'Three short-form videos per month, shot in your own kitchen.',
            'listing_duration' => '4 weeks',
            'listing_fee' => '€300 per video',
            'listing_languages' => ['en', 'pt'],
            'listing_regions' => ['PT', 'ES'],
            'listing_examples_url' => 'https://examples.example/work',

            // See the class docblock: the creator's offer-accept must not land
            // on the contract gate, which is a different branch with its own
            // coverage.
            'requires_per_campaign_contract' => false,

            // Never expires, so a long-lived branch cannot start failing the
            // spec on a date rollover.
            'ends_at' => null,
        ]);

        return new JsonResponse([
            'data' => [
                'agency_ulid' => $agency->ulid,
                'campaign_ulid' => $campaign->ulid,
                'campaign_name' => $campaign->name,
                'brand_name' => $brand->name,
                'creator_ulid' => $creator->ulid,
                'creator_display_name' => $creator->display_name,
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
