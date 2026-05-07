<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Fixtures\Audit\EmptyAllowlistFixture;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('emits user.created on creation with the allowlisted snapshot', function (): void {
    $user = User::factory()->createOne();

    $row = AuditLog::query()
        ->where('action', AuditAction::UserCreated->value)
        ->where('subject_id', $user->id)
        ->firstOrFail();

    expect($row->after)->toBeArray()
        ->and($row->after)->toHaveKey('email', $user->email)
        ->and($row->after)->toHaveKey('name', $user->name)
        ->and($row->before)->toBeNull();
});

it('updating only the password produces NO user.updated audit row and never leaks password fields', function (): void {
    $user = User::factory()->createOne();

    AuditLog::query()->delete();
    // Re-insert via raw query is the only way for tests to clear the table
    // (the model itself rejects deletes).
    DB::table('audit_logs')->truncate();

    $user->password = Hash::make('A-NewPassword-w!th-Length-12');
    $user->save();

    $rows = AuditLog::query()->get();

    expect($rows)->toHaveCount(0, 'password-only update must not emit a user.updated row.');

    $serialised = json_encode($rows->toArray());
    expect($serialised)->not->toContain('password')
        ->and($serialised)->not->toContain('two_factor_secret')
        ->and($serialised)->not->toContain('two_factor_recovery_codes')
        ->and($serialised)->not->toContain('two_factor_confirmed_at')
        ->and($serialised)->not->toContain('remember_token');
});

it('updating an allowlisted attribute emits user.updated with delta only and clean of secrets', function (): void {
    $user = User::factory()->createOne(['name' => 'Old Name']);
    DB::table('audit_logs')->truncate();

    $user->name = 'New Name';
    // Also touch an excluded attribute to prove it never leaks into snapshots.
    $user->password = Hash::make('Another-NewPassword-12!');
    $user->save();

    $row = AuditLog::query()
        ->where('action', AuditAction::UserUpdated->value)
        ->where('subject_id', $user->id)
        ->firstOrFail();

    expect($row->before)->toBe(['name' => 'Old Name'])
        ->and($row->after)->toBe(['name' => 'New Name']);

    $serialised = json_encode($row->getAttributes());
    expect($serialised)->not->toContain('password')
        ->and($serialised)->not->toContain('two_factor_secret')
        ->and($serialised)->not->toContain('two_factor_recovery_codes')
        ->and($serialised)->not->toContain('two_factor_confirmed_at')
        ->and($serialised)->not->toContain('remember_token');
});

it('emits user.deleted with the allowlisted before-snapshot when soft-deleted with a reason', function (): void {
    $user = User::factory()->createOne();
    DB::table('audit_logs')->truncate();

    $user->withAuditReason('GDPR erasure request #42')->delete();

    $row = AuditLog::query()
        ->where('action', AuditAction::UserDeleted->value)
        ->where('subject_id', $user->id)
        ->firstOrFail();

    expect($row->reason)->toBe('GDPR erasure request #42')
        ->and($row->before)->toBeArray()
        ->and($row->before)->toHaveKey('email', $user->email);

    $serialised = json_encode($row->getAttributes());
    expect($serialised)->not->toContain('password')
        ->and($serialised)->not->toContain('two_factor_secret')
        ->and($serialised)->not->toContain('two_factor_recovery_codes')
        ->and($serialised)->not->toContain('two_factor_confirmed_at')
        ->and($serialised)->not->toContain('remember_token');
});

it('User::auditableAllowlist() excludes every sensitive Phase 1 column', function (): void {
    $allowlist = (new User)->auditableAllowlist();

    foreach ([
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'remember_token',
    ] as $sensitive) {
        expect($allowlist)->not->toContain($sensitive, "{$sensitive} must NEVER be auditable.");
    }
});

it('withAuditReason() trims, treats blank as null, and is consumed once', function (): void {
    $user = User::factory()->createOne();

    $user->withAuditReason('   spaces matter   ');
    expect($user->consumePendingAuditReason())->toBe('spaces matter');
    expect($user->consumePendingAuditReason())->toBeNull();

    $user->withAuditReason('   ');
    expect($user->consumePendingAuditReason())->toBeNull();

    $user->withAuditReason(null);
    expect($user->consumePendingAuditReason())->toBeNull();
});

it('auditAction() returns the conventional <classname>.<event> enum case', function (): void {
    $user = new User;

    expect($user->auditAction('created'))->toBe(AuditAction::UserCreated)
        ->and($user->auditAction('updated'))->toBe(AuditAction::UserUpdated)
        ->and($user->auditAction('deleted'))->toBe(AuditAction::UserDeleted);
});

it('auditableSnapshot() filters strictly through the allowlist', function (): void {
    $user = new User;
    $snapshot = $user->auditableSnapshot([
        'name' => 'Alice',
        'password' => 'should-never-appear',
        'two_factor_secret' => 'should-never-appear',
        'remember_token' => 'should-never-appear',
        'email' => 'a@example.com',
    ]);

    expect($snapshot)->toBe([
        'name' => 'Alice',
        'email' => 'a@example.com',
    ]);
});

it('a model with an empty allowlist exposes nothing in audit snapshots', function (): void {
    // Fixture model lives under tests/Fixtures/Audit/ and proves the
    // contract holds for any consumer, not just User.
    $fixture = new EmptyAllowlistFixture;

    expect($fixture->auditableAllowlist())->toBe([])
        ->and($fixture->auditableSnapshot(['name' => 'whatever', 'password' => 'leaked?']))
        ->toBe([]);
});
