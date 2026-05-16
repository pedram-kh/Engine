<?php

declare(strict_types=1);

use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Mail\InviteAgencyUserMail;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyUserInvitation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Create invitation
// ---------------------------------------------------------------------------

it('agency_admin can create an invitation', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson("/api/v1/agencies/{$agency->ulid}/invitations", [
            'email' => 'new@example.com',
            'role' => AgencyRole::AgencyManager->value,
        ])
        ->assertCreated()
        ->assertJsonPath('data.attributes.email', 'new@example.com')
        ->assertJsonPath('data.attributes.role', AgencyRole::AgencyManager->value);

    $this->assertDatabaseHas('agency_user_invitations', [
        'agency_id' => $agency->id,
        'email' => 'new@example.com',
        'role' => AgencyRole::AgencyManager->value,
    ]);

    Mail::assertQueued(InviteAgencyUserMail::class, fn ($mail) => $mail->hasTo('new@example.com'));
});

it('agency_manager cannot create an invitation', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $manager = User::factory()->agencyManager($agency)->createOne();

    $this->actingAs($manager)
        ->postJson("/api/v1/agencies/{$agency->ulid}/invitations", [
            'email' => 'invited@example.com',
            'role' => AgencyRole::AgencyStaff->value,
        ])
        ->assertForbidden();

    Mail::assertNothingQueued();
});

it('agency_staff cannot create an invitation', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();

    $this->actingAs($staff)
        ->postJson("/api/v1/agencies/{$agency->ulid}/invitations", [
            'email' => 'invited@example.com',
            'role' => AgencyRole::AgencyStaff->value,
        ])
        ->assertForbidden();
});

it('invitation creation emits invitation.created audit log', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson("/api/v1/agencies/{$agency->ulid}/invitations", [
            'email' => 'audit@example.com',
            'role' => AgencyRole::AgencyStaff->value,
        ])
        ->assertCreated();

    $this->assertDatabaseHas('audit_logs', [
        'action' => AuditAction::InvitationCreated->value,
    ]);
});

it('invitation creation validates role field', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson("/api/v1/agencies/{$agency->ulid}/invitations", [
            'email' => 'test@example.com',
            'role' => 'invalid_role',
        ])
        ->assertEnvelopeValidationErrors(['role']);
});

it('invitation creation succeeds for existing-user email (user-enumeration defence)', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $existingUser = User::factory()->createOne(['email' => 'existing@example.com']);

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/agencies/{$agency->ulid}/invitations", [
            'email' => 'existing@example.com',
            'role' => AgencyRole::AgencyStaff->value,
        ])
        ->assertCreated();

    // Response shape is identical whether the email is known or not.
    expect($response->json('data.attributes.email'))->toBe('existing@example.com');

    Mail::assertQueued(InviteAgencyUserMail::class);
});

it('invitation response does not expose the token hash', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/agencies/{$agency->ulid}/invitations", [
            'email' => 'safe@example.com',
            'role' => AgencyRole::AgencyStaff->value,
        ])
        ->assertCreated()
        ->json();

    expect($response)->not->toHaveKey('data.attributes.token_hash');
});

it('cross-tenant invitation creation returns 404', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $otherAgency = Agency::factory()->createOne();

    $this->actingAs($admin)
        ->postJson("/api/v1/agencies/{$otherAgency->ulid}/invitations", [
            'email' => 'cross@example.com',
            'role' => AgencyRole::AgencyStaff->value,
        ])
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// Accept invitation
// ---------------------------------------------------------------------------

it('an authenticated user can accept a valid invitation', function (): void {
    $agency = Agency::factory()->createOne();
    $token = Str::random(64);
    $inviter = User::factory()->agencyAdmin($agency)->createOne();
    $invitation = AgencyUserInvitation::factory()->create([
        'agency_id' => $agency->id,
        'email' => 'invitee@example.com',
        'role' => AgencyRole::AgencyManager,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
        'invited_by_user_id' => $inviter->id,
    ]);

    $acceptor = User::factory()->createOne(['email' => 'invitee@example.com']);

    $this->actingAs($acceptor)
        ->postJson("/api/v1/agencies/{$agency->ulid}/invitations/accept", [
            'token' => $token,
        ])
        ->assertOk()
        ->assertJsonPath('data.attributes.accepted_at', fn ($v) => $v !== null);

    // Agency membership row created.
    $this->assertDatabaseHas('agency_users', [
        'agency_id' => $agency->id,
        'user_id' => $acceptor->id,
        'role' => AgencyRole::AgencyManager->value,
    ]);

    // Invitation stamped.
    $this->assertDatabaseHas('agency_user_invitations', [
        'id' => $invitation->id,
        'accepted_by_user_id' => $acceptor->id,
    ]);
});

it('accept emits invitation.accepted audit log', function (): void {
    $agency = Agency::factory()->createOne();
    $token = Str::random(64);
    $inviter = User::factory()->agencyAdmin($agency)->createOne();
    AgencyUserInvitation::factory()->create([
        'agency_id' => $agency->id,
        'email' => 'audit-accept@example.com',
        'role' => AgencyRole::AgencyStaff,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
        'invited_by_user_id' => $inviter->id,
    ]);
    $acceptor = User::factory()->createOne(['email' => 'audit-accept@example.com']);

    $this->actingAs($acceptor)
        ->postJson("/api/v1/agencies/{$agency->ulid}/invitations/accept", ['token' => $token])
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::InvitationAccepted->value]);
});

it('returns 404 for an invalid token', function (): void {
    $agency = Agency::factory()->createOne();
    $user = User::factory()->createOne();

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/invitations/accept", [
            'token' => str_repeat('x', 64),
        ])
        ->assertNotFound();
});

it('returns 410 for an expired token and emits expired-on-attempt audit log', function (): void {
    $agency = Agency::factory()->createOne();
    $token = Str::random(64);
    $inviter = User::factory()->agencyAdmin($agency)->createOne();
    AgencyUserInvitation::factory()->expired()->create([
        'agency_id' => $agency->id,
        'email' => 'expired@example.com',
        'role' => AgencyRole::AgencyStaff,
        'token_hash' => hash('sha256', $token),
        'invited_by_user_id' => $inviter->id,
    ]);
    $user = User::factory()->createOne(['email' => 'expired@example.com']);

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/invitations/accept", [
            'token' => $token,
        ])
        ->assertStatus(410);

    $this->assertDatabaseHas('audit_logs', [
        'action' => AuditAction::InvitationExpiredOnAttempt->value,
    ]);
});

it('returns 409 for an already-accepted token (Q1: single-use-with-retry)', function (): void {
    $agency = Agency::factory()->createOne();
    $token = Str::random(64);
    $inviter = User::factory()->agencyAdmin($agency)->createOne();
    AgencyUserInvitation::factory()->accepted()->create([
        'agency_id' => $agency->id,
        'email' => 'already@example.com',
        'role' => AgencyRole::AgencyStaff,
        'token_hash' => hash('sha256', $token),
        'invited_by_user_id' => $inviter->id,
        'expires_at' => now()->addDays(7),
    ]);
    $user = User::factory()->createOne(['email' => 'already@example.com']);

    $this->actingAs($user)
        ->postJson("/api/v1/agencies/{$agency->ulid}/invitations/accept", [
            'token' => $token,
        ])
        ->assertConflict();
});

it('returns 403 when authenticated user email does not match the invitation email', function (): void {
    $agency = Agency::factory()->createOne();
    $token = Str::random(64);
    $inviter = User::factory()->agencyAdmin($agency)->createOne();
    AgencyUserInvitation::factory()->create([
        'agency_id' => $agency->id,
        'email' => 'correct@example.com',
        'role' => AgencyRole::AgencyStaff,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
        'invited_by_user_id' => $inviter->id,
    ]);
    $wrongUser = User::factory()->createOne(['email' => 'wrong@example.com']);

    $this->actingAs($wrongUser)
        ->postJson("/api/v1/agencies/{$agency->ulid}/invitations/accept", [
            'token' => $token,
        ])
        ->assertForbidden();
});

it('returns 409 when user is already a member of the agency', function (): void {
    $agency = Agency::factory()->createOne();
    $token = Str::random(64);
    $inviter = User::factory()->agencyAdmin($agency)->createOne();
    $existingMember = User::factory()->agencyStaff($agency)->createOne();

    AgencyUserInvitation::factory()->create([
        'agency_id' => $agency->id,
        'email' => $existingMember->email,
        'role' => AgencyRole::AgencyStaff,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
        'invited_by_user_id' => $inviter->id,
    ]);

    $this->actingAs($existingMember)
        ->postJson("/api/v1/agencies/{$agency->ulid}/invitations/accept", [
            'token' => $token,
        ])
        ->assertConflict();
});

it('retry before acceptance succeeds (Q1: single-use-with-retry)', function (): void {
    $agency = Agency::factory()->createOne();
    $token = Str::random(64);
    $inviter = User::factory()->agencyAdmin($agency)->createOne();
    AgencyUserInvitation::factory()->create([
        'agency_id' => $agency->id,
        'email' => 'retry@example.com',
        'role' => AgencyRole::AgencyManager,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
        'invited_by_user_id' => $inviter->id,
    ]);
    $acceptor = User::factory()->createOne(['email' => 'retry@example.com']);

    // First attempt — succeeds.
    $this->actingAs($acceptor)
        ->postJson("/api/v1/agencies/{$agency->ulid}/invitations/accept", ['token' => $token])
        ->assertOk();

    // Second attempt — returns 409 because accepted_at is now set.
    $this->actingAs($acceptor)
        ->postJson("/api/v1/agencies/{$agency->ulid}/invitations/accept", ['token' => $token])
        ->assertConflict();
});

it('unauthenticated accept returns 401', function (): void {
    $agency = Agency::factory()->createOne();

    $this->postJson("/api/v1/agencies/{$agency->ulid}/invitations/accept", [
        'token' => str_repeat('a', 64),
    ])
        ->assertUnauthorized();
});
