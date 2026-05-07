<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Models\AdminProfile;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Models\User;
use Database\Seeders\Sprint1IdentitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('seeds the Catalyst pilot agency, an agency_admin, and a super_admin', function (): void {
    $this->seed(Sprint1IdentitySeeder::class);

    $catalyst = Agency::query()->where('slug', 'catalyst')->firstOrFail();
    expect($catalyst->name)->toBe('Catalyst')
        ->and($catalyst->country_code)->toBe('PT');

    $agencyAdmin = User::query()->where('email', 'admin@catalyst.local')->firstOrFail();
    expect($agencyAdmin->type->value)->toBe('agency_user')
        ->and($agencyAdmin->password)->toStartWith('$argon2id$')
        ->and(Hash::check('password-12chars', $agencyAdmin->password))->toBeTrue()
        ->and($agencyAdmin->mfa_required)->toBeFalse()
        ->and($agencyAdmin->email_verified_at)->not->toBeNull();

    $membership = AgencyMembership::query()
        ->where('agency_id', $catalyst->id)
        ->where('user_id', $agencyAdmin->id)
        ->firstOrFail();
    expect($membership->role)->toBe(AgencyRole::AgencyAdmin)
        ->and($membership->isAccepted())->toBeTrue();

    $superAdmin = User::query()->where('email', 'super@catalyst-engine.local')->firstOrFail();
    expect($superAdmin->type->value)->toBe('platform_admin')
        ->and($superAdmin->password)->toStartWith('$argon2id$')
        ->and($superAdmin->mfa_required)->toBeTrue();

    $profile = AdminProfile::query()->where('user_id', $superAdmin->id)->firstOrFail();
    expect($profile->admin_role)->toBe(AdminRole::SuperAdmin);
});

it('seeder is idempotent (safe to run twice)', function (): void {
    $this->seed(Sprint1IdentitySeeder::class);
    $this->seed(Sprint1IdentitySeeder::class);

    expect(Agency::query()->where('slug', 'catalyst')->count())->toBe(1)
        ->and(User::query()->where('email', 'admin@catalyst.local')->count())->toBe(1)
        ->and(User::query()->where('email', 'super@catalyst-engine.local')->count())->toBe(1)
        ->and(AdminProfile::query()->count())->toBe(1)
        ->and(AgencyMembership::query()->count())->toBe(1);
});

it('seeder refuses to run outside local/testing', function (): void {
    app()['env'] = 'production';

    expect(fn () => (new Sprint1IdentitySeeder)->run())
        ->toThrow(RuntimeException::class, 'only allowed in local and testing');
});
