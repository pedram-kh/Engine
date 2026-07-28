<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/_test/agencies/{agency}/pending-applications
 *
 * One-shot provisioning for the AH-058 agency-side applications leg: a listed
 * campaign on the GIVEN agency, plus N rostered creators who have applied to it
 * and are still pending.
 *
 * It is a SIBLING of {@see CreateListedJobController}, not an extension of it,
 * and the difference is the whole reason this file exists: that helper is
 * CREATOR-keyed (it takes the signed-in creator's email and provisions a fresh
 * agency with no agency user attached), while this leg is agency-side and needs
 * a campaign on an agency the spec can already sign into. Teaching one helper
 * both modes would give it two mutually exclusive shapes selected by which
 * field the caller sent — the §5.26 smell. So this one is agency-keyed, exactly
 * like {@see CreateRosterCreatorsController}, whose shape it follows.
 *
 * Applications are inserted directly rather than driven through
 * POST /creators/me/jobs/{campaign}/apply: that would mean N creator sign-ins
 * (each needing an approved creator account and a TOTP round trip) before the
 * agency spec's first assertion. The apply path is proven by its own feature
 * tests; this helper exists so the E2E leg can start at the interesting part.
 *
 * Gated by VerifyTestHelperToken (404 when closed), validates its inputs (422
 * on failure), no production wiring.
 *
 * Request body:
 *   - `applicants`                  — array, required, 1..25 rows.
 *   - `applicants.*.display_name`   — string, required.
 *   - `applicants.*.note`           — string|null, optional (the applicant's note).
 *   - `campaign_name`               — string|null, optional.
 *   - `brand_name`                  — string|null, optional.
 *
 * Response (201):
 *   { "data": { "agency_ulid", "campaign_ulid", "campaign_name", "brand_name",
 *               "applications": [ { "application_ulid", "creator_ulid", "display_name" } ] } }
 */
final class CreatePendingApplicationsController
{
    public function __invoke(Request $request, Agency $agency): JsonResponse
    {
        try {
            $validated = $request->validate([
                'applicants' => ['required', 'array', 'min:1', 'max:25'],
                'applicants.*.display_name' => ['required', 'string', 'max:160'],
                'applicants.*.note' => ['nullable', 'string', 'max:1000'],
                'campaign_name' => ['nullable', 'string', 'max:255'],
                'brand_name' => ['nullable', 'string', 'max:255'],
            ]);
        } catch (ValidationException $e) {
            return new JsonResponse([
                'error' => 'validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        /** @var Brand $brand */
        $brand = Brand::factory()->forAgency($agency->id)->create([
            'name' => $this->text($validated, 'brand_name', 'Northwind Coffee'),
        ]);

        /** @var Campaign $campaign */
        $campaign = Campaign::factory()->create([
            'agency_id' => $agency->id,
            'brand_id' => $brand->id,
            'name' => $this->text($validated, 'campaign_name', 'Autumn UGC push'),
            'status' => CampaignStatus::Active,
            'listed_on_jobs_board' => true,
            // Stamped directly: this helper never routes through
            // CampaignController::update(), which is where the flip detector
            // normally stamps it.
            'listed_at' => now(),
            'listing_fee' => '€300 per video',
            'listing_duration' => '4 weeks',
            'listing_languages' => ['en', 'pt'],
            'listing_regions' => ['PT', 'ES'],
            // Never expires, so a long-lived branch cannot start failing the
            // spec on a date rollover.
            'ends_at' => null,
        ]);

        /** @var list<array<string, mixed>> $applicantsInput */
        $applicantsInput = $validated['applicants'];

        $applications = [];
        foreach ($applicantsInput as $input) {
            /** @var Creator $creator */
            $creator = Creator::factory()->create([
                'display_name' => $input['display_name'],
                'application_status' => ApplicationStatus::Approved,
            ]);

            // Applicants are rostered BY DEFINITION — the board only shows a
            // creator jobs from agencies that have them on the roster — so the
            // relation is part of the seeded state, not an extra.
            AgencyCreatorRelation::factory()->create([
                'agency_id' => $agency->id,
                'creator_id' => $creator->id,
                'relationship_status' => RelationshipStatus::Roster,
                'is_blacklisted' => false,
            ]);

            /** @var CampaignApplication $application */
            $application = CampaignApplication::factory()->create([
                'agency_id' => $agency->id,
                'campaign_id' => $campaign->id,
                'creator_id' => $creator->id,
                'status' => CampaignApplicationStatus::Pending,
                'note' => is_string($input['note'] ?? null) ? $input['note'] : null,
                'responded_at' => null,
            ]);

            $applications[] = [
                'application_ulid' => $application->ulid,
                'creator_ulid' => $creator->ulid,
                'display_name' => $creator->display_name,
            ];
        }

        return new JsonResponse([
            'data' => [
                'agency_ulid' => $agency->ulid,
                'campaign_ulid' => $campaign->ulid,
                'campaign_name' => $campaign->name,
                'brand_name' => $brand->name,
                'applications' => $applications,
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
