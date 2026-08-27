<?php

declare(strict_types=1);

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Agencies\Database\Factories\AgencyMembershipFactory;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-087 — the admin agency-members drill-in (read side).
 *
 *   GET /api/v1/admin/agencies/{agency}/members
 *
 * The load-bearing test in this file is the EXACT-KEYSET pin (D4): it is what
 * proves an admin can never see secret material through this surface, and it
 * fails the moment anyone adds a field to the payload without arguing for it.
 */
function ah087MembersAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

function ah087MemberOf(Agency $agency, array $userAttributes = [], AgencyRole $role = AgencyRole::AgencyStaff): User
{
    $user = User::factory()->create(array_merge([
        'type' => UserType::AgencyUser,
    ], $userAttributes));

    AgencyMembershipFactory::new()->create([
        'agency_id' => $agency->id,
        'user_id' => $user->id,
        'role' => $role->value,
        'accepted_at' => now(),
    ]);

    return $user;
}

/**
 * One member row's attributes, looked up by ULID.
 *
 * A hand-rolled search rather than `collect($response->json('data'))->keyBy('id')`:
 * a decoded JSON body is `mixed`, and handing that straight to `collect()` leaves
 * its generics unresolved.
 *
 * @return array<string, mixed>
 */
function ah087AttributesFor(mixed $data, string $ulid): array
{
    foreach (is_array($data) ? $data : [] as $row) {
        if (is_array($row) && ($row['id'] ?? null) === $ulid) {
            $attributes = $row['attributes'] ?? null;

            return is_array($attributes) ? $attributes : [];
        }
    }

    return [];
}

// ─── Authorization (the standing 403/401 standard) ──────────────────────

it('lists an agency members for a platform admin', function (): void {
    $admin = ah087MembersAdmin();
    $agency = AgencyFactory::new()->createOne();
    ah087MemberOf($agency);
    ah087MemberOf($agency);

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/agencies/{$agency->ulid}/members");

    expect($response->status())->toBe(200);
    expect($response->json('meta.total'))->toBe(2);
});

it('403s an agency user hitting the admin members endpoint for their OWN agency', function (): void {
    $agency = AgencyFactory::new()->createOne();
    $agencyAdmin = ah087MemberOf($agency, [], AgencyRole::AgencyAdmin);

    $response = $this->actingAs($agencyAdmin, 'web_admin')
        ->getJson("/api/v1/admin/agencies/{$agency->ulid}/members");

    // Even an agency_admin of this very agency gets 403: the admin console is
    // not an in-agency surface (the AgencyPolicy flat-platform-admin doctrine).
    expect($response->status())->toBe(403);
});

it('401s an unauthenticated members request', function (): void {
    $agency = AgencyFactory::new()->createOne();

    expect($this->getJson("/api/v1/admin/agencies/{$agency->ulid}/members")->status())->toBe(401);
});

// ─── D4: the exact keyset (no secret-adjacent field, ever) ──────────────

it('emits EXACTLY the pinned member keyset — no secret-adjacent field', function (): void {
    $admin = ah087MembersAdmin();
    $agency = AgencyFactory::new()->createOne();
    ah087MemberOf($agency, [
        'two_factor_secret' => 'PLAINTEXTSECRET',
        'two_factor_recovery_codes' => ['code-one', 'code-two'],
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/agencies/{$agency->ulid}/members");

    expect($response->status())->toBe(200);

    $row = $response->json('data.0');
    expect(array_keys($row))->toEqualCanonicalizing(['id', 'type', 'attributes']);
    expect(array_keys($row['attributes']))->toEqualCanonicalizing([
        'user_id',
        'name',
        'email',
        'role',
        'status',
        'joined_at',
        'last_active_at',
        'is_mfa_enrolled',
        'is_mfa_exempt',
        'mfa_exempted_at',
        'mfa_exemption_reason',
    ]);

    // §5.34 — the negative half. The secret and the codes exist on this user
    // (seeded above), so their absence from the payload is a real assertion
    // rather than a vacuous one.
    $raw = $response->getContent();
    expect($raw)->not->toContain('PLAINTEXTSECRET')
        ->and($raw)->not->toContain('code-one')
        ->and($raw)->not->toContain('two_factor_secret')
        ->and($raw)->not->toContain('recovery');
});

// ─── The three MFA states ───────────────────────────────────────────────

it('renders the three MFA states from two independent booleans', function (): void {
    $admin = ah087MembersAdmin();
    $agency = AgencyFactory::new()->createOne();

    $enrolled = ah087MemberOf($agency, ['two_factor_confirmed_at' => now(), 'name' => 'Enrolled']);
    $plain = ah087MemberOf($agency, ['name' => 'Plain']);
    $exempt = ah087MemberOf($agency, [
        'name' => 'Exempt',
        'mfa_exempted_at' => now(),
        'mfa_exemption_reason' => 'Field crew with no smartphone on site.',
    ]);
    $both = ah087MemberOf($agency, [
        'name' => 'Both',
        'two_factor_confirmed_at' => now(),
        'mfa_exempted_at' => now(),
        'mfa_exemption_reason' => 'Exempted after a device loss, kept their authenticator.',
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/agencies/{$agency->ulid}/members?per_page=100");

    $data = $response->json('data');

    expect(ah087AttributesFor($data, $enrolled->ulid)['is_mfa_enrolled'])->toBeTrue()
        ->and(ah087AttributesFor($data, $enrolled->ulid)['is_mfa_exempt'])->toBeFalse();

    expect(ah087AttributesFor($data, $plain->ulid)['is_mfa_enrolled'])->toBeFalse()
        ->and(ah087AttributesFor($data, $plain->ulid)['is_mfa_exempt'])->toBeFalse();

    expect(ah087AttributesFor($data, $exempt->ulid)['is_mfa_enrolled'])->toBeFalse()
        ->and(ah087AttributesFor($data, $exempt->ulid)['is_mfa_exempt'])->toBeTrue()
        ->and(ah087AttributesFor($data, $exempt->ulid)['mfa_exemption_reason'])
        ->toBe('Field crew with no smartphone on site.');

    // Enrolled AND exempt is a real, coherent state — the exemption removes
    // the requirement, not the enrollment. Both booleans stay true and the UI
    // decides which one wins the chip.
    expect(ah087AttributesFor($data, $both->ulid)['is_mfa_enrolled'])->toBeTrue()
        ->and(ah087AttributesFor($data, $both->ulid)['is_mfa_exempt'])->toBeTrue();
});

// ─── Filter / search / pagination (the cloned contract) ─────────────────

it('filters by role', function (): void {
    $admin = ah087MembersAdmin();
    $agency = AgencyFactory::new()->createOne();
    ah087MemberOf($agency, [], AgencyRole::AgencyAdmin);
    ah087MemberOf($agency, [], AgencyRole::AgencyStaff);

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/agencies/{$agency->ulid}/members?role=agency_admin");

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.attributes.role'))->toBe('agency_admin');
});

it('searches case-insensitively across name and email', function (): void {
    $admin = ah087MembersAdmin();
    $agency = AgencyFactory::new()->createOne();
    ah087MemberOf($agency, ['name' => 'Zephyr', 'email' => 'zephyr@example.test']);
    ah087MemberOf($agency, ['name' => 'Other', 'email' => 'other@example.test']);

    $byName = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/agencies/{$agency->ulid}/members?search=zEpH");
    expect($byName->json('meta.total'))->toBe(1);

    $byEmail = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/agencies/{$agency->ulid}/members?search=OTHER@example");
    expect($byEmail->json('meta.total'))->toBe(1);
});

it('paginates with the same meta block as the agency list', function (): void {
    $admin = ah087MembersAdmin();
    $agency = AgencyFactory::new()->createOne();
    for ($i = 0; $i < 3; $i++) {
        ah087MemberOf($agency);
    }

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/agencies/{$agency->ulid}/members?per_page=2&page=2");

    expect($response->json('meta'))->toBe([
        'total' => 3,
        'page' => 2,
        'per_page' => 2,
        'last_page' => 2,
    ]);
    expect($response->json('data'))->toHaveCount(1);
});

it('never leaks another agency members into the list', function (): void {
    $admin = ah087MembersAdmin();
    $agency = AgencyFactory::new()->createOne();
    $other = AgencyFactory::new()->createOne();
    ah087MemberOf($agency);
    ah087MemberOf($other);
    ah087MemberOf($other);

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/agencies/{$agency->ulid}/members");

    expect($response->json('meta.total'))->toBe(1);
});

it('excludes soft-deleted memberships', function (): void {
    $admin = ah087MembersAdmin();
    $agency = AgencyFactory::new()->createOne();
    $user = ah087MemberOf($agency);

    AgencyMembership::query()
        ->where('agency_id', $agency->id)
        ->where('user_id', $user->id)
        ->delete();

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/agencies/{$agency->ulid}/members");

    expect($response->json('meta.total'))->toBe(0);
});
