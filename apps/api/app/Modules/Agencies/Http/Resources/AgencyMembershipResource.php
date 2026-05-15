<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Resources;

use App\Modules\Agencies\Models\AgencyMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of an AgencyMembership row for the paginated
 * members listing surface (Sprint 3 Chunk 4 sub-step 3).
 *
 *   GET /api/v1/agencies/{agency}/members
 *
 * `last_active_at` is sourced from the related User's `last_login_at`
 * (Sprint 1 column). When the user has never logged in (e.g., the
 * invitee accepted but hasn't returned), it's null.
 *
 * The membership ID surfaced in `id` is the pivot row's ULID-equivalent
 * — we use the related User's ULID as the canonical identifier because
 * AgencyMembership rows don't have their own ULID (they're pivot rows).
 * This matches Sprint 2's bootstrap-time membership shape exposed via
 * `useAgencyStore.memberships`.
 *
 * @mixin AgencyMembership
 */
final class AgencyMembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $membership = $this->resource;
        assert($membership instanceof AgencyMembership);

        $user = $membership->user;

        return [
            'id' => $user?->ulid ?? '',
            'type' => 'agency_memberships',
            'attributes' => [
                'user_id' => $user?->ulid ?? '',
                'name' => $user?->name ?? '',
                'email' => $user?->email ?? '',
                'role' => $membership->role->value,
                'status' => $membership->isAccepted() ? 'active' : 'pending',
                'created_at' => $membership->created_at->toIso8601String(),
                'last_active_at' => $user?->last_login_at?->toIso8601String(),
            ],
        ];
    }
}
