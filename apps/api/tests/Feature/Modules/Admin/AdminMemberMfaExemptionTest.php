<?php

declare(strict_types=1);

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Database\Factories\AgencyMembershipFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Mail\MfaExemptionGrantedMail;
use App\Modules\Identity\Mail\MfaExemptionRemovedMail;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-087 — Exempt / un-exempt from 2FA (the policy operation).
 *
 *   POST /api/v1/admin/agencies/{agency}/members/{user}/exempt-2fa
 *   POST /api/v1/admin/agencies/{agency}/members/{user}/remove-2fa-exemption
 *
 * Review priorities #1 (structural inertness) and #2 (platform admins are
 * never exemptable) both live here. #1's server half is the NULL-population
 * proof below; its client half is the guard spec in the main SPA.
 */
function ah087ExemptionAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

function ah087ExemptionMember(Agency $agency, array $attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'type' => UserType::AgencyUser,
    ], $attributes));

    AgencyMembershipFactory::new()->create([
        'agency_id' => $agency->id,
        'user_id' => $user->id,
        'accepted_at' => now(),
    ]);

    return $user;
}

function ah087ExemptUrl(Agency $agency, User $user): string
{
    return "/api/v1/admin/agencies/{$agency->ulid}/members/{$user->ulid}/exempt-2fa";
}

function ah087RemoveExemptionUrl(Agency $agency, User $user): string
{
    return "/api/v1/admin/agencies/{$agency->ulid}/members/{$user->ulid}/remove-2fa-exemption";
}

// ─── Priority #1: STRUCTURAL INERTNESS ──────────────────────────────────

it('leaves every newly created user NOT exempt — the columns default to NULL', function (): void {
    // The whole inertness argument in one assertion: the exemption state is a
    // nullable timestamp, so the default is "not exempt" for every row that
    // exists, with no backfill and nothing written at deploy. Contrast the
    // rejected mfa_required approach, whose default (false) would have read as
    // "exempt" for the entire live agency population.
    $agency = AgencyFactory::new()->createOne();

    foreach ([
        ah087ExemptionMember($agency),
        ah087ExemptionMember($agency, ['two_factor_confirmed_at' => now()]),
        User::factory()->create(['type' => UserType::PlatformAdmin]),
        User::factory()->create(['type' => UserType::Creator]),
    ] as $user) {
        expect($user->mfa_exempted_at)->toBeNull()
            ->and($user->mfa_exemption_reason)->toBeNull()
            ->and($user->isMfaExempt())->toBeFalse();
    }
});

it('touches NOTHING but the two exemption columns when granting', function (): void {
    Mail::fake();
    $admin = ah087ExemptionAdmin();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087ExemptionMember($agency, [
        'two_factor_secret' => 'SECRETVALUE',
        'two_factor_recovery_codes' => ['aaa-bbb'],
        'two_factor_confirmed_at' => now(),
        'mfa_required' => false,
    ]);
    $before = $member->only(['two_factor_confirmed_at', 'mfa_required', 'is_suspended']);

    $this->actingAs($admin, 'web_admin')
        ->postJson(ah087ExemptUrl($agency, $member), ['reason' => 'No smartphone; works from a shared terminal.']);

    $member->refresh();
    // The exemption removes the REQUIREMENT, never the enrollment — so the
    // authenticator survives and the login gate keeps challenging them.
    expect($member->two_factor_secret)->toBe('SECRETVALUE')
        ->and($member->hasTwoFactorEnabled())->toBeTrue()
        ->and($member->two_factor_confirmed_at?->toIso8601String())
        ->toBe($before['two_factor_confirmed_at']?->toIso8601String())
        // mfa_required stays dormant with its documented meaning (AH-087 R1).
        ->and($member->mfa_required)->toBe($before['mfa_required'])
        ->and($member->is_suspended)->toBe($before['is_suspended']);
});

// ─── Priority #2: platform admins are NEVER exemptable ──────────────────

it('422s an attempt to exempt a platform admin and writes nothing', function (): void {
    Mail::fake();
    $admin = ah087ExemptionAdmin();
    $agency = AgencyFactory::new()->createOne();

    // A platform_admin who also holds a membership row — structurally
    // possible, so the refusal has to be enforced rather than assumed.
    $target = ah087ExemptionMember($agency, ['type' => UserType::PlatformAdmin]);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson(ah087ExemptUrl($agency, $target), ['reason' => 'Trying to exempt a platform administrator.']);

    expect($response->status())->toBe(422);
    expect($response->json('errors.0.code'))->toBe('mfa.platform_admin_not_exemptable');

    // §5.34 — the refusal is total: no column written, no audit row, no mail.
    expect($target->refresh()->isMfaExempt())->toBeFalse();
    expect(AuditLog::query()->where('action', AuditAction::UserMfaExempted->value)->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('keeps the admin server-side gate blind to exemptions', function (): void {
    // Even if a platform admin somehow carried the columns, EnsureMfaForAdmins
    // reads enrollment only. The exemption can never open an admin door.
    config()->set('auth.admin_mfa_enforced', true);

    $admin = User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => null,
        'mfa_exempted_at' => now(),
        'mfa_exemption_reason' => 'Should not matter anywhere.',
    ]);

    $response = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/agencies');

    expect($response->status())->toBe(403);
    expect($response->json('errors.0.code'))->toBe('auth.mfa.enrollment_required');
});

// ─── Grant: state, audit, mail ──────────────────────────────────────────

it('stamps both exemption columns and audits with the reason', function (): void {
    Mail::fake();
    $admin = ah087ExemptionAdmin();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087ExemptionMember($agency);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson(ah087ExemptUrl($agency, $member), ['reason' => 'Warehouse staff without a personal phone.']);

    expect($response->status())->toBe(200);
    expect($response->json('data.attributes.is_mfa_exempt'))->toBeTrue();

    $member->refresh();
    expect($member->isMfaExempt())->toBeTrue()
        ->and($member->mfa_exemption_reason)->toBe('Warehouse staff without a personal phone.');

    $row = AuditLog::query()->where('action', AuditAction::UserMfaExempted->value)->firstOrFail();
    expect($row->actor_id)->toBe($admin->id)
        ->and($row->subject_id)->toBe($member->id)
        ->and($row->reason)->toBe('Warehouse staff without a personal phone.');

    Mail::assertQueued(MfaExemptionGrantedMail::class, fn (MfaExemptionGrantedMail $mail): bool => $mail->hasTo($member->email));
});

it('409s exempting an already-exempt member without re-stamping the original', function (): void {
    Mail::fake();
    $admin = ah087ExemptionAdmin();
    $agency = AgencyFactory::new()->createOne();
    $granted = now()->subDays(3);
    $member = ah087ExemptionMember($agency, [
        'mfa_exempted_at' => $granted,
        'mfa_exemption_reason' => 'The original reason, which must survive.',
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson(ah087ExemptUrl($agency, $member), ['reason' => 'A second, later attempt at the same thing.']);

    expect($response->status())->toBe(409);
    expect($response->json('errors.0.code'))->toBe('mfa.already_exempt');

    $member->refresh();
    expect($member->mfa_exemption_reason)->toBe('The original reason, which must survive.')
        ->and($member->mfa_exempted_at?->toIso8601String())->toBe($granted->toIso8601String());
    Mail::assertNothingQueued();
});

// ─── Removal: state, audit (reason REQUIRED here too), mail ─────────────

it('clears both columns, audits with a reason, and emails on removal', function (): void {
    Mail::fake();
    $admin = ah087ExemptionAdmin();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087ExemptionMember($agency, [
        'mfa_exempted_at' => now()->subWeek(),
        'mfa_exemption_reason' => 'Granted while their device was in repair.',
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson(ah087RemoveExemptionUrl($agency, $member), ['reason' => 'Device replaced; back under the normal policy.']);

    expect($response->status())->toBe(200);
    expect($response->json('data.attributes.is_mfa_exempt'))->toBeFalse();

    $member->refresh();
    expect($member->mfa_exempted_at)->toBeNull()
        ->and($member->mfa_exemption_reason)->toBeNull();

    $row = AuditLog::query()->where('action', AuditAction::UserMfaExemptionRemoved->value)->firstOrFail();
    // Deliberately divergent from agency.reactivated, which needs no reason:
    // restoring a requirement on a PERSON answers "why" in both directions.
    expect($row->reason)->toBe('Device replaced; back under the normal policy.');

    Mail::assertQueued(MfaExemptionRemovedMail::class, fn (MfaExemptionRemovedMail $mail): bool => $mail->hasTo($member->email));
});

it('409s removing an exemption that does not exist', function (): void {
    Mail::fake();
    $admin = ah087ExemptionAdmin();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087ExemptionMember($agency);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson(ah087RemoveExemptionUrl($agency, $member), ['reason' => 'Removing something that is not there.']);

    expect($response->status())->toBe(409);
    expect($response->json('errors.0.code'))->toBe('mfa.not_exempt');
    Mail::assertNothingQueued();
});

it('allows removing an exemption from a platform-admin target (restoring is never blocked)', function (): void {
    Mail::fake();
    $admin = ah087ExemptionAdmin();
    $agency = AgencyFactory::new()->createOne();
    $target = ah087ExemptionMember($agency, [
        'type' => UserType::PlatformAdmin,
        'mfa_exempted_at' => now()->subMonth(),
        'mfa_exemption_reason' => 'A row that should not exist, but might.',
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson(ah087RemoveExemptionUrl($agency, $target), ['reason' => 'Cleaning up an exemption on an admin account.']);

    // The 422 guards the GRANT direction only — clearing an exemption always
    // moves toward the stricter state, so it is never the risky operation.
    expect($response->status())->toBe(200);
    expect($target->refresh()->isMfaExempt())->toBeFalse();
});

// ─── Reason mandatory in BOTH directions, authorization ─────────────────

it('422s a missing reason in both directions and changes nothing', function (): void {
    Mail::fake();
    $admin = ah087ExemptionAdmin();
    $agency = AgencyFactory::new()->createOne();
    $plain = ah087ExemptionMember($agency);
    $exempt = ah087ExemptionMember($agency, [
        'mfa_exempted_at' => now(),
        'mfa_exemption_reason' => 'Existing exemption.',
    ]);

    expect($this->actingAs($admin, 'web_admin')->postJson(ah087ExemptUrl($agency, $plain), [])->status())->toBe(422);
    expect($this->actingAs($admin, 'web_admin')->postJson(ah087RemoveExemptionUrl($agency, $exempt), [])->status())->toBe(422);

    expect($plain->refresh()->isMfaExempt())->toBeFalse();
    expect($exempt->refresh()->isMfaExempt())->toBeTrue();
    Mail::assertNothingQueued();
});

it('404s when the target is not a member of the given agency', function (): void {
    Mail::fake();
    $admin = ah087ExemptionAdmin();
    $agency = AgencyFactory::new()->createOne();
    $outsider = ah087ExemptionMember(AgencyFactory::new()->createOne());

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson(ah087ExemptUrl($agency, $outsider), ['reason' => 'Wrong agency in the URL, deliberately.']);

    expect($response->status())->toBe(404);
    expect($outsider->refresh()->isMfaExempt())->toBeFalse();
});

it('403s an agency user attempting to exempt a colleague', function (): void {
    Mail::fake();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087ExemptionMember($agency);
    $agencyAdmin = ah087ExemptionMember($agency, ['two_factor_confirmed_at' => now()]);

    $response = $this->actingAs($agencyAdmin, 'web_admin')
        ->postJson(ah087ExemptUrl($agency, $member), ['reason' => 'An agency user should never hold this power.']);

    expect($response->status())->toBe(403);
    expect($member->refresh()->isMfaExempt())->toBeFalse();
});

it('401s an anonymous exemption attempt', function (): void {
    Mail::fake();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087ExemptionMember($agency);

    $response = $this->postJson(ah087ExemptUrl($agency, $member), ['reason' => 'Anonymous attempt at an exemption.']);

    expect($response->status())->toBe(401);
    expect($member->refresh()->isMfaExempt())->toBeFalse();
});

it('never puts the admin reason or identity in either outgoing mail', function (): void {
    Mail::fake();
    $admin = ah087ExemptionAdmin();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087ExemptionMember($agency);
    $grantReason = 'Executive decision by the ops lead after a dispute.';

    $this->actingAs($admin, 'web_admin')
        ->postJson(ah087ExemptUrl($agency, $member), ['reason' => $grantReason]);

    Mail::assertQueued(MfaExemptionGrantedMail::class, function (MfaExemptionGrantedMail $mail) use ($grantReason, $admin): bool {
        $rendered = (string) $mail->render();

        return ! str_contains($rendered, $grantReason)
            && ! str_contains($rendered, $admin->email)
            && ! str_contains($rendered, $admin->name);
    });
});
