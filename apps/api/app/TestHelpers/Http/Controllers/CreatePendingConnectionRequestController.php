<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/_test/creators/pending-connection-request
 *
 * One-shot E2E subject provisioning for the Sprint 6.6c creator-inbox
 * Playwright round-trip. Given the signed-in creator's email, it:
 *
 *   1. APPROVES the creator (application_status → approved). The requests
 *      inbox renders ONLY in the dashboard's approved branch, and no
 *      production path approves a self-signed-up creator (admin-only), so the
 *      E2E spec has no way to reach the approved surface without this flip.
 *   2. Creates an agency.
 *   3. Creates a `pending_request` agency_creator_relation between the agency
 *      and the creator (via the factory state — stamps invitation_sent_at +
 *      the invite attribution the send-request write sets).
 *
 * No production endpoint can provision this in one call (the agency-side send
 * is admin/manager-gated on a SEPARATE agency the spec doesn't control); hence
 * this test-helper. Follows the CreateAgencyWithAdminController / CreateRoster-
 * CreatorsController pattern verbatim: gated by VerifyTestHelperToken (404 when
 * closed), validates inputs (422 on failure), no production wiring.
 *
 * Request body:
 *   - `email`        — string, required. The signed-in creator's account email.
 *   - `agency_name`  — string|null, optional. Defaults to a unique fake company
 *                      (the human label the inbox row binds + the accept toast
 *                      names).
 *
 * Response (201):
 *   { "data": { "relation_ulid", "agency_ulid", "agency_name", "creator_ulid" } }
 */
final class CreatePendingConnectionRequestController
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'string', 'email:rfc', 'max:254'],
                'agency_name' => ['nullable', 'string', 'max:255'],
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

        // Approve the creator so the dashboard's approved branch (and its inbox
        // fetch) is reachable end-to-end.
        $creator->application_status = ApplicationStatus::Approved;
        if ($creator->approved_at === null) {
            $creator->approved_at = now();
        }
        $creator->save();

        $agencyName = isset($validated['agency_name']) && is_string($validated['agency_name'])
            ? $validated['agency_name']
            : fake()->unique()->company();

        /** @var Agency $agency */
        $agency = AgencyFactory::new()->create(['name' => $agencyName]);

        /** @var AgencyCreatorRelation $relation */
        $relation = AgencyCreatorRelation::factory()->pendingRequest()->create([
            'agency_id' => $agency->id,
            'creator_id' => $creator->id,
        ]);

        return new JsonResponse([
            'data' => [
                'relation_ulid' => $relation->ulid,
                'agency_ulid' => $agency->ulid,
                'agency_name' => $agency->name,
                'creator_ulid' => $creator->ulid,
            ],
        ], 201);
    }
}
