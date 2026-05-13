<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Enums\RelationshipStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/creators/invitations/preview?token={token}
 *
 * Returns the agency context for an invitation token. Unauthenticated
 * endpoint — the magic-link landing page calls it to render
 * "$agency has invited you. Sign up below." UI.
 *
 * Pushback (per kickoff Refinements §2): the response shape is
 * intentionally narrowed to {agency_name, is_expired, is_accepted} ONLY
 * — invited_email is NEVER exposed. Standing standard #42 (no
 * enumerable identifiers on unauthenticated surfaces) applied: an
 * attacker holding a guessed-but-valid token must NOT learn the
 * invitee's email from this surface. The accept endpoint matches the
 * email at submit time instead, returning invitation.email_mismatch
 * if the typed email doesn't match the bound User.
 *
 * Generic 404 on token-not-found ensures the existence of an invitation
 * is itself non-discoverable.
 */
final class InvitationPreviewController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:128'],
        ]);

        $tokenHash = hash('sha256', (string) $request->string('token'));

        $relation = AgencyCreatorRelation::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('invitation_token_hash', $tokenHash)
            ->first();

        if ($relation === null) {
            return ErrorResponse::single(
                $request,
                404,
                'invitation.not_found',
                'Invitation not found.',
            );
        }

        $agency = Agency::query()->find($relation->agency_id);
        if ($agency === null) {
            return ErrorResponse::single(
                $request,
                404,
                'invitation.not_found',
                'Invitation not found.',
            );
        }

        return response()->json([
            'data' => [
                'agency_name' => $agency->name,
                'is_expired' => $relation->isInvitationExpired(),
                'is_accepted' => $relation->relationship_status === RelationshipStatus::Roster,
            ],
        ]);
    }
}
