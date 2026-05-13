<?php

declare(strict_types=1);

use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyUserInvitation;
use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use App\TestHelpers\TestHelpersServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'));
});

// ---------------------------------------------------------------------------
// Happy path
// ---------------------------------------------------------------------------

it('creates a pending invitation and returns the unhashed token', function (): void {
    $agency = Agency::factory()->createOne();

    $response = $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/invitations", [
        'email' => 'playwright@example.com',
        'role' => AgencyRole::AgencyManager->value,
    ]);

    $response->assertStatus(201);

    $token = $response->json('data.token');
    expect($token)->toBeString()->toHaveLength(64);

    // Confirm the invitation row was created with the correct hash.
    $this->assertDatabaseHas('agency_user_invitations', [
        'agency_id' => $agency->id,
        'email' => 'playwright@example.com',
        'role' => AgencyRole::AgencyManager->value,
        'token_hash' => hash('sha256', $token),
        'accepted_at' => null,
    ]);
});

it('lower-cases the email', function (): void {
    $agency = Agency::factory()->createOne();

    $response = $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/invitations", [
        'email' => 'UPPER@Example.COM',
        'role' => AgencyRole::AgencyStaff->value,
    ]);

    $response->assertStatus(201);
    expect($response->json('data.email'))->toBe('upper@example.com');
});

it('respects custom expires_in_days', function (): void {
    $agency = Agency::factory()->createOne();

    $response = $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/invitations", [
        'email' => 'custom-expiry@example.com',
        'role' => AgencyRole::AgencyStaff->value,
        'expires_in_days' => 3,
    ]);

    $response->assertStatus(201);

    $invitation = AgencyUserInvitation::query()
        ->where('email', 'custom-expiry@example.com')
        ->first();

    expect($invitation?->expires_at->diffInDays(now()))->toBeLessThanOrEqual(3);
});

it('response includes the ULID, email, role, token and expires_at', function (): void {
    $agency = Agency::factory()->createOne();

    $response = $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/invitations", [
        'email' => 'shape@example.com',
        'role' => AgencyRole::AgencyAdmin->value,
    ]);

    $response->assertStatus(201);

    expect($response->json('data'))->toHaveKeys(['ulid', 'email', 'role', 'token', 'expires_at']);
    // token_hash must NOT appear in the response
    expect($response->json('data'))->not->toHaveKey('token_hash');
});

// ---------------------------------------------------------------------------
// Validation failures
// ---------------------------------------------------------------------------

it('returns 422 for missing email', function (): void {
    $agency = Agency::factory()->createOne();

    $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/invitations", [
        'role' => AgencyRole::AgencyStaff->value,
    ])->assertStatus(422);
});

it('returns 422 for invalid role', function (): void {
    $agency = Agency::factory()->createOne();

    $this->postJson("/api/v1/_test/agencies/{$agency->ulid}/invitations", [
        'email' => 'test@example.com',
        'role' => 'bad_role',
    ])->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Gate-closed (token missing / invalid env)
// ---------------------------------------------------------------------------

it('returns 404 bare when the test-helper token header is absent', function (): void {
    // Override the header set in beforeEach.
    $agency = Agency::factory()->createOne();

    $this->withHeaders(['X-Test-Helper-Token' => ''])->postJson(
        "/api/v1/_test/agencies/{$agency->ulid}/invitations",
        ['email' => 'gate@example.com', 'role' => AgencyRole::AgencyStaff->value],
    )->assertNotFound();
});

it('gate is open in the testing environment with a non-empty token', function (): void {
    expect(TestHelpersServiceProvider::gateOpen())->toBeTrue();
});
