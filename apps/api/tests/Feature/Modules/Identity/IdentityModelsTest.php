<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Models\AdminProfile;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Enums\ThemePreference;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('User factory produces a valid creator by default', function (): void {
    $user = User::factory()->create();

    expect($user->ulid)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($user->type)->toBe(UserType::Creator)
        ->and($user->theme_preference)->toBe(ThemePreference::System)
        ->and($user->preferred_language)->toBe('en')
        ->and($user->preferred_currency)->toBe('EUR')
        ->and($user->mfa_required)->toBeFalse()
        ->and($user->is_suspended)->toBeFalse()
        ->and($user->email_verified_at)->not->toBeNull();
});

it('User factory writes Argon2id-hashed passwords', function (): void {
    $user = User::factory()->create();

    expect($user->password)->toStartWith('$argon2id$')
        ->and(Hash::check('password-12chars', $user->password))->toBeTrue();
});

it('User factory unverified state nulls email_verified_at', function (): void {
    $user = User::factory()->unverified()->create();

    expect($user->email_verified_at)->toBeNull();
});

it('User factory suspended state sets the suspension fields', function (): void {
    $user = User::factory()->suspended('test reason')->create();

    expect($user->is_suspended)->toBeTrue()
        ->and($user->suspended_reason)->toBe('test reason')
        ->and($user->suspended_at)->not->toBeNull();
});

it('User factory withTwoFactor state populates the 2FA columns', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $user->refresh();

    expect($user->two_factor_secret)->toBe('TESTSECRETXXXXXX')
        ->and($user->two_factor_recovery_codes)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->not->toBeNull()
        ->and($user->hasTwoFactorEnabled())->toBeTrue();
});

it('User factory agencyAdmin state attaches the user to a fresh agency as admin', function (): void {
    $user = User::factory()->agencyAdmin()->create();

    expect($user->type)->toBe(UserType::AgencyUser)
        ->and($user->agencies()->count())->toBe(1);

    $membership = AgencyMembership::query()
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($membership->role)->toBe(AgencyRole::AgencyAdmin)
        ->and($membership->isAccepted())->toBeTrue();
});

it('User factory agencyAdmin state respects an explicit agency', function (): void {
    $agency = Agency::factory()->create();

    $user = User::factory()->agencyAdmin($agency)->create();

    /** @var Agency $attached */
    $attached = $user->agencies()->firstOrFail();
    expect($attached->id)->toBe($agency->id);
});

it('User factory platformAdmin state creates the admin_profile row and forces mfa', function (): void {
    $user = User::factory()->platformAdmin()->create();

    expect($user->type)->toBe(UserType::PlatformAdmin)
        ->and($user->mfa_required)->toBeTrue()
        ->and($user->isPlatformAdmin())->toBeTrue();

    /** @var AdminProfile $profile */
    $profile = AdminProfile::query()->where('user_id', $user->id)->firstOrFail();

    expect($profile->admin_role)->toBe(AdminRole::SuperAdmin);

    $reloaded = $user->refresh();
    /** @var AdminProfile $linked */
    $linked = $reloaded->adminProfile()->firstOrFail();
    expect($linked->id)->toBe($profile->id);
});

it('User factory agencyStaff state attaches the user with the agency_staff role', function (): void {
    $agency = Agency::factory()->create();

    $user = User::factory()->agencyStaff($agency)->create();
    $agency->refresh();

    /** @var AgencyMembership $membership */
    $membership = $agency->memberships()->firstOrFail();

    expect($user->type)->toBe(UserType::AgencyUser)
        ->and($membership->role)->toBe(AgencyRole::AgencyStaff)
        ->and($membership->user_id)->toBe($user->id);
});

it('User->isCreator() returns true for creators and false otherwise', function (): void {
    $creator = User::factory()->create(['type' => UserType::Creator]);
    $admin = User::factory()->platformAdmin()->create();

    expect($creator->isCreator())->toBeTrue()
        ->and($admin->isCreator())->toBeFalse();
});

it('User factory creator() state sets type=creator', function (): void {
    $user = User::factory()->creator()->create();

    expect($user->type)->toBe(UserType::Creator);
});

it('Agency factory catalyst state seeds the pilot tenant correctly', function (): void {
    $catalyst = Agency::factory()->catalyst()->create();

    expect($catalyst->slug)->toBe('catalyst')
        ->and($catalyst->name)->toBe('Catalyst')
        ->and($catalyst->country_code)->toBe('PT')
        ->and($catalyst->default_currency)->toBe('EUR')
        ->and($catalyst->subscription_tier)->toBe('pilot')
        ->and($catalyst->subscription_status)->toBe('active');
});

it('users.type cast resolves to the UserType enum', function (): void {
    $user = User::factory()->create(['type' => UserType::PlatformAdmin]);
    $user->refresh();

    expect($user->type)->toBe(UserType::PlatformAdmin);
});

it('users.theme_preference cast resolves to the ThemePreference enum', function (): void {
    $user = User::factory()->create(['theme_preference' => ThemePreference::Dark]);
    $user->refresh();

    expect($user->theme_preference)->toBe(ThemePreference::Dark);
});

it('users.two_factor_secret is encrypted at rest', function (): void {
    $user = User::factory()->create(['two_factor_secret' => 'plaintext-secret']);

    /** @var stdClass $row */
    $row = DB::table('users')->where('id', $user->id)->firstOrFail();

    /** @var string $rawSecret */
    $rawSecret = $row->two_factor_secret;

    $user->refresh();

    expect($rawSecret)->not->toBe('plaintext-secret')
        ->and($user->two_factor_secret)->toBe('plaintext-secret');
});

it('agency members relation round-trips a user through the AgencyMembership pivot', function (): void {
    $agency = Agency::factory()->create();
    User::factory()->agencyManager($agency)->create();
    $agency->refresh();

    /** @var AgencyMembership $membership */
    $membership = $agency->memberships()->firstOrFail();

    expect($agency->members()->count())->toBe(1)
        ->and($membership->role)->toBe(AgencyRole::AgencyManager);
});

it('agency_users unique constraint rejects duplicate memberships per user/agency', function (): void {
    $agency = Agency::factory()->create();
    $user = User::factory()->create(['type' => UserType::AgencyUser]);

    AgencyMembership::factory()->create([
        'agency_id' => $agency->id,
        'user_id' => $user->id,
        'role' => AgencyRole::AgencyAdmin,
    ]);

    expect(fn () => AgencyMembership::factory()->create([
        'agency_id' => $agency->id,
        'user_id' => $user->id,
        'role' => AgencyRole::AgencyManager,
    ]))->toThrow(QueryException::class);
});

it('User soft-delete preserves the row', function (): void {
    $user = User::factory()->create();

    // user.deleted is a reason-mandatory action (docs/05-SECURITY-COMPLIANCE.md §3.3),
    // enforced at the service layer by AuditLogger. Real admin destructive
    // endpoints get the reason from the X-Action-Reason header via the
    // `action.reason` middleware (docs/04-API-DESIGN.md §26).
    $user->withAuditReason('test cleanup')->delete();

    expect(User::query()->find($user->id))->toBeNull()
        ->and(User::query()->withTrashed()->find($user->id))->not->toBeNull();
});
