<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Models\AdminProfile;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/_test/users/admin
 *
 * Provisions a fresh platform_admin user for the admin SPA's
 * Playwright suite (chunk 7.6). The production sign-up endpoint
 * cannot create admin users (admin onboarding is out-of-band per
 * `docs/20-PHASE-1-SPEC.md` § 5 and the user_type validation in
 * `SignUpRequest` rejects `platform_admin`), and the admin SPA itself
 * therefore cannot drive its own E2E setup through the production
 * surface alone. Without a seeded admin row the chunk-7.6 sign-in
 * and mandatory-MFA enrollment specs have no subject — that's the
 * gap this controller closes.
 *
 * Group 3 honest deviation #D1 (structurally-correct minimal extension):
 * the chunk-7.6 kickoff specified "no new backend endpoints"; the
 * intent there was production surfaces. Test-helper endpoints exist
 * specifically to fill setup gaps the production API cannot serve,
 * gated by `TEST_HELPERS_TOKEN` + non-prod environment (see
 * `App\TestHelpers\TestHelpersServiceProvider`). This controller
 * follows that pattern verbatim — it has no production surface and
 * no production wiring.
 *
 * Request body:
 *   - `email`           — string, required, lower-cased + trimmed.
 *   - `password`        — string, required, ≥12 chars.
 *   - `name`            — string, optional, default 'Admin User'.
 *   - `enrolled`        — bool, optional, default false. When true,
 *                          the row is created with a known `two_factor_secret`
 *                          + `two_factor_confirmed_at = now()`, mirroring
 *                          `UserFactory::withTwoFactor()` — the Playwright
 *                          sign-in happy-path spec uses this branch.
 *   - `role`            — string, optional, default 'super_admin'.
 *                          Must be one of the `AdminRole` cases.
 *
 * Response (201):
 *   {
 *     "data": {
 *       "id": 123,
 *       "ulid": "01HQ...",
 *       "email": "...",
 *       "two_factor_secret": "JBSW..." | null
 *     }
 *   }
 *
 * Returns `two_factor_secret` ONLY when `enrolled=true`. The secret
 * is what the chunk-7.6 happy-path spec hands to `mintTotpFromSecret`
 * to derive the current 6-digit code on demand.
 *
 * Failure modes:
 *   - Missing/invalid fields → 422 (Laravel default).
 *   - Email already taken → 422 (`Rule::unique`).
 *   - The gate (token/env) is closed → 404 from `VerifyTestHelperToken`.
 */
final class CreateAdminUserController
{
    public function __invoke(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'string', 'email:rfc', 'max:254', Rule::unique('users', 'email')],
                'password' => ['required', 'string', 'min:12', 'max:512'],
                'name' => ['nullable', 'string', 'max:255'],
                'enrolled' => ['nullable', 'boolean'],
                'role' => ['nullable', 'string', Rule::in(array_map(
                    static fn (AdminRole $role): string => $role->value,
                    AdminRole::cases(),
                ))],
            ]);
        } catch (ValidationException $e) {
            return new JsonResponse([
                'error' => 'validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $email = strtolower(trim((string) $validated['email']));
        $password = (string) $validated['password'];
        $name = isset($validated['name']) && is_string($validated['name']) && $validated['name'] !== ''
            ? $validated['name']
            : 'Admin User';
        $enrolled = (bool) ($validated['enrolled'] ?? false);
        $role = AdminRole::from((string) ($validated['role'] ?? AdminRole::SuperAdmin->value));

        $attributes = [
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make($password),
            'type' => UserType::PlatformAdmin,
            'name' => $name,
            'preferred_language' => 'en',
            'preferred_currency' => 'EUR',
            'timezone' => 'UTC',
            'mfa_required' => true,
            'is_suspended' => false,
        ];

        $secret = null;

        if ($enrolled) {
            $secret = $twoFactor->generateSecret();
            $attributes['two_factor_secret'] = $secret;
            $attributes['two_factor_confirmed_at'] = now();
            // The recovery-codes column is `encrypted:array` and stores
            // bcrypt hashes (chunk 5 priority #3); we stamp a single
            // valid-shaped placeholder so the cast round-trips. The
            // E2E spec never exercises the recovery-code branch for
            // the pre-enrolled subject — the codes-flow spec lives in
            // the enrollment journey, which creates its own user with
            // enrolled=false and walks through /2fa/enable.
            $attributes['two_factor_recovery_codes'] = [
                '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ012345',
            ];
        }

        /** @var User $user */
        $user = User::query()->create($attributes);

        AdminProfile::query()->create([
            'user_id' => $user->id,
            'admin_role' => $role,
        ]);

        return new JsonResponse([
            'data' => [
                'id' => $user->id,
                'ulid' => $user->ulid,
                'email' => $user->email,
                'two_factor_secret' => $secret,
            ],
        ], 201);
    }
}
