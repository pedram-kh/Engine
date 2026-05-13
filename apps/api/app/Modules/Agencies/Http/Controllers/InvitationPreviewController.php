<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Controllers;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyUserInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/agencies/{agency}/invitations/preview?token=<unhashed>
 *
 * Unauthenticated endpoint. Returns enough invitation metadata for the
 * SPA's accept-invitation page to render a meaningful "you are being
 * invited to join X as Y" prompt BEFORE the user signs in.
 *
 * Security design:
 *   - The unhashed token is the secret; hashing before lookup means the
 *     database never contains plaintext tokens.
 *   - Wrong agency + valid-other-agency token → 404 (user-enumeration
 *     defence: never confirm whether a ULID is a real agency).
 *   - Token not found → 404 (same defence).
 *   - Expired token → 200 with is_expired: true (the token IS correct;
 *     returning metadata is less sensitive than confirming existence via
 *     the 410 the accept endpoint uses).
 *   - Already-accepted → 200 with is_accepted: true.
 *   - agency_name and role are included on all 200 responses so the SPA
 *     can render the expired/accepted states with full context.
 *
 * No auth required — consuming users may not have an account yet.
 */
final class InvitationPreviewController
{
    public function __invoke(Request $request, Agency $agency): JsonResponse
    {
        $rawToken = $request->query('token', '');

        if (! is_string($rawToken) || $rawToken === '') {
            return response()->json([
                'errors' => [[
                    'code' => 'invitation.token_required',
                    'title' => 'Token required.',
                    'detail' => 'The token query parameter is required.',
                ]],
            ], 422);
        }

        $tokenHash = hash('sha256', $rawToken);

        $invitation = AgencyUserInvitation::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('agency_id', $agency->id)
            ->where('token_hash', $tokenHash)
            ->first();

        if ($invitation === null) {
            return response()->json([
                'errors' => [[
                    'code' => 'invitation.not_found',
                    'title' => 'Not found.',
                    'detail' => 'The invitation could not be found.',
                ]],
            ], 404);
        }

        return response()->json([
            'data' => [
                'agency_name' => $agency->name,
                'role' => $invitation->role->value,
                'is_expired' => $invitation->isExpired(),
                'is_accepted' => $invitation->isAccepted(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
        ]);
    }
}
