<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/_test/agencies/setup
 *
 * One-shot E2E subject provisioning for Sprint 2 Chunk 2's Playwright specs.
 * Creates:
 *   1. An `agency_user`-typed user with a verified email and known password.
 *   2. An agency.
 *   3. An accepted `agency_admin` membership linking the two.
 *
 * Returns all identifiers needed to sign in + drive the brand / invitation
 * happy-path specs. No production endpoints can provision this in one call;
 * hence this test-helper.
 *
 * Follows CreateAdminUserController (chunk 7.6) pattern:
 *   - Gated by VerifyTestHelperToken middleware.
 *   - Returns bare 404 when gate is closed (runtime protection).
 *   - Validates all inputs; returns 422 on failure.
 *
 * Request body (all optional — defaults generate stable unique values):
 *   - `email`       — string, optional. Defaults to a unique fake email.
 *   - `password`    — string, optional. Defaults to 'Password1!'.
 *   - `name`        — string, optional. Defaults to 'Test Admin'.
 *   - `agency_name` — string, optional. Defaults to a unique fake company.
 *
 * Response (201):
 *   {
 *     "data": {
 *       "email": "admin@example.com",
 *       "password": "Password1!",
 *       "user_ulid": "...",
 *       "agency_ulid": "...",
 *       "agency_name": "..."
 *     }
 *   }
 */
final class CreateAgencyWithAdminController
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => ['nullable', 'string', 'email:rfc', 'max:254'],
                'password' => ['nullable', 'string', 'min:8', 'max:128'],
                'name' => ['nullable', 'string', 'max:255'],
                'agency_name' => ['nullable', 'string', 'max:255'],
            ]);
        } catch (ValidationException $e) {
            return new JsonResponse([
                'error' => 'validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $email = isset($validated['email']) && is_string($validated['email'])
            ? $validated['email']
            : fake()->unique()->safeEmail();

        $password = isset($validated['password']) && is_string($validated['password'])
            ? $validated['password']
            : 'Password1!';

        $name = isset($validated['name']) && is_string($validated['name'])
            ? $validated['name']
            : 'Test Admin';

        $agencyName = isset($validated['agency_name']) && is_string($validated['agency_name'])
            ? $validated['agency_name']
            : fake()->unique()->company();

        // Create an agency_user-typed, email-verified user with a known password.
        // Use User::query()->create() directly (mirrors CreateAdminUserController)
        // rather than UserFactory so we control the password without triggering
        // the model's `hashed` cast on a pre-hashed value (RuntimeException in CI).
        /** @var User $user */
        $user = User::query()->create([
            'type' => UserType::AgencyUser,
            'email' => $email,
            'name' => $name,
            'email_verified_at' => now(),
            'password' => Hash::make($password),
            'preferred_language' => 'en',
            'preferred_currency' => 'EUR',
            'timezone' => 'UTC',
            'mfa_required' => false,
            'is_suspended' => false,
        ]);

        // Create the agency.
        /** @var Agency $agency */
        $agency = AgencyFactory::new()->create(['name' => $agencyName]);

        // Create an accepted agency_admin membership.
        AgencyMembership::query()->create([
            'agency_id' => $agency->id,
            'user_id' => $user->id,
            'role' => AgencyRole::AgencyAdmin,
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);

        return new JsonResponse([
            'data' => [
                'email' => $email,
                'password' => $password,
                'user_ulid' => $user->ulid,
                'agency_ulid' => $agency->ulid,
                'agency_name' => $agencyName,
            ],
        ], 201);
    }
}
