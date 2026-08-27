<?php

declare(strict_types=1);

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Database\Factories\AgencyMembershipFactory;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Mail\MfaResetByAdminMail;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-087 — Reset 2FA (the support operation).
 *
 *   POST /api/v1/admin/agencies/{agency}/members/{user}/reset-2fa
 *
 * Review priority #3: the reset clears the COMPLETE artifact set. Partial
 * clearing is the dangerous failure — a NULLed secret beside a stale
 * `two_factor_confirmed_at` reads as "enrolled" to the login gate while no
 * authenticator on earth can satisfy it.
 *
 * The `ah087` prefix on the helpers below is not decoration: Pest's top-level
 * `function` declarations share ONE global namespace across the whole suite, so
 * a plainly-named helper here fatals the entire run the moment another file
 * declares the same name (this file's first draft declared `resetUrl()` and
 * collided with `Modules/Boards/BoardResetTest.php` — the AH-085 bug class,
 * caught by the full-suite run rather than the targeted one).
 */
function ah087ResetAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

function ah087EnrolledMember(Agency $agency, array $attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'type' => UserType::AgencyUser,
        'two_factor_secret' => 'SECRETVALUE',
        'two_factor_recovery_codes' => ['aaa-bbb', 'ccc-ddd'],
        'two_factor_confirmed_at' => now(),
    ], $attributes));

    AgencyMembershipFactory::new()->create([
        'agency_id' => $agency->id,
        'user_id' => $user->id,
        'accepted_at' => now(),
    ]);

    return $user;
}

function ah087ResetUrl(Agency $agency, User $user): string
{
    return "/api/v1/admin/agencies/{$agency->ulid}/members/{$user->ulid}/reset-2fa";
}

// ─── The complete wipe (priority #3) ───────────────────────────────────

it('clears every enrollment artifact in one transaction', function (): void {
    Mail::fake();
    $admin = ah087ResetAdmin();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087EnrolledMember($agency, ['two_factor_enrollment_suspended_at' => now()]);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson(ah087ResetUrl($agency, $member), ['reason' => 'Lost their phone, verified by video call.']);

    expect($response->status())->toBe(200);

    $member->refresh();
    expect($member->two_factor_secret)->toBeNull()
        ->and($member->two_factor_recovery_codes)->toBeNull()
        ->and($member->two_factor_confirmed_at)->toBeNull()
        ->and($member->two_factor_enrollment_suspended_at)->toBeNull()
        ->and($member->hasTwoFactorEnabled())->toBeFalse();
});

it('leaves the account MFA posture untouched — reset is not an exemption', function (): void {
    Mail::fake();
    $admin = ah087ResetAdmin();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087EnrolledMember($agency, ['mfa_required' => true]);

    $this->actingAs($admin, 'web_admin')
        ->postJson(ah087ResetUrl($agency, $member), ['reason' => 'Support request, device replaced.']);

    $member->refresh();
    // The requirement columns are not this operation's business: the user
    // re-enrolls, they are not excused from enrolling.
    expect($member->mfa_required)->toBeTrue()
        ->and($member->mfa_exempted_at)->toBeNull()
        ->and($member->mfa_exemption_reason)->toBeNull();
});

it('returns the refreshed member row showing not-enrolled', function (): void {
    Mail::fake();
    $admin = ah087ResetAdmin();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087EnrolledMember($agency);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson(ah087ResetUrl($agency, $member), ['reason' => 'Lost their authenticator device.']);

    expect($response->json('data.attributes.is_mfa_enrolled'))->toBeFalse();
    expect($response->getContent())->not->toContain('SECRETVALUE');
});

// ─── Audit (§5.5 transactional, and the DELIBERATE non-dispatch) ────────

it('writes ONE user.mfa_reset row carrying the reason, attributed to the ADMIN', function (): void {
    Mail::fake();
    $admin = ah087ResetAdmin();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087EnrolledMember($agency);

    $this->actingAs($admin, 'web_admin')
        ->postJson(ah087ResetUrl($agency, $member), ['reason' => 'Device lost; identity confirmed on a call.']);

    // sole() carries the "exactly one row" half of the assertion itself: a
    // second write would throw rather than quietly pass the field checks below.
    $row = AuditLog::query()->where('action', AuditAction::UserMfaReset->value)->sole();

    expect($row->actor_id)->toBe($admin->id)
        ->and($row->subject_id)->toBe($member->id)
        ->and($row->reason)->toBe('Device lost; identity confirmed on a call.');
    expect($row->metadata['agency_id'] ?? null)->toBe($agency->ulid);
});

it('does NOT write a self-service mfa.disabled row (TwoFactorDisabled deliberately not dispatched)', function (): void {
    Mail::fake();
    $admin = ah087ResetAdmin();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087EnrolledMember($agency);

    $this->actingAs($admin, 'web_admin')
        ->postJson(ah087ResetUrl($agency, $member), ['reason' => 'Support reset after device loss.']);

    // §5.34 — the negative assertion that carries the design decision. An
    // admin reset must never appear in the trail as the user disabling their
    // own 2FA; that verb's listener attributes the action to the subject.
    expect(AuditLog::query()->where('action', AuditAction::MfaDisabled->value)->count())->toBe(0);
    // And no generic user.updated row either — the write is quiet by design.
    expect(AuditLog::query()->where('action', AuditAction::UserUpdated->value)->count())->toBe(0);
});

it('keeps secret material out of the audit row entirely', function (): void {
    Mail::fake();
    $admin = ah087ResetAdmin();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087EnrolledMember($agency);

    $this->actingAs($admin, 'web_admin')
        ->postJson(ah087ResetUrl($agency, $member), ['reason' => 'Device lost, support-verified.']);

    $row = AuditLog::query()->where('action', AuditAction::UserMfaReset->value)->firstOrFail();

    expect(AuditAction::UserMfaReset->isSensitiveCredentialAction())->toBeTrue();
    expect(json_encode([$row->before, $row->after, $row->metadata]))
        ->not->toContain('SECRETVALUE')
        ->and(json_encode([$row->before, $row->after, $row->metadata]))->not->toContain('aaa-bbb');
});

// ─── Notification ───────────────────────────────────────────────────────

it('emails the affected member (email-only — no in-app notification)', function (): void {
    Mail::fake();
    $admin = ah087ResetAdmin();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087EnrolledMember($agency);

    $this->actingAs($admin, 'web_admin')
        ->postJson(ah087ResetUrl($agency, $member), ['reason' => 'Lost device, verified by phone.']);

    Mail::assertQueued(MfaResetByAdminMail::class, function (MfaResetByAdminMail $mail) use ($member): bool {
        return $mail->hasTo($member->email);
    });

    // The blacklist precedent: this arc ships no NotificationType, so nothing
    // lands in the in-app feed.
    expect(Notification::query()->count())->toBe(0);
});

it('never puts the admin reason in the outgoing mail', function (): void {
    Mail::fake();
    $admin = ah087ResetAdmin();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087EnrolledMember($agency);
    $reason = 'Suspected credential sharing with an ex-contractor.';

    $this->actingAs($admin, 'web_admin')
        ->postJson(ah087ResetUrl($agency, $member), ['reason' => $reason]);

    Mail::assertQueued(MfaResetByAdminMail::class, function (MfaResetByAdminMail $mail) use ($reason, $admin): bool {
        $rendered = (string) $mail->render();

        // The AH-051 eyes-on discipline: the internal rationale and the acting
        // admin's identity are both absent from user-facing copy.
        return ! str_contains($rendered, $reason)
            && ! str_contains($rendered, $admin->email)
            && ! str_contains($rendered, $admin->name);
    });
});

// ─── Idempotency / validation / authorization ───────────────────────────

it('409s with mfa.nothing_to_reset when the member has no enrollment at all', function (): void {
    Mail::fake();
    $admin = ah087ResetAdmin();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087EnrolledMember($agency, [
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson(ah087ResetUrl($agency, $member), ['reason' => 'Speculative reset with nothing to clear.']);

    expect($response->status())->toBe(409);
    expect($response->json('errors.0.code'))->toBe('mfa.nothing_to_reset');
    expect(AuditLog::query()->where('action', AuditAction::UserMfaReset->value)->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('DOES reset a member frozen by the failed-attempt throttle before confirming', function (): void {
    Mail::fake();
    $admin = ah087ResetAdmin();
    $agency = AgencyFactory::new()->createOne();
    // The stuck case the enrollment-suspension column was added for: frozen
    // mid-enrollment, never confirmed. Treating this as "nothing to reset"
    // would leave the only person who can help them unable to.
    $member = ah087EnrolledMember($agency, [
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
        'two_factor_enrollment_suspended_at' => now(),
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson(ah087ResetUrl($agency, $member), ['reason' => 'Enrollment frozen by failed attempts; user verified.']);

    expect($response->status())->toBe(200);
    expect($member->refresh()->two_factor_enrollment_suspended_at)->toBeNull();
});

it('422s a missing or too-short reason and changes nothing', function (): void {
    Mail::fake();
    $admin = ah087ResetAdmin();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087EnrolledMember($agency);

    expect($this->actingAs($admin, 'web_admin')->postJson(ah087ResetUrl($agency, $member), [])->status())->toBe(422);
    expect($this->actingAs($admin, 'web_admin')->postJson(ah087ResetUrl($agency, $member), ['reason' => 'too short'])->status())->toBe(422);

    expect($member->refresh()->hasTwoFactorEnabled())->toBeTrue();
    expect(AuditLog::query()->where('action', AuditAction::UserMfaReset->value)->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('404s when the target user is not a member of the given agency', function (): void {
    Mail::fake();
    $admin = ah087ResetAdmin();
    $agency = AgencyFactory::new()->createOne();
    $other = AgencyFactory::new()->createOne();
    $outsider = ah087EnrolledMember($other);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson(ah087ResetUrl($agency, $outsider), ['reason' => 'Wrong agency in the URL, deliberately.']);

    expect($response->status())->toBe(404);
    expect($outsider->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('403s an agency user attempting a reset', function (): void {
    Mail::fake();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087EnrolledMember($agency);
    $agencyUser = User::factory()->create([
        'type' => UserType::AgencyUser,
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->actingAs($agencyUser, 'web_admin')
        ->postJson(ah087ResetUrl($agency, $member), ['reason' => 'Not my call to make, apparently.']);

    expect($response->status())->toBe(403);
    expect($member->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('401s an anonymous reset attempt', function (): void {
    Mail::fake();
    $agency = AgencyFactory::new()->createOne();
    $member = ah087EnrolledMember($agency);

    $response = $this->postJson(ah087ResetUrl($agency, $member), ['reason' => 'Anonymous attempt at a reset.']);

    expect($response->status())->toBe(401);
    expect($member->refresh()->hasTwoFactorEnabled())->toBeTrue();
});
