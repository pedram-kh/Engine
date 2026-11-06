<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Agencies\Support\AgencyCreatorRelationGuard;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Features\MessagesV2InboxEnabled;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * AH-119 A3 — reassign the member who owns a creator relationship.
 *
 * `PATCH agencies/{agency}/creators/{creator}/owner {owner_user_id}`: admin and manager only (Q6),
 * the new owner an ACTIVE member of this agency (422 `owner.not_a_member`), the relation in this
 * agency (404), idempotent when unchanged (200, no audit row), behind `messages_v2_inbox_enabled`
 * (404 while off). The owner gates only the inbox "Mine" filter: no routing and no notification.
 */
final class AgencyCreatorOwnerController
{
    public function update(Request $request, Agency $agency, Creator $creator): JsonResponse
    {
        if (! Feature::active(MessagesV2InboxEnabled::NAME)) {
            return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
        }

        $relation = AgencyCreatorRelationGuard::requireExisting($agency, $creator);

        Gate::authorize('reassignOwner', $relation);

        $validated = $request->validate(['owner_user_id' => ['required', 'integer']]);
        $newOwnerId = (int) $validated['owner_user_id'];

        $isActiveMember = AgencyMembership::query()
            ->where('agency_id', $agency->id)
            ->where('user_id', $newOwnerId)
            ->whereNotNull('accepted_at')
            ->whereNull('deleted_at')
            ->exists();

        if (! $isActiveMember) {
            return ErrorResponse::single(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'owner.not_a_member',
                'The new owner must be an active member of this agency.',
            );
        }

        /** @var User $actor */
        $actor = $request->user();
        $previous = $relation->effectiveOwnerId();

        if ($previous !== $newOwnerId) {
            DB::transaction(function () use ($relation, $newOwnerId, $previous, $actor, $agency): void {
                $relation->forceFill(['owner_user_id' => $newOwnerId])->save();

                Audit::log(
                    action: AuditAction::AgencyCreatorRelationOwnerChanged,
                    actor: $actor,
                    subject: $relation,
                    metadata: ['from_user' => $previous, 'to_user' => $newOwnerId],
                    agencyId: $agency->id,
                );
            });
        }

        return response()->json([
            'data' => ['id' => $creator->ulid, 'type' => 'relation_owner', 'attributes' => [
                'owner' => $this->owner($agency, $relation),
            ]],
        ]);
    }

    /** @return array{id: int, name: string}|null */
    private function owner(Agency $agency, AgencyCreatorRelation $relation): ?array
    {
        $ownerId = $relation->effectiveOwnerId();
        $user = $ownerId === null ? null : User::query()->find($ownerId);

        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }
}
