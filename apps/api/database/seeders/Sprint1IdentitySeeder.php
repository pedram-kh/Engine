<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Models\AdminProfile;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Seeds the Phase 1 identity baseline:
 *
 *   - The Catalyst pilot agency (docs/20-PHASE-1-SPEC.md §1).
 *   - A second agency (Northwind) so cross-tenant isolation can be
 *     exercised in local dev (agency A vs agency B — e.g. the Sprint 7
 *     blacklist privacy invariant: A's blacklist is invisible to B).
 *   - One agency_admin user attached to EACH agency.
 *   - One platform super_admin user.
 *
 * GUARDED to local + testing only. Refuses to run in any other
 * environment so a stray `php artisan db:seed` in production cannot
 * create a known-credential admin account. Production admin users
 * are minted by the bootstrap process described in
 * docs/SPRINT-0-MANUAL-STEPS.md.
 */
final class Sprint1IdentitySeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'Sprint1IdentitySeeder is only allowed in local and testing environments. '.
                'See docs/SPRINT-0-MANUAL-STEPS.md for production admin bootstrap.',
            );
        }

        $this->seedAgencyWithAdmin(
            slug: 'catalyst',
            name: 'Catalyst',
            countryCode: 'PT',
            timezone: 'Europe/Lisbon',
            adminEmail: 'admin@catalyst.local',
            adminName: 'Catalyst Admin',
        );

        // Second agency for cross-tenant testing (a distinct tenant from
        // Catalyst — share a creator between the two to exercise the
        // per-agency blacklist / discovery isolation invariants).
        $this->seedAgencyWithAdmin(
            slug: 'northwind',
            name: 'Northwind',
            countryCode: 'GB',
            timezone: 'Europe/London',
            adminEmail: 'admin@northwind.local',
            adminName: 'Northwind Admin',
        );

        $superAdmin = User::query()->updateOrCreate(
            ['email' => 'super@catalyst-engine.local'],
            [
                'name' => 'Engine C Super Admin',
                'type' => UserType::PlatformAdmin,
                'password' => Hash::make('password-12chars'),
                'email_verified_at' => now(),
                'preferred_language' => 'en',
                'preferred_currency' => 'EUR',
                'timezone' => 'UTC',
                'mfa_required' => true,
            ],
        );

        AdminProfile::query()->updateOrCreate(
            ['user_id' => $superAdmin->id],
            ['admin_role' => AdminRole::SuperAdmin],
        );
    }

    /**
     * Idempotently seed an agency + an agency_admin member attached to it.
     */
    private function seedAgencyWithAdmin(
        string $slug,
        string $name,
        string $countryCode,
        string $timezone,
        string $adminEmail,
        string $adminName,
    ): void {
        $agency = Agency::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'country_code' => $countryCode,
                'default_currency' => 'EUR',
                'default_language' => 'en',
                'subscription_tier' => 'pilot',
                'subscription_status' => 'active',
                'settings' => [
                    'blacklist_notification_default' => false,
                    'escrow_funding_moment' => 'on_contract_sign',
                ],
                'is_active' => true,
            ],
        );

        $admin = User::query()->updateOrCreate(
            ['email' => $adminEmail],
            [
                'name' => $adminName,
                'type' => UserType::AgencyUser,
                'password' => Hash::make('password-12chars'),
                'email_verified_at' => now(),
                'preferred_language' => 'en',
                'preferred_currency' => 'EUR',
                'timezone' => $timezone,
                'mfa_required' => false,
            ],
        );

        AgencyMembership::query()->updateOrCreate(
            [
                'agency_id' => $agency->id,
                'user_id' => $admin->id,
            ],
            [
                'role' => AgencyRole::AgencyAdmin,
                'accepted_at' => now(),
            ],
        );
    }
}
