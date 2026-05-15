<?php

declare(strict_types=1);

use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorService;
use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'));
});

it('seeds an agency_user + agency + agency_admin membership in one call', function (): void {
    $response = $this->postJson('/api/v1/_test/agencies/setup', [
        'email' => 'agency-admin@example.com',
        'agency_name' => 'Catalyst E2E Agency',
    ]);

    $response->assertStatus(201);

    /** @var User $user */
    $user = User::query()->where('email', 'agency-admin@example.com')->firstOrFail();
    expect($user->type)->toBe(UserType::AgencyUser);
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull();
    expect($user->two_factor_secret)->toBeNull();

    /** @var Agency $agency */
    $agency = Agency::query()->where('name', 'Catalyst E2E Agency')->firstOrFail();

    /** @var AgencyMembership $membership */
    $membership = AgencyMembership::query()
        ->where('agency_id', $agency->id)
        ->where('user_id', $user->id)
        ->firstOrFail();
    expect($membership->role)->toBe(AgencyRole::AgencyAdmin);
    expect($membership->accepted_at)->not->toBeNull();

    expect($response->json('data.two_factor_secret'))->toBeNull();
});

it('seeds a confirmed 2FA secret when enroll_2fa=true', function (): void {
    $response = $this->postJson('/api/v1/_test/agencies/setup', [
        'email' => 'enrolled-admin@example.com',
        'enroll_2fa' => true,
    ]);

    $response->assertStatus(201);

    /** @var User $user */
    $user = User::query()->where('email', 'enrolled-admin@example.com')->firstOrFail();
    expect($user->two_factor_confirmed_at)->not->toBeNull();
    expect($user->two_factor_secret)->toBeString();
    expect($user->two_factor_recovery_codes)->toBeArray()->toHaveCount(8);
    expect($user->hasTwoFactorEnabled())->toBeTrue();

    /** @var string $secret */
    $secret = $response->json('data.two_factor_secret');
    expect($secret)->toBe($user->two_factor_secret);

    // Round-trip: the persisted secret must produce a code the production
    // service accepts. This is the same chain the bulk-invite spec drives
    // via `mintTotpCodeForEmail`.
    /** @var TwoFactorService $service */
    $service = app(TwoFactorService::class);
    $code = $service->currentCodeFor($secret);
    expect($service->verifyTotp($secret, $code))->toBeTrue();
});

it('defaults enroll_2fa to false when omitted', function (): void {
    $this->postJson('/api/v1/_test/agencies/setup', [
        'email' => 'no-mfa@example.com',
    ])->assertStatus(201);

    /** @var User $user */
    $user = User::query()->where('email', 'no-mfa@example.com')->firstOrFail();
    expect($user->hasTwoFactorEnabled())->toBeFalse();
});

it('returns 404 when the helper gate is closed (no token header)', function (): void {
    $this->withoutHeader(VerifyTestHelperToken::HEADER)
        ->postJson('/api/v1/_test/agencies/setup', [
            'email' => 'gated@example.com',
        ])
        ->assertStatus(404);
});
