<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Models\AdminProfile;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Enums\ThemePreference;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Production-safe admin bootstrap (the procedure the Sprint1IdentitySeeder
 * docblock defers to — that seeder is guarded to local/testing precisely so
 * known-credential admins can never be seeded into production).
 *
 * Two modes, switched by `--agency`:
 *
 *   - PLATFORM admin (default):
 *       php artisan admin:create ops@example.com --first-name=Ada --last-name=Ops
 *     Creates a `platform_admin` user + AdminProfile (default role
 *     super_admin — Phase 1 only uses that case) with `mfa_required=true`,
 *     so the admin SPA forces TOTP enrollment at first sign-in.
 *
 *   - AGENCY admin/member:
 *       php artisan admin:create jordan@example.com --agency=catalyst
 *     Creates an `agency_user` + an accepted AgencyMembership on the given
 *     agency slug (default role agency_admin).
 *
 * A cryptographically random one-time password is generated and printed
 * ONCE to the console — it is never stored in the repo or logs. The operator
 * hands it to the person out-of-band; they should change it after first
 * sign-in. Email is stamped verified (the operator vouches for the address;
 * there is no verification-mail path for ops-created accounts).
 *
 * Deliberately NOT idempotent: an existing email aborts rather than
 * updates, so the command can never silently rotate a live user's
 * password or escalate an existing account's role.
 */
final class CreateAdminUser extends Command
{
    protected $signature = 'admin:create
        {email : Email address of the new admin}
        {--first-name= : First (given) name}
        {--last-name= : Surname}
        {--agency= : Agency slug — when set, creates an agency member instead of a platform admin}
        {--role= : platform: super_admin|support|finance|security (default super_admin); agency: agency_admin|agency_manager|agency_staff (default agency_admin)}
        {--language=en : Preferred UI language}
        {--timezone=UTC : IANA timezone}';

    protected $description = 'Create a platform admin (default) or an agency admin/member with a one-time generated password.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error(sprintf('"%s" is not a valid email address.', $email));

            return self::FAILURE;
        }

        if (User::query()->withTrashed()->where('email', $email)->exists()) {
            $this->error(sprintf(
                'A user with email %s already exists (possibly soft-deleted). '.
                'This command never updates existing accounts.',
                $email,
            ));

            return self::FAILURE;
        }

        $firstName = trim((string) ($this->option('first-name') ?? ''));
        if ($firstName === '') {
            $firstName = trim((string) $this->ask('First name'));
        }

        $lastName = trim((string) ($this->option('last-name') ?? ''));
        if ($lastName === '') {
            $lastName = trim((string) $this->ask('Last name'));
        }

        if ($firstName === '' || $lastName === '') {
            $this->error('First name and last name are required.');

            return self::FAILURE;
        }

        $agencySlug = trim((string) ($this->option('agency') ?? ''));

        return $agencySlug === ''
            ? $this->createPlatformAdmin($email, $firstName, $lastName)
            : $this->createAgencyMember($email, $firstName, $lastName, $agencySlug);
    }

    private function createPlatformAdmin(string $email, string $firstName, string $lastName): int
    {
        $role = AdminRole::tryFrom((string) ($this->option('role') ?? AdminRole::SuperAdmin->value));
        if ($role === null) {
            $this->error(sprintf(
                'Invalid platform role. Valid roles: %s',
                implode(', ', array_column(AdminRole::cases(), 'value')),
            ));

            return self::FAILURE;
        }

        $password = Str::password(24);

        DB::transaction(function () use ($email, $firstName, $lastName, $role, $password): void {
            $user = User::query()->create([
                'email' => $email,
                'name' => $firstName,
                'last_name' => $lastName,
                'type' => UserType::PlatformAdmin,
                'password' => $password,
                'email_verified_at' => now(),
                'preferred_language' => (string) $this->option('language'),
                'preferred_currency' => (string) config('app.default_currency', 'EUR'),
                'timezone' => (string) $this->option('timezone'),
                'theme_preference' => ThemePreference::System,
                // Admin SPA access is MFA-gated: first sign-in forces TOTP
                // enrollment (EnsureMfaForAdmins + requireMfaEnrolled).
                'mfa_required' => true,
                'is_suspended' => false,
            ]);

            AdminProfile::query()->create([
                'user_id' => $user->id,
                'admin_role' => $role,
            ]);
        });

        $this->info(sprintf('Platform admin created: %s (%s)', $email, $role->value));
        $this->printOneTimePassword($password);

        return self::SUCCESS;
    }

    private function createAgencyMember(string $email, string $firstName, string $lastName, string $agencySlug): int
    {
        $agency = Agency::query()->where('slug', $agencySlug)->first();
        if ($agency === null) {
            $this->error(sprintf('No agency found with slug "%s".', $agencySlug));

            return self::FAILURE;
        }

        $role = AgencyRole::tryFrom((string) ($this->option('role') ?? AgencyRole::AgencyAdmin->value));
        if ($role === null) {
            $this->error(sprintf(
                'Invalid agency role. Valid roles: %s',
                implode(', ', array_column(AgencyRole::cases(), 'value')),
            ));

            return self::FAILURE;
        }

        $password = Str::password(24);

        DB::transaction(function () use ($email, $firstName, $lastName, $agency, $role, $password): void {
            $user = User::query()->create([
                'email' => $email,
                'name' => $firstName,
                'last_name' => $lastName,
                'type' => UserType::AgencyUser,
                'password' => $password,
                'email_verified_at' => now(),
                'preferred_language' => (string) $this->option('language'),
                'preferred_currency' => (string) config('app.default_currency', 'EUR'),
                'timezone' => (string) $this->option('timezone'),
                'theme_preference' => ThemePreference::System,
                // Agency admins hit requireMfaEnrolled on admin-gated pages;
                // no blanket flag needed (mirrors the invite flow).
                'mfa_required' => false,
                'is_suspended' => false,
            ]);

            AgencyMembership::query()->create([
                'agency_id' => $agency->id,
                'user_id' => $user->id,
                'role' => $role,
                'accepted_at' => now(),
            ]);
        });

        $this->info(sprintf('Agency member created: %s (%s @ %s)', $email, $role->value, $agency->slug));
        $this->printOneTimePassword($password);

        return self::SUCCESS;
    }

    private function printOneTimePassword(string $password): void
    {
        $this->newLine();
        $this->warn('One-time password (shown ONCE, not stored anywhere else):');
        $this->line('  '.$password);
        $this->newLine();
        $this->line('Hand it to the person out-of-band and have them change it after first sign-in.');
    }
}
