<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Features\ApplicationNotificationsEnabled;
use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Features\IncompleteCreatorNudgeEnabled;
use App\Modules\Creators\Features\JobPostedNotificationsEnabled;
use App\Modules\Creators\Features\KycVerificationEnabled;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 13 (D-6) — the admin feature-flag toggle.
 *
 *   GET  /api/v1/admin/feature-flags
 *   POST /api/v1/admin/feature-flags/{flag}
 *
 * The RUNTIME mutation path over DB-backed Pennant. Every flip is
 * platform_admin-gated, writes a feature_flag.toggled audit row with a
 * MANDATORY reason, and flips Feature::active() live.
 */
function makeFlagAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

it('401s an unauthenticated list request', function (): void {
    expect($this->getJson('/api/v1/admin/feature-flags')->status())->toBe(401);
});

it('403s a non-admin listing flags', function (): void {
    $nonAdmin = User::factory()->create([
        'type' => UserType::AgencyUser,
        'two_factor_confirmed_at' => now(),
    ]);

    expect($this->actingAs($nonAdmin, 'web_admin')->getJson('/api/v1/admin/feature-flags')->status())
        ->toBe(403);
});

it('lists every registered flag with its current state', function (): void {
    $admin = makeFlagAdmin();

    $response = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/feature-flags');

    expect($response->status())->toBe(200);
    /** @var list<array{id: string, attributes: array{name: string, enabled: bool}}> $rows */
    $rows = $response->json('data');
    $names = array_map(static fn (array $row): string => $row['attributes']['name'], $rows);

    expect($names)->toContain(KycVerificationEnabled::NAME)
        ->and($names)->toContain(ContractSigningEnabled::NAME)
        ->and($names)->toContain(IncompleteCreatorNudgeEnabled::NAME)
        ->and($names)->toContain(JobPostedNotificationsEnabled::NAME)
        ->and($names)->toContain(ApplicationNotificationsEnabled::NAME);
    expect($response->json('data.0.attributes'))->toHaveKeys([
        'name', 'label', 'description', 'enabled',
    ]);
});

it('lets an admin arm the jobs-board fan-out from the same registry (AH-056, D6)', function (): void {
    $admin = makeFlagAdmin();
    expect(Feature::active(JobPostedNotificationsEnabled::NAME))->toBeFalse();

    $response = $this->actingAs($admin, 'web_admin')->postJson(
        '/api/v1/admin/feature-flags/'.JobPostedNotificationsEnabled::NAME,
        ['enabled' => true, 'reason' => 'Dry-run read is clean; arming job-posted mail.'],
    );

    expect($response->status())->toBe(200);
    expect(Feature::active(JobPostedNotificationsEnabled::NAME))->toBeTrue();

    // The kill switch has to be reachable in BOTH directions from the SPA —
    // arming a mail fan-out you cannot disarm is not a kill switch.
    $off = $this->actingAs($admin, 'web_admin')->postJson(
        '/api/v1/admin/feature-flags/'.JobPostedNotificationsEnabled::NAME,
        ['enabled' => false, 'reason' => 'Pausing the fan-out.'],
    );

    expect($off->status())->toBe(200);
    expect(Feature::active(JobPostedNotificationsEnabled::NAME))->toBeFalse();
});

it('lets an admin arm + disarm the application mails from the same registry (AH-058, D6)', function (): void {
    $admin = makeFlagAdmin();
    expect(Feature::active(ApplicationNotificationsEnabled::NAME))->toBeFalse();

    $on = $this->actingAs($admin, 'web_admin')->postJson(
        '/api/v1/admin/feature-flags/'.ApplicationNotificationsEnabled::NAME,
        ['enabled' => true, 'reason' => 'Arming the jobs-board application mails with the arc.'],
    );

    expect($on->status())->toBe(200);
    expect(Feature::active(ApplicationNotificationsEnabled::NAME))->toBeTrue();

    // The AH-056 lesson, re-pinned: a flag reachable only from `tinker` is not
    // an operator control. Both directions must work from the SPA, because the
    // whole value of this flag is how fast an unintended send stops.
    $off = $this->actingAs($admin, 'web_admin')->postJson(
        '/api/v1/admin/feature-flags/'.ApplicationNotificationsEnabled::NAME,
        ['enabled' => false, 'reason' => 'Pausing the application mails.'],
    );

    expect($off->status())->toBe(200);
    expect(Feature::active(ApplicationNotificationsEnabled::NAME))->toBeFalse();
});

it('activates a flag, flips Feature::active live, and writes an audit row', function (): void {
    $admin = makeFlagAdmin();
    expect(Feature::active(KycVerificationEnabled::NAME))->toBeFalse();

    $response = $this->actingAs($admin, 'web_admin')->postJson(
        '/api/v1/admin/feature-flags/'.KycVerificationEnabled::NAME,
        ['enabled' => true, 'reason' => 'Enabling KYC for the launch cohort.'],
    );

    expect($response->status())->toBe(200);
    expect($response->json('data.attributes.enabled'))->toBeTrue();
    expect(Feature::active(KycVerificationEnabled::NAME))->toBeTrue();

    $log = AuditLog::query()->where('action', AuditAction::FeatureFlagToggled->value)->latest('id')->firstOrFail();
    expect($log->reason)->toBe('Enabling KYC for the launch cohort.');
    expect($log->metadata)->toMatchArray([
        'flag' => KycVerificationEnabled::NAME,
        'enabled' => true,
    ]);
});

it('deactivates an active flag and records the flip', function (): void {
    $admin = makeFlagAdmin();
    Feature::activate(KycVerificationEnabled::NAME);
    expect(Feature::active(KycVerificationEnabled::NAME))->toBeTrue();

    $response = $this->actingAs($admin, 'web_admin')->postJson(
        '/api/v1/admin/feature-flags/'.KycVerificationEnabled::NAME,
        ['enabled' => false, 'reason' => 'Disabling KYC after the test window.'],
    );

    expect($response->status())->toBe(200);
    expect($response->json('data.attributes.enabled'))->toBeFalse();
    expect(Feature::active(KycVerificationEnabled::NAME))->toBeFalse();

    $log = AuditLog::query()->where('action', AuditAction::FeatureFlagToggled->value)->latest('id')->firstOrFail();
    expect($log->metadata)->toMatchArray(['enabled' => false]);
});

it('422s a toggle with no reason (the verb requiresReason)', function (): void {
    $admin = makeFlagAdmin();

    $response = $this->actingAs($admin, 'web_admin')->postJson(
        '/api/v1/admin/feature-flags/'.KycVerificationEnabled::NAME,
        ['enabled' => true],
    );

    expect($response->status())->toBe(422);
    // The flip never happened — the missing reason short-circuits before
    // Feature::activate is reached.
    expect(Feature::active(KycVerificationEnabled::NAME))->toBeFalse();
});

it('404s an unknown flag name (the registry allowlist)', function (): void {
    $admin = makeFlagAdmin();

    $response = $this->actingAs($admin, 'web_admin')->postJson(
        '/api/v1/admin/feature-flags/not_a_real_flag',
        ['enabled' => true, 'reason' => 'Trying to flip a bogus flag.'],
    );

    expect($response->status())->toBe(404);
});

it('403s a non-admin attempting a toggle', function (): void {
    $nonAdmin = User::factory()->create([
        'type' => UserType::AgencyUser,
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->actingAs($nonAdmin, 'web_admin')->postJson(
        '/api/v1/admin/feature-flags/'.KycVerificationEnabled::NAME,
        ['enabled' => true, 'reason' => 'Should never get through.'],
    );

    expect($response->status())->toBe(403);
    expect(Feature::active(KycVerificationEnabled::NAME))->toBeFalse();
});
