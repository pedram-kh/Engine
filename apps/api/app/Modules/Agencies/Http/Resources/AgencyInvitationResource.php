<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Resources;

use App\Modules\Agencies\Models\AgencyUserInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of an AgencyUserInvitation.
 *
 * token_hash is NEVER exposed. Only metadata is returned.
 *
 * @mixin AgencyUserInvitation
 */
final class AgencyInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invitation = $this->resource;
        assert($invitation instanceof AgencyUserInvitation);

        return [
            'id' => $invitation->ulid,
            'type' => 'agency_invitations',
            'attributes' => [
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'accepted_at' => $invitation->accepted_at?->toIso8601String(),
                'is_pending' => $invitation->isPending(),
                'is_expired' => $invitation->isExpired(),
                'created_at' => $invitation->created_at->toIso8601String(),
            ],
            'relationships' => [
                'agency' => [
                    'data' => [
                        'id' => $invitation->agency->ulid,
                        'type' => 'agencies',
                    ],
                ],
            ],
        ];
    }
}
