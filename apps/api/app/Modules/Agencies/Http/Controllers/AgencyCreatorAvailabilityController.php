<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Controllers;

use App\Modules\Agencies\Http\Resources\AgencyAvailabilityResource;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\Availability\AvailabilityExpansionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/agencies/{agency}/creators/{creator}/availability —
 * the agency-side read-view of a roster creator's availability
 * (Sprint 5 Chunk A, D-a6).
 *
 * Built standalone now (plan-pause B6 resolution): Sprint 6's per-creator
 * detail page will CONSUME this endpoint rather than the endpoint waiting on
 * the page. The data is the point — an agency matching to a campaign needs
 * to see when a creator is blocked.
 *
 * Tenancy / scope (plan-pause Q2 = mirror the Chunk-5 roster scope exactly):
 *   - Route sits under the `auth:web → tenancy.agency → tenancy` stack, so a
 *     non-member gets the 404 invisibility response before this runs, and the
 *     `{agency}` segment is the active tenant.
 *   - The creator must be in THIS agency's roster — enforced by an explicit
 *     "an AgencyCreatorRelation exists between this agency and this creator"
 *     check, across ALL relationship statuses (roster/prospect/external),
 *     identical to what the roster list already shows. An agency cannot read
 *     availability for a creator it has no relation with (404). Break-revert:
 *     dropping the relation check leaks a non-roster creator's availability.
 *
 * Reads the SAME {@see AvailabilityExpansionService} output as
 * conflict-detection (D-a4) and renders it through
 * {@see AgencyAvailabilityResource}, which OMITS `reason` (creator-only, B4).
 */
final class AgencyCreatorAvailabilityController
{
    /** Default look-ahead window when no `from`/`to` is supplied. */
    private const int DEFAULT_WINDOW_DAYS = 90;

    /** Hard ceiling on the requested window span (bounds expansion). */
    private const int MAX_WINDOW_DAYS = 366;

    public function __construct(
        private readonly AvailabilityExpansionService $expansion,
    ) {}

    public function show(Request $request, Agency $agency, Creator $creator): JsonResponse
    {
        Gate::authorize('viewAny', AgencyCreatorRelation::class);

        $this->requireRosterRelation($agency, $creator);

        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from'])
            ? CarbonImmutable::parse((string) $validated['from'])
            : CarbonImmutable::now()->startOfDay();

        $to = isset($validated['to'])
            ? CarbonImmutable::parse((string) $validated['to'])
            : $from->addDays(self::DEFAULT_WINDOW_DAYS);

        $maxTo = $from->addDays(self::MAX_WINDOW_DAYS);
        if ($to->greaterThan($maxTo)) {
            $to = $maxTo;
        }

        $occurrences = $this->expansion->expand($creator, $from, $to);

        return AgencyAvailabilityResource::collection($occurrences)
            ->additional([
                'meta' => [
                    'window' => [
                        'from' => $from->toIso8601String(),
                        'to' => $to->toIso8601String(),
                    ],
                ],
            ])
            ->response();
    }

    /**
     * 404 unless the creator is in this agency's roster (any relationship
     * status). The belt-and-suspenders explicit agency_id filter sits on top
     * of the BelongsToAgency global scope, mirroring the roster controller.
     */
    private function requireRosterRelation(Agency $agency, Creator $creator): void
    {
        $hasRelation = AgencyCreatorRelation::query()
            ->where('agency_id', $agency->id)
            ->where('creator_id', $creator->id)
            ->exists();

        if (! $hasRelation) {
            abort(404);
        }
    }
}
