<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Identity\Contracts\PwnedPasswordsClientContract;
use App\Modules\Identity\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * Sprint 3 Chunk 4 sub-step 4 — magic-link invitation sign-up path.
 *
 * The bulk-invite (Chunk 1) pre-creates a User + Creator + Relation in
 * `prospect` status with a random unusable password. The invitee
 * receives the magic-link email and lands on /auth/accept-invite. After
 * the 5-state preview UI confirms the invitation is valid + pending,
 * the user clicks "Continue to create your account" and lands on
 * /sign-up?token=<token>. The sign-up POST carries `invitation_token`
 * and is handled by SignUpService::acceptInvitationOnSignUp().
 *
 * This file pins the four failure modes (not_found, expired,
 * already_accepted, email_mismatch) and the happy-path side effects
 * (User row updated, Relation flipped to roster, audit row emitted).
 */
beforeEach(function (): void {
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());

    app()->bind(PwnedPasswordsClientContract::class, fn () => new class implements PwnedPasswordsClientContract
    {
        public function breachCount(string $plaintextPassword): int
        {
            return 0;
        }
    });

    Mail::fake();
});

/**
 * @return array{token: string, relation: AgencyCreatorRelation, user: User, agency: Agency}
 */
function provisionInvitation(array $overrides = []): array
{
    $agency = Agency::factory()->createOne();
    $invitee = User::factory()->createOne([
        'email' => $overrides['email'] ?? 'invitee@example.com',
        'name' => 'Invitee Placeholder',
        'email_verified_at' => null,
    ]);
    $creator = CreatorFactory::new()->createOne([
        'user_id' => $invitee->id,
    ]);
    $token = bin2hex(random_bytes(16));
    $hash = hash('sha256', $token);

    $relation = AgencyCreatorRelation::create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => $overrides['status'] ?? RelationshipStatus::Prospect->value,
        'invitation_token_hash' => $hash,
        'invitation_expires_at' => $overrides['expires_at'] ?? now()->addDays(7),
        'invitation_sent_at' => now(),
        'invited_by_user_id' => User::factory()->createOne()->id,
    ]);

    return ['token' => $token, 'relation' => $relation, 'user' => $invitee, 'agency' => $agency];
}

const STRONG_PASSWORD = 'My-strong-passphrase-9876';

// ---------------------------------------------------------------------------
// Happy path
// ---------------------------------------------------------------------------

it('accepts a valid invitation: updates the User, flips the relation to roster, emits audit', function (): void {
    ['token' => $token, 'relation' => $relation, 'user' => $invitee] = provisionInvitation();

    $response = $this->postJson('/api/v1/auth/sign-up', [
        'name' => 'Real Name',
        'email' => 'invitee@example.com',
        'password' => STRONG_PASSWORD,
        'password_confirmation' => STRONG_PASSWORD,
        'invitation_token' => $token,
        'preferred_language' => 'pt',
    ]);

    expect($response->status())->toBe(201);

    $fresh = $invitee->fresh();
    assert($fresh !== null);
    expect($fresh->name)->toBe('Real Name');
    expect($fresh->email_verified_at)->not->toBeNull();
    expect(Hash::check(STRONG_PASSWORD, $fresh->password))->toBeTrue();
    expect($fresh->preferred_language)->toBe('pt');

    $freshRelation = $relation->fresh();
    assert($freshRelation !== null);
    expect($freshRelation->relationship_status)->toBe(RelationshipStatus::Roster);
    expect($freshRelation->invitation_token_hash)->toBeNull();

    $audit = AuditLog::query()
        ->where('action', AuditAction::CreatorInvitationAccepted->value)
        ->latest('id')
        ->first();
    expect($audit)->not->toBeNull();
});

it('does not create a new User row when the invitation path is used (the bulk-invite User is reused)', function (): void {
    ['token' => $token] = provisionInvitation();
    $before = User::query()->count();

    $this->postJson('/api/v1/auth/sign-up', [
        'name' => 'Real Name',
        'email' => 'invitee@example.com',
        'password' => STRONG_PASSWORD,
        'password_confirmation' => STRONG_PASSWORD,
        'invitation_token' => $token,
    ])->assertStatus(201);

    // The provisioning created (1) the invitee + (2) the inviter. Sign-up
    // adds zero new users — it updates the existing invitee row.
    expect(User::query()->count())->toBe($before);
});

// ---------------------------------------------------------------------------
// Failure: invitation.not_found
// ---------------------------------------------------------------------------

it('returns 422 + invitation.not_found when the token does not match any relation', function (): void {
    $response = $this->postJson('/api/v1/auth/sign-up', [
        'name' => 'Real Name',
        'email' => 'someone@example.com',
        'password' => STRONG_PASSWORD,
        'password_confirmation' => STRONG_PASSWORD,
        'invitation_token' => 'nonexistent-token-aaaaaaaa',
    ]);

    expect($response->status())->toBe(422);
    expect($response->json('errors.0.code'))->toBe('invitation.not_found');
});

// ---------------------------------------------------------------------------
// Failure: invitation.expired
// ---------------------------------------------------------------------------

it('returns 422 + invitation.expired when the invitation expires_at is past', function (): void {
    ['token' => $token, 'user' => $invitee] = provisionInvitation([
        'expires_at' => now()->subDay(),
    ]);

    $response = $this->postJson('/api/v1/auth/sign-up', [
        'name' => 'Real Name',
        'email' => 'invitee@example.com',
        'password' => STRONG_PASSWORD,
        'password_confirmation' => STRONG_PASSWORD,
        'invitation_token' => $token,
    ]);

    expect($response->status())->toBe(422);
    expect($response->json('errors.0.code'))->toBe('invitation.expired');
    $fresh = $invitee->fresh();
    assert($fresh !== null);
    expect($fresh->email_verified_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Failure: invitation.already_accepted
// ---------------------------------------------------------------------------

it('returns 422 + invitation.already_accepted when the relation is already in roster', function (): void {
    ['token' => $token] = provisionInvitation([
        'status' => RelationshipStatus::Roster->value,
    ]);

    $response = $this->postJson('/api/v1/auth/sign-up', [
        'name' => 'Real Name',
        'email' => 'invitee@example.com',
        'password' => STRONG_PASSWORD,
        'password_confirmation' => STRONG_PASSWORD,
        'invitation_token' => $token,
    ]);

    expect($response->status())->toBe(422);
    expect($response->json('errors.0.code'))->toBe('invitation.already_accepted');
});

// ---------------------------------------------------------------------------
// Failure: invitation.email_mismatch (the post-submit hard-lock from C2=a)
// ---------------------------------------------------------------------------

it('returns 422 + invitation.email_mismatch when the typed email differs from the bound user', function (): void {
    ['token' => $token, 'user' => $invitee] = provisionInvitation([
        'email' => 'real-invitee@example.com',
    ]);

    $response = $this->postJson('/api/v1/auth/sign-up', [
        'name' => 'Real Name',
        'email' => 'different-email@example.com',
        'password' => STRONG_PASSWORD,
        'password_confirmation' => STRONG_PASSWORD,
        'invitation_token' => $token,
    ]);

    expect($response->status())->toBe(422);
    expect($response->json('errors.0.code'))->toBe('invitation.email_mismatch');
    expect($response->json('errors.0.source.pointer'))->toBe('/data/attributes/email');

    // No state changes occurred.
    $fresh = $invitee->fresh();
    assert($fresh !== null);
    expect($fresh->email_verified_at)->toBeNull();
});

it('treats the typed email case-insensitively when comparing to the bound user', function (): void {
    ['token' => $token] = provisionInvitation([
        'email' => 'invitee@example.com',
    ]);

    $response = $this->postJson('/api/v1/auth/sign-up', [
        'name' => 'Real Name',
        // Same email, different case — must NOT trigger email_mismatch.
        'email' => 'INVITEE@EXAMPLE.COM',
        'password' => STRONG_PASSWORD,
        'password_confirmation' => STRONG_PASSWORD,
        'invitation_token' => $token,
    ]);

    expect($response->status())->toBe(201);
});

// ---------------------------------------------------------------------------
// Unique-email rule relaxation
// ---------------------------------------------------------------------------

it('does not return 422 unique-email error when invitation_token is present (relaxes the unique rule)', function (): void {
    // The pre-created User row would normally fail the
    // `Rule::unique('users', 'email')` check on the sign-up form-request.
    // With invitation_token present, the rule is relaxed.
    ['token' => $token] = provisionInvitation();

    $response = $this->postJson('/api/v1/auth/sign-up', [
        'name' => 'Real Name',
        'email' => 'invitee@example.com',
        'password' => STRONG_PASSWORD,
        'password_confirmation' => STRONG_PASSWORD,
        'invitation_token' => $token,
    ]);

    expect($response->status())->toBe(201);
});

it('still enforces unique-email when no invitation_token is provided', function (): void {
    User::factory()->createOne(['email' => 'taken@example.com']);

    $response = $this->postJson('/api/v1/auth/sign-up', [
        'name' => 'Real Name',
        'email' => 'taken@example.com',
        'password' => STRONG_PASSWORD,
        'password_confirmation' => STRONG_PASSWORD,
    ]);

    expect($response->status())->toBe(422);
});
