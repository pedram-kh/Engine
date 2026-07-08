<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Models\AdminProfile;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('creates a platform super admin with MFA required and a verified email', function (): void {
    $this->artisan('admin:create', [
        'email' => 'ops@example.com',
        '--first-name' => 'Ada',
        '--last-name' => 'Ops',
    ])
        ->expectsOutputToContain('Platform admin created: ops@example.com (super_admin)')
        ->expectsOutputToContain('One-time password')
        ->assertSuccessful();

    $user = User::query()->where('email', 'ops@example.com')->firstOrFail();

    expect($user->type)->toBe(UserType::PlatformAdmin)
        ->and($user->name)->toBe('Ada')
        ->and($user->last_name)->toBe('Ops')
        ->and($user->mfa_required)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();

    $profile = AdminProfile::query()->where('user_id', $user->id)->firstOrFail();
    expect($profile->admin_role)->toBe(AdminRole::SuperAdmin);
});

it('creates an agency admin attached to the given agency slug', function (): void {
    $agency = Agency::factory()->createOne(['slug' => 'catalyst']);

    $this->artisan('admin:create', [
        'email' => 'jordan@example.com',
        '--first-name' => 'Jordan',
        '--last-name' => 'Lee',
        '--agency' => 'catalyst',
    ])
        ->expectsOutputToContain('Agency member created: jordan@example.com (agency_admin @ catalyst)')
        ->assertSuccessful();

    $user = User::query()->where('email', 'jordan@example.com')->firstOrFail();

    expect($user->type)->toBe(UserType::AgencyUser)
        ->and($user->email_verified_at)->not->toBeNull();

    $membership = AgencyMembership::query()
        ->where('agency_id', $agency->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($membership->role)->toBe(AgencyRole::AgencyAdmin)
        ->and($membership->accepted_at)->not->toBeNull();
});

it('accepts an explicit agency role', function (): void {
    Agency::factory()->createOne(['slug' => 'catalyst']);

    $this->artisan('admin:create', [
        'email' => 'staff@example.com',
        '--first-name' => 'Sam',
        '--last-name' => 'Staff',
        '--agency' => 'catalyst',
        '--role' => 'agency_manager',
    ])->assertSuccessful();

    $user = User::query()->where('email', 'staff@example.com')->firstOrFail();
    $membership = AgencyMembership::query()->where('user_id', $user->id)->firstOrFail();

    expect($membership->role)->toBe(AgencyRole::AgencyManager);
});

it('refuses to touch an existing email (never updates or escalates)', function (): void {
    User::factory()->createOne(['email' => 'taken@example.com']);
    $before = User::query()->count();

    $this->artisan('admin:create', [
        'email' => 'taken@example.com',
        '--first-name' => 'Dup',
        '--last-name' => 'User',
    ])
        ->expectsOutputToContain('already exists')
        ->assertFailed();

    expect(User::query()->count())->toBe($before);
});

it('rejects an invalid email address', function (): void {
    $this->artisan('admin:create', [
        'email' => 'not-an-email',
        '--first-name' => 'Bad',
        '--last-name' => 'Email',
    ])->assertFailed();

    expect(User::query()->where('name', 'Bad')->exists())->toBeFalse();
});

it('rejects an unknown agency slug', function (): void {
    $this->artisan('admin:create', [
        'email' => 'nobody@example.com',
        '--first-name' => 'No',
        '--last-name' => 'Agency',
        '--agency' => 'does-not-exist',
    ])
        ->expectsOutputToContain('No agency found with slug')
        ->assertFailed();

    expect(User::query()->where('email', 'nobody@example.com')->exists())->toBeFalse();
});

it('rejects an invalid platform role', function (): void {
    $this->artisan('admin:create', [
        'email' => 'ops2@example.com',
        '--first-name' => 'Ada',
        '--last-name' => 'Ops',
        '--role' => 'not-a-role',
    ])->assertFailed();

    expect(User::query()->where('email', 'ops2@example.com')->exists())->toBeFalse();
});

it('stores a hashed password, not the plaintext', function (): void {
    $this->artisan('admin:create', [
        'email' => 'hash@example.com',
        '--first-name' => 'Hash',
        '--last-name' => 'Check',
    ])->assertSuccessful();

    $user = User::query()->where('email', 'hash@example.com')->firstOrFail();

    // Argon2id hashes start with $argon2id$ (the app's configured hasher).
    expect($user->password)->toStartWith('$argon2id$');
});
