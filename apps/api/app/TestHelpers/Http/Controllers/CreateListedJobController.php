<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/_test/creators/listed-job
 *
 * One-shot E2E provisioning for the AH-056 jobs-board Playwright leg. Given the
 * signed-in creator's email, it assembles the entire left-hand side of the
 * visibility predicate:
 *
 *   1. APPROVES the creator (application_status → approved). The board's first
 *      leg is the approved gate and no production path approves a self-signed-up
 *      creator — that is admin-only, on a surface this spec does not drive.
 *   2. Creates an agency and a brand under it.
 *   3. Rosters the creator with that agency (the `roster`, non-blacklisted
 *      relation `permitsMessaging()` requires).
 *   4. Creates a LISTED campaign carrying the full listing floor, with
 *      `listed_at` stamped so the recency chip has something honest to render.
 *
 * Why a helper rather than production paths: reaching this state through the UI
 * would mean an admin approving a creator, an agency admin creating a brand and
 * a campaign, filling the listing floor, and inviting-then-accepting a roster
 * relation — four sign-ins across two SPAs before the spec's first assertion.
 * The predicate itself is proven by the seven-case feature-test set; the E2E leg
 * exists to prove the wiring end to end, and this helper is what lets it start
 * at the interesting part.
 *
 * Follows the CreatePendingConnectionRequestController pattern verbatim: gated
 * by VerifyTestHelperToken (404 when closed), validates its inputs (422 on
 * failure), no production wiring.
 *
 * Request body:
 *   - `email`             — string, required. The signed-in creator's account email.
 *   - `campaign_name`     — string|null, optional.
 *   - `brand_name`        — string|null, optional.
 *   - `agency_name`       — string|null, optional.
 *   - `listing_fee`       — string|null, optional (display-only copy).
 *   - `listing_duration`  — string|null, optional.
 *   - `description`       — string|null, optional (the job copy).
 *
 * Response (201):
 *   { "data": { "campaign_ulid", "campaign_name", "brand_name", "agency_ulid", "creator_ulid" } }
 */
final class CreateListedJobController
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'string', 'email:rfc', 'max:254'],
                'campaign_name' => ['nullable', 'string', 'max:255'],
                'brand_name' => ['nullable', 'string', 'max:255'],
                'agency_name' => ['nullable', 'string', 'max:255'],
                'listing_fee' => ['nullable', 'string', 'max:255'],
                'listing_duration' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
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

        $creator->application_status = ApplicationStatus::Approved;
        if ($creator->approved_at === null) {
            $creator->approved_at = now();
        }
        $creator->save();

        /** @var Agency $agency */
        $agency = AgencyFactory::new()->create([
            'name' => $this->text($validated, 'agency_name', fake()->unique()->company()),
        ]);

        /** @var Brand $brand */
        $brand = Brand::factory()->forAgency($agency->id)->create([
            'name' => $this->text($validated, 'brand_name', 'Northwind Coffee'),
            'website_url' => 'https://northwind.example',
        ]);

        AgencyCreatorRelation::factory()->create([
            'agency_id' => $agency->id,
            'creator_id' => $creator->id,
            'relationship_status' => RelationshipStatus::Roster,
            'is_blacklisted' => false,
        ]);

        /** @var Campaign $campaign */
        $campaign = Campaign::factory()->create([
            'agency_id' => $agency->id,
            'brand_id' => $brand->id,
            'name' => $this->text($validated, 'campaign_name', 'Autumn UGC push'),
            'description' => $this->text(
                $validated,
                'description',
                'Three short-form videos per month, shot in your own kitchen.',
            ),
            'status' => CampaignStatus::Active,
            'listed_on_jobs_board' => true,
            // Stamped directly rather than through the flip detector: this
            // helper never routes through CampaignController::update(), and a
            // null stamp would leave the recency chip unrenderable.
            'listed_at' => now(),
            'listing_fee' => $this->text($validated, 'listing_fee', '€300 per video'),
            'listing_duration' => $this->text($validated, 'listing_duration', '4 weeks'),
            'listing_languages' => ['en', 'pt'],
            'listing_regions' => ['PT', 'ES'],
            'listing_examples_url' => 'https://examples.example/work',
            // Never expires, so a long-lived branch cannot start failing this
            // spec on a date rollover.
            'ends_at' => null,
        ]);

        return new JsonResponse([
            'data' => [
                'campaign_ulid' => $campaign->ulid,
                'campaign_name' => $campaign->name,
                'brand_name' => $brand->name,
                'agency_ulid' => $agency->ulid,
                'creator_ulid' => $creator->ulid,
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
