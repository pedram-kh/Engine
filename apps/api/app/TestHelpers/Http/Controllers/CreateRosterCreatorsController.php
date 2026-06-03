<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/_test/agencies/{agency}/roster-creators
 *
 * Seeds one or more creators + accepted agency_creator_relations on the given
 * agency so the Sprint 6 Chunk 1 Playwright roster spec can render a REAL
 * roster table and exercise name/bio search + the disabled filter affordances
 * against actual rows. No production endpoint can provision an agency roster
 * with known display_name/bio in a single call; hence this test-helper.
 *
 * Follows the CreateAgencyWithAdminController / CreateAgencyInvitationController
 * pattern verbatim: gated by VerifyTestHelperToken (404 when closed), validates
 * all inputs (422 on failure), no production wiring.
 *
 * Request body:
 *   - `creators`                         — array, required, 1..50 rows.
 *   - `creators.*.display_name`          — string, required.
 *   - `creators.*.bio`                   — string|null, optional.
 *   - `creators.*.country_code`          — string|null, optional (2 chars).
 *   - `creators.*.primary_language`      — string|null, optional (2 chars).
 *   - `creators.*.relationship_status`   — string|null, optional; one of
 *                                          RelationshipStatus (default roster).
 *
 * Response (201):
 *   { "data": { "agency_ulid": "...", "relations": [ { "relation_ulid", "creator_ulid", "display_name" } ] } }
 */
final class CreateRosterCreatorsController
{
    public function __invoke(Request $request, Agency $agency): JsonResponse
    {
        try {
            $validated = $request->validate([
                'creators' => ['required', 'array', 'min:1', 'max:50'],
                'creators.*.display_name' => ['required', 'string', 'max:160'],
                'creators.*.bio' => ['nullable', 'string', 'max:5000'],
                'creators.*.country_code' => ['nullable', 'string', 'size:2'],
                'creators.*.primary_language' => ['nullable', 'string', 'size:2'],
                'creators.*.relationship_status' => ['nullable', 'string', Rule::in(array_map(
                    static fn (RelationshipStatus $status): string => $status->value,
                    RelationshipStatus::cases(),
                ))],
            ]);
        } catch (ValidationException $e) {
            return new JsonResponse([
                'error' => 'validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        /** @var list<array<string, mixed>> $creatorsInput */
        $creatorsInput = $validated['creators'];

        $relations = [];
        foreach ($creatorsInput as $input) {
            $creator = Creator::factory()->create([
                'display_name' => $input['display_name'],
                'bio' => $input['bio'] ?? null,
                'country_code' => $input['country_code'] ?? 'US',
                'primary_language' => $input['primary_language'] ?? 'en',
                'application_status' => ApplicationStatus::Approved,
            ]);

            $status = isset($input['relationship_status']) && is_string($input['relationship_status'])
                ? RelationshipStatus::from($input['relationship_status'])
                : RelationshipStatus::Roster;

            /** @var AgencyCreatorRelation $relation */
            $relation = AgencyCreatorRelation::factory()->create([
                'agency_id' => $agency->id,
                'creator_id' => $creator->id,
                'relationship_status' => $status,
                'is_blacklisted' => false,
            ]);

            $relations[] = [
                'relation_ulid' => $relation->ulid,
                'creator_ulid' => $creator->ulid,
                'display_name' => $creator->display_name,
            ];
        }

        return new JsonResponse([
            'data' => [
                'agency_ulid' => $agency->ulid,
                'relations' => $relations,
            ],
        ], 201);
    }
}
