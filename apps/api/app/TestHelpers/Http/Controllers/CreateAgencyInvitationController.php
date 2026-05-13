<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyUserInvitation;
use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Identity\Enums\UserType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/_test/agencies/{agency}/invitations
 *
 * Seeds a pending invitation with a *known* token for Chunk 2's
 * Playwright accept-invitation E2E spec. Without this helper, the spec
 * would have to intercept an outbound email to extract the magic-link
 * token — which is fragile in CI and impossible without a mail-capture
 * service. This endpoint short-circuits that by creating the invitation
 * row with a caller-supplied (or randomly generated) token and returning
 * the unhashed token in the response.
 *
 * Follows the CreateAdminUserController (chunk 7.6) pattern verbatim:
 *   - Gated by VerifyTestHelperToken middleware (env + token check).
 *   - Returns bare 404 when the gate is closed (runtime protection).
 *   - Validates all inputs; returns 422 on failure.
 *   - Minimal production surface — no production wiring.
 *
 * Request body:
 *   - `email`      — string, required. The invited email address.
 *   - `role`       — string, required. One of `AgencyRole` values.
 *   - `invited_by` — int|null, optional. ID of the inviting user.
 *                    If omitted, a new agency_admin user is created.
 *   - `expires_in_days` — int, optional, default 7.
 *
 * Response (201):
 *   {
 *     "data": {
 *       "ulid": "...",
 *       "email": "...",
 *       "role": "...",
 *       "token": "<unhashed>",   ← the only place this is returned
 *       "expires_at": "..."
 *     }
 *   }
 *
 * Failure modes:
 *   - Missing/invalid fields → 422.
 *   - Gate closed → 404 (from VerifyTestHelperToken middleware).
 */
final class CreateAgencyInvitationController
{
    public function __invoke(Request $request, Agency $agency): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'string', 'email:rfc', 'max:254'],
                'role' => ['required', 'string', Rule::in(array_map(
                    static fn (AgencyRole $role): string => $role->value,
                    AgencyRole::cases(),
                ))],
                'invited_by' => ['nullable', 'integer', 'exists:users,id'],
                'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            ]);
        } catch (ValidationException $e) {
            return new JsonResponse([
                'error' => 'validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        // Resolve or create the inviting user.
        $invitedById = isset($validated['invited_by']) && is_int($validated['invited_by'])
            ? $validated['invited_by']
            : UserFactory::new()
                ->state(['type' => UserType::AgencyUser])
                ->createOne()
                ->id;

        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);
        $expiresInDays = isset($validated['expires_in_days']) && is_int($validated['expires_in_days'])
            ? $validated['expires_in_days']
            : 7;

        /** @var AgencyUserInvitation $invitation */
        $invitation = AgencyUserInvitation::withoutGlobalScopes()->create([
            'agency_id' => $agency->id,
            'email' => strtolower(trim((string) $validated['email'])),
            'role' => AgencyRole::from((string) $validated['role']),
            'token_hash' => $tokenHash,
            'expires_at' => now()->addDays($expiresInDays),
            'invited_by_user_id' => $invitedById,
        ]);

        return new JsonResponse([
            'data' => [
                'ulid' => $invitation->ulid,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'token' => $token,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                // Included so the E2E spec can construct the SPA accept URL:
                // /accept-invitation?token={token}&agency={agency_ulid}
                'agency_ulid' => $agency->ulid,
            ],
        ], 201);
    }
}
