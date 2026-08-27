<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
||--------------------------------------------------------------------------
|| AH-087 — users.mfa_exempted_at + users.mfa_exemption_reason
||--------------------------------------------------------------------------
||
|| The schema half of review priority #1. The pair is additive-nullable, and
|| NULL is what makes the exemption branch structurally inert: every row that
|| existed before the migration reads as "not exempt" with no backfill and no
|| data write at deploy.
||
*/

it('adds both exemption columns to users', function (): void {
    foreach (['mfa_exempted_at', 'mfa_exemption_reason'] as $column) {
        expect(Schema::hasColumn('users', $column))
            ->toBeTrue("users.{$column} should exist");
    }
});

it('leaves both columns NULL for a user created without them (no default, no backfill)', function (): void {
    $user = User::factory()->create(['type' => UserType::AgencyUser]);

    // The NULL check happens in SQL rather than in PHP: a cast or an accessor
    // could present a non-NULL storage default as null, which is exactly the
    // thing this test exists to rule out.
    $storedAsNull = DB::table('users')
        ->where('id', $user->id)
        ->whereNull('mfa_exempted_at')
        ->whereNull('mfa_exemption_reason')
        ->exists();

    expect($storedAsNull)->toBeTrue();
});

it('keeps mfa_required dormant and independent of the exemption pair', function (): void {
    // R1: mfa_required was deliberately NOT reused. It stays with its
    // documented meaning, and its default (false for agency users) is exactly
    // why branching enforcement on it would have exempted the whole live
    // population on deploy.
    $user = User::factory()->create(['type' => UserType::AgencyUser]);

    expect($user->mfa_required)->toBeFalse()
        ->and($user->isMfaExempt())->toBeFalse();

    $user->forceFill(['mfa_exempted_at' => now(), 'mfa_exemption_reason' => 'Reason.'])->saveQuietly();

    expect($user->refresh()->isMfaExempt())->toBeTrue()
        ->and($user->mfa_required)->toBeFalse();
});

it('persists a granted exemption and reads it back through the model helper', function (): void {
    $user = User::factory()->create([
        'type' => UserType::AgencyUser,
        'mfa_exempted_at' => now(),
        'mfa_exemption_reason' => 'Shared-terminal worker with no personal device.',
    ]);

    $user->refresh();

    expect($user->isMfaExempt())->toBeTrue()
        ->and($user->mfa_exempted_at)->not->toBeNull()
        ->and($user->mfa_exemption_reason)->toBe('Shared-terminal worker with no personal device.');
});

it('keeps the free-text exemption reason out of the audit allowlist', function (): void {
    $allowlist = (new User)->auditableAllowlist();

    // The redaction discipline: the state IS auditable, the operator's prose
    // about a named person is not — it reaches the trail through the audit
    // row's own `reason` field instead.
    expect($allowlist)->toContain('mfa_exempted_at')
        ->and($allowlist)->not->toContain('mfa_exemption_reason');
});
