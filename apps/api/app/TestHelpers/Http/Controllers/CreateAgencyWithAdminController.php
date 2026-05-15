<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorService;
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
 *   - `email`          — string, optional. Defaults to a unique fake email.
 *   - `password`       — string, optional. Defaults to 'Password1!'.
 *   - `name`           — string, optional. Defaults to 'Test Admin'.
 *   - `agency_name`    — string, optional. Defaults to a unique fake company.
 *   - `enroll_2fa`     — bool, optional. When `true`, seed the user with a
 *                        confirmed 2FA secret + recovery codes so the SPA
 *                        `requireMfaEnrolled` router guard treats the user
 *                        as enrolled out-of-the-box. Sprint 3 Chunk 4 added
 *                        this flag to unblock the bulk-invite critical-path
 *                        E2E spec, whose route chain is
 *                        `requireAuth → requireMfaEnrolled → requireAgencyAdmin`.
 *                        Without it, the spec would have to drive the full
 *                        enrollment flow inline (~12 SPA navigations) just
 *                        to reach the page under test. The returned
 *                        `two_factor_secret` lets the spec mint a fresh
 *                        TOTP code via `mintTotpCodeForEmail`, which only
 *                        works once `users.two_factor_secret` is persisted
 *                        — exactly the state this branch leaves the user in.
 *
 * Response (201):
 *   {
 *     "data": {
 *       "email": "admin@example.com",
 *       "password": "Password1!",
 *       "user_ulid": "...",
 *       "agency_ulid": "...",
 *       "agency_name": "...",
 *       "two_factor_secret": "..." | null
 *     }
 *   }
 */
final class CreateAgencyWithAdminController
{
    public function __invoke(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => ['nullable', 'string', 'email:rfc', 'max:254'],
                'password' => ['nullable', 'string', 'min:8', 'max:128'],
                'name' => ['nullable', 'string', 'max:255'],
                'agency_name' => ['nullable', 'string', 'max:255'],
                'enroll_2fa' => ['nullable', 'boolean'],
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

        $enroll2fa = (bool) ($validated['enroll_2fa'] ?? false);

        $attributes = [
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
        ];

        // Optional 2FA enrollment: pre-seed the model with a confirmed TOTP
        // secret + recovery codes so the SPA's `requireMfaEnrolled` guard
        // treats the user as enrolled on first sign-in. The secret is a
        // 32-char base32 string in the shape `TwoFactorService` would
        // generate; we hand it back in the response so the spec can mint a
        // valid 6-digit code via `_test/totp`. Recovery codes are seeded
        // with placeholder values so the column is non-null and the
        // controller schema invariant (`two_factor_recovery_codes NOT NULL
        // when two_factor_confirmed_at NOT NULL`) holds.
        $secret = null;
        if ($enroll2fa) {
            // Use the production TwoFactorService to mint the secret so the
            // shape is bit-identical to a real enrollment (Google2FA's
            // generateSecretKey of length SECRET_LENGTH). `mintTotpCodeForEmail`
            // reads the persisted column and re-derives the current code via
            // the same service, so the shape parity matters.
            $secret = $twoFactor->generateSecret();
            $attributes['two_factor_secret'] = $secret;
            $attributes['two_factor_recovery_codes'] = $this->generateRecoveryCodes();
            $attributes['two_factor_confirmed_at'] = now();
        }

        /** @var User $user */
        $user = User::query()->create($attributes);

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
                'two_factor_secret' => $secret,
            ],
        ], 201);
    }

    /**
     * Generate 8 single-use recovery codes in the shape
     * `RecoveryCodeService::generate()` returns. The values are never
     * consumed by the bulk-invite spec — the spec mints a TOTP code, not
     * a recovery code — but the column is encrypted:array on the model
     * and we want it non-null when `two_factor_confirmed_at` is also set
     * (matches the production invariant the model assumes).
     *
     * @return array<int, string>
     */
    private function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = bin2hex(random_bytes(5));
        }

        return $codes;
    }
}
