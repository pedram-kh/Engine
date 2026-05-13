<?php

declare(strict_types=1);

use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyUserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// GET /api/v1/agencies/{agency}/invitations/preview?token=<unhashed>
//
// Unauthenticated endpoint. The token query param carries the unhashed token;
// the controller hashes it before lookup (sha256). No auth required.
// ---------------------------------------------------------------------------

it('returns 422 when token query param is missing', function (): void {
    $agency = Agency::factory()->createOne();

    $this->getJson("/api/v1/agencies/{$agency->ulid}/invitations/preview")
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'invitation.token_required');
});

it('returns 404 for an unknown token (user-enumeration defence)', function (): void {
    $agency = Agency::factory()->createOne();

    $this->getJson("/api/v1/agencies/{$agency->ulid}/invitations/preview?token=".Str::random(64))
        ->assertNotFound()
        ->assertJsonPath('errors.0.code', 'invitation.not_found');
});

it('returns 404 when token belongs to a different agency (user-enumeration defence)', function (): void {
    $agencyA = Agency::factory()->createOne();
    $agencyB = Agency::factory()->createOne();

    $token = Str::random(64);
    AgencyUserInvitation::factory()->create([
        'agency_id' => $agencyA->id,
        'token_hash' => hash('sha256', $token),
        'email' => 'user@example.com',
        'role' => AgencyRole::AgencyManager,
        'expires_at' => now()->addDays(7),
    ]);

    // Querying agencyB with agencyA's token → 404
    $this->getJson("/api/v1/agencies/{$agencyB->ulid}/invitations/preview?token={$token}")
        ->assertNotFound()
        ->assertJsonPath('errors.0.code', 'invitation.not_found');
});

it('returns 200 with preview data for a valid pending invitation', function (): void {
    $agency = Agency::factory()->createOne(['name' => 'Acme Corp']);

    $token = Str::random(64);
    AgencyUserInvitation::factory()->create([
        'agency_id' => $agency->id,
        'token_hash' => hash('sha256', $token),
        'email' => 'manager@example.com',
        'role' => AgencyRole::AgencyManager,
        'expires_at' => now()->addDays(7),
    ]);

    $this->getJson("/api/v1/agencies/{$agency->ulid}/invitations/preview?token={$token}")
        ->assertOk()
        ->assertJsonPath('data.agency_name', 'Acme Corp')
        ->assertJsonPath('data.role', AgencyRole::AgencyManager->value)
        ->assertJsonPath('data.is_expired', false)
        ->assertJsonPath('data.is_accepted', false);
});

it('returns is_expired: true for an expired invitation', function (): void {
    $agency = Agency::factory()->createOne(['name' => 'Expired Agency']);

    $token = Str::random(64);
    AgencyUserInvitation::factory()->create([
        'agency_id' => $agency->id,
        'token_hash' => hash('sha256', $token),
        'email' => 'late@example.com',
        'role' => AgencyRole::AgencyStaff,
        'expires_at' => now()->subDay(), // already expired
    ]);

    $this->getJson("/api/v1/agencies/{$agency->ulid}/invitations/preview?token={$token}")
        ->assertOk()
        ->assertJsonPath('data.is_expired', true)
        ->assertJsonPath('data.is_accepted', false)
        ->assertJsonPath('data.agency_name', 'Expired Agency')
        ->assertJsonPath('data.role', AgencyRole::AgencyStaff->value);
});

it('returns is_accepted: true for an already-accepted invitation', function (): void {
    $agency = Agency::factory()->createOne(['name' => 'Done Agency']);

    $token = Str::random(64);
    AgencyUserInvitation::factory()->create([
        'agency_id' => $agency->id,
        'token_hash' => hash('sha256', $token),
        'email' => 'done@example.com',
        'role' => AgencyRole::AgencyAdmin,
        'expires_at' => now()->addDays(7),
        'accepted_at' => now()->subHour(),
    ]);

    $this->getJson("/api/v1/agencies/{$agency->ulid}/invitations/preview?token={$token}")
        ->assertOk()
        ->assertJsonPath('data.is_accepted', true)
        ->assertJsonPath('data.is_expired', false)
        ->assertJsonPath('data.agency_name', 'Done Agency');
});

it('is accessible without authentication', function (): void {
    $agency = Agency::factory()->createOne();
    $token = Str::random(64);

    // Unauthenticated request — should return 404 (not 401) because the
    // endpoint has no auth middleware. Unknown token → 404.
    $this->getJson("/api/v1/agencies/{$agency->ulid}/invitations/preview?token={$token}")
        ->assertNotFound();
});
