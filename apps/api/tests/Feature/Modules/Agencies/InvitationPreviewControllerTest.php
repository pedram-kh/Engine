<?php

declare(strict_types=1);

use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyUserInvitation;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * `GET /api/v1/agencies/{agency}/invitations/preview` had NO dedicated
 * test file before AH-095 (the sibling
 * `tests/Feature/Modules/Creators/InvitationPreviewControllerTest.php`
 * covers the CREATOR magic-link preview endpoint — a different
 * controller, different module, same name by coincidence). This file
 * pins the pre-existing behaviour AND the two AH-095 additions
 * (`invited_email`, `account_exists`) together, so a future change to
 * either surface trips one suite.
 */
function provisionPreviewInvitation(array $overrides = []): array
{
    $agency = $overrides['agency'] ?? Agency::factory()->createOne();
    $token = Str::random(64);
    $inviter = User::factory()->agencyAdmin($agency)->createOne();

    $invitation = AgencyUserInvitation::factory()->create([
        'agency_id' => $agency->id,
        'email' => $overrides['email'] ?? 'previewee@example.com',
        'role' => $overrides['role'] ?? AgencyRole::AgencyManager,
        'token_hash' => hash('sha256', $token),
        'expires_at' => $overrides['expires_at'] ?? now()->addDays(7),
        'invited_by_user_id' => $inviter->id,
    ]);

    if (($overrides['accepted'] ?? false) === true) {
        $invitation->update(['accepted_at' => now(), 'accepted_by_user_id' => $inviter->id]);
    }

    return ['token' => $token, 'invitation' => $invitation, 'agency' => $agency];
}

it('returns agency_name, role, and expiry metadata for a valid pending token', function (): void {
    ['token' => $token, 'agency' => $agency] = provisionPreviewInvitation([
        'role' => AgencyRole::AgencyStaff,
    ]);

    $this->getJson("/api/v1/agencies/{$agency->ulid}/invitations/preview?token={$token}")
        ->assertOk()
        ->assertJsonPath('data.agency_name', $agency->name)
        ->assertJsonPath('data.role', AgencyRole::AgencyStaff->value)
        ->assertJsonPath('data.is_expired', false)
        ->assertJsonPath('data.is_accepted', false);
});

it('AH-095: reports account_exists = false and invited_email for a stranger invitee', function (): void {
    ['token' => $token, 'agency' => $agency] = provisionPreviewInvitation([
        'email' => 'brand-new@example.com',
    ]);

    $this->getJson("/api/v1/agencies/{$agency->ulid}/invitations/preview?token={$token}")
        ->assertOk()
        ->assertJsonPath('data.invited_email', 'brand-new@example.com')
        ->assertJsonPath('data.account_exists', false);
});

it('AH-095: reports account_exists = true when a users row already exists for the invited email', function (): void {
    ['token' => $token, 'agency' => $agency] = provisionPreviewInvitation([
        'email' => 'already-has-account@example.com',
    ]);
    User::factory()->createOne(['email' => 'already-has-account@example.com']);

    $this->getJson("/api/v1/agencies/{$agency->ulid}/invitations/preview?token={$token}")
        ->assertOk()
        ->assertJsonPath('data.invited_email', 'already-has-account@example.com')
        ->assertJsonPath('data.account_exists', true);
});

it('returns 422 when the token query parameter is missing', function (): void {
    $agency = Agency::factory()->createOne();

    $this->getJson("/api/v1/agencies/{$agency->ulid}/invitations/preview")
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'invitation.token_required');
});

it('returns 404 for an unknown token', function (): void {
    $agency = Agency::factory()->createOne();

    $this->getJson("/api/v1/agencies/{$agency->ulid}/invitations/preview?token=".str_repeat('z', 64))
        ->assertNotFound()
        ->assertJsonPath('errors.0.code', 'invitation.not_found');
});

it('returns 404 for a valid token scoped to a different agency (user-enumeration defence)', function (): void {
    ['token' => $token] = provisionPreviewInvitation();
    $otherAgency = Agency::factory()->createOne();

    $this->getJson("/api/v1/agencies/{$otherAgency->ulid}/invitations/preview?token={$token}")
        ->assertNotFound();
});

it('reports is_expired = true for an expired invitation, without erroring', function (): void {
    ['token' => $token, 'agency' => $agency] = provisionPreviewInvitation([
        'expires_at' => now()->subDay(),
    ]);

    $this->getJson("/api/v1/agencies/{$agency->ulid}/invitations/preview?token={$token}")
        ->assertOk()
        ->assertJsonPath('data.is_expired', true);
});

it('reports is_accepted = true for an already-accepted invitation', function (): void {
    ['token' => $token, 'agency' => $agency] = provisionPreviewInvitation(['accepted' => true]);

    $this->getJson("/api/v1/agencies/{$agency->ulid}/invitations/preview?token={$token}")
        ->assertOk()
        ->assertJsonPath('data.is_accepted', true);
});

it('never exposes the token hash', function (): void {
    ['token' => $token, 'agency' => $agency] = provisionPreviewInvitation();

    $response = $this->getJson("/api/v1/agencies/{$agency->ulid}/invitations/preview?token={$token}")
        ->assertOk()
        ->json();

    expect($response)->not->toHaveKey('data.token_hash');
});
