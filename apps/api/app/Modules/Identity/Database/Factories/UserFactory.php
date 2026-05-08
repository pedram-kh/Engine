<?php

declare(strict_types=1);

namespace App\Modules\Identity\Database\Factories;

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Models\AdminProfile;
use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Database\Factories\AgencyMembershipFactory;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Identity\Enums\ThemePreference;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Cached hash so factory generation in big test suites doesn't pay
     * the Argon2id cost for every row. The default factory password is
     * 'password-12chars'.
     */
    protected static ?string $cachedPassword = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$cachedPassword ??= Hash::make('password-12chars'),
            'type' => UserType::Creator,
            'name' => fake()->name(),
            'preferred_language' => 'en',
            'preferred_currency' => 'EUR',
            'timezone' => 'UTC',
            'theme_preference' => ThemePreference::System,
            'mfa_required' => false,
            'is_suspended' => false,
        ];
    }

    /**
     * Email-unverified state. Used by chunk 4's signup tests.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Suspended-account state. Used by chunk 3's lockout-escalation tests.
     */
    public function suspended(string $reason = 'Suspended by test'): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_suspended' => true,
            'suspended_reason' => $reason,
            'suspended_at' => now(),
        ]);
    }

    /**
     * 2FA-confirmed state. Note: the two_factor_recovery_codes column
     * is cast to `encrypted:array` and stores bcrypt hashes (chunk 5
     * priority #3); the placeholder values below are valid bcrypt-shaped
     * strings so the cast round-trips cleanly. Tests that need to log
     * in with a recovery code must hash a known plaintext via
     * TwoFactorService::hashRecoveryCode() and stamp the array
     * themselves rather than relying on this state.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', // RFC 6238 example secret
            'two_factor_recovery_codes' => array_fill(
                0,
                10,
                '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ012345',
            ),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Creator user (default — explicit override for readability).
     */
    public function creator(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => UserType::Creator,
        ]);
    }

    /**
     * Agency-staff user. Optionally attached to an agency with a role —
     * defaults to AgencyAdmin in the given agency when one is supplied,
     * or creates a fresh agency.
     *
     * Usage:
     *   User::factory()->agencyAdmin($agency)->create();
     *   User::factory()->agencyManager($agency)->create();
     *   User::factory()->agencyStaff($agency)->create();
     */
    public function agencyAdmin(?Agency $agency = null): static
    {
        return $this->agencyMember($agency, AgencyRole::AgencyAdmin);
    }

    public function agencyManager(?Agency $agency = null): static
    {
        return $this->agencyMember($agency, AgencyRole::AgencyManager);
    }

    public function agencyStaff(?Agency $agency = null): static
    {
        return $this->agencyMember($agency, AgencyRole::AgencyStaff);
    }

    /**
     * Platform admin (Catalyst Engine ops staff). MFA mandatory by spec.
     * Creates the satellite admin_profiles row in the same call.
     */
    public function platformAdmin(AdminRole $role = AdminRole::SuperAdmin): static
    {
        return $this
            ->state(fn (array $attributes): array => [
                'type' => UserType::PlatformAdmin,
                'mfa_required' => true,
            ])
            ->afterCreating(function (User $user) use ($role): void {
                AdminProfile::factory()->state([
                    'user_id' => $user->id,
                    'admin_role' => $role,
                ])->create();
            });
    }

    private function agencyMember(?Agency $agency, AgencyRole $role): static
    {
        return $this
            ->state(fn (array $attributes): array => [
                'type' => UserType::AgencyUser,
            ])
            ->afterCreating(function (User $user) use ($agency, $role): void {
                $agency ??= AgencyFactory::new()->createOne();

                AgencyMembershipFactory::new()->state([
                    'agency_id' => $agency->id,
                    'user_id' => $user->id,
                    'role' => $role,
                    'accepted_at' => now(),
                ])->create();
            });
    }
}
