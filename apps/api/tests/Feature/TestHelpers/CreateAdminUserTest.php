<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Models\AdminProfile;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorService;
use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'));
});

it('creates a platform_admin user with the matching admin profile', function (): void {
    $response = $this->postJson('/api/v1/_test/users/admin', [
        'email' => 'fresh@example.com',
        'password' => 'Pa$$w0rd-12+',
    ]);

    $response->assertStatus(201);

    /** @var User $user */
    $user = User::query()->where('email', 'fresh@example.com')->firstOrFail();
    expect($user->type)->toBe(UserType::PlatformAdmin);
    expect($user->mfa_required)->toBeTrue();
    expect($user->email_verified_at)->not->toBeNull();
    expect(Hash::check('Pa$$w0rd-12+', $user->password))->toBeTrue();

    /** @var AdminProfile $profile */
    $profile = AdminProfile::query()->where('user_id', $user->id)->firstOrFail();
    expect($profile->admin_role)->toBe(AdminRole::SuperAdmin);

    expect($response->json('data.two_factor_secret'))->toBeNull();
    expect($response->json('data.email'))->toBe('fresh@example.com');
});

it('lower-cases + trims the email before storing', function (): void {
    $this->postJson('/api/v1/_test/users/admin', [
        'email' => '  MiXeD@Example.com  ',
        'password' => 'Pa$$w0rd-12+',
    ])->assertStatus(201);

    expect(User::query()->where('email', 'mixed@example.com')->exists())->toBeTrue();
});

it('defaults the name to "Admin User" when none is supplied', function (): void {
    $this->postJson('/api/v1/_test/users/admin', [
        'email' => 'noname@example.com',
        'password' => 'Pa$$w0rd-12+',
    ])->assertStatus(201);

    /** @var User $user */
    $user = User::query()->where('email', 'noname@example.com')->firstOrFail();
    expect($user->name)->toBe('Admin User');
});

it('honors a custom name when supplied', function (): void {
    $this->postJson('/api/v1/_test/users/admin', [
        'email' => 'named@example.com',
        'password' => 'Pa$$w0rd-12+',
        'name' => 'Jane Q. Admin',
    ])->assertStatus(201);

    /** @var User $user */
    $user = User::query()->where('email', 'named@example.com')->firstOrFail();
    expect($user->name)->toBe('Jane Q. Admin');
});

it('returns a usable two_factor_secret when enrolled=true', function (): void {
    $response = $this->postJson('/api/v1/_test/users/admin', [
        'email' => 'pre-enrolled@example.com',
        'password' => 'Pa$$w0rd-12+',
        'enrolled' => true,
    ])->assertStatus(201);

    /** @var User $user */
    $user = User::query()->where('email', 'pre-enrolled@example.com')->firstOrFail();
    expect($user->two_factor_secret)->toBeString();
    expect($user->two_factor_confirmed_at)->not->toBeNull();

    /** @var string $secret */
    $secret = $response->json('data.two_factor_secret');
    expect($secret)->toBe($user->two_factor_secret);

    /** @var TwoFactorService $twoFactor */
    $twoFactor = app(TwoFactorService::class);
    $code = $twoFactor->currentCodeFor($secret);
    expect($twoFactor->verifyTotp($secret, $code))->toBeTrue();
});

it('honors a custom admin role', function (): void {
    $this->postJson('/api/v1/_test/users/admin', [
        'email' => 'support@example.com',
        'password' => 'Pa$$w0rd-12+',
        'role' => AdminRole::Support->value,
    ])->assertStatus(201);

    /** @var User $user */
    $user = User::query()->where('email', 'support@example.com')->firstOrFail();
    /** @var AdminProfile $profile */
    $profile = AdminProfile::query()->where('user_id', $user->id)->firstOrFail();
    expect($profile->admin_role)->toBe(AdminRole::Support);
});

it('returns 422 when email is missing', function (): void {
    $this->postJson('/api/v1/_test/users/admin', [
        'password' => 'Pa$$w0rd-12+',
    ])->assertStatus(422);
});

it('returns 422 when password is too short', function (): void {
    $this->postJson('/api/v1/_test/users/admin', [
        'email' => 'short-pw@example.com',
        'password' => 'short',
    ])->assertStatus(422);
});

it('returns 422 when email is already taken', function (): void {
    User::factory()->createOne(['email' => 'duplicate@example.com']);
    $this->postJson('/api/v1/_test/users/admin', [
        'email' => 'duplicate@example.com',
        'password' => 'Pa$$w0rd-12+',
    ])->assertStatus(422);
});

it('returns 422 when role is not a valid AdminRole', function (): void {
    $this->postJson('/api/v1/_test/users/admin', [
        'email' => 'bad-role@example.com',
        'password' => 'Pa$$w0rd-12+',
        'role' => 'wizard',
    ])->assertStatus(422);
});

it('returns 404 when the helper gate is closed (no token header)', function (): void {
    $this->withoutHeader(VerifyTestHelperToken::HEADER)
        ->postJson('/api/v1/_test/users/admin', [
            'email' => 'gated@example.com',
            'password' => 'Pa$$w0rd-12+',
        ])
        ->assertStatus(404);
});
