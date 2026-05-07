<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Exceptions\AuditLogImmutableException;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('AuditLog::update() throws AuditLogImmutableException', function (): void {
    $log = AuditLog::factory()->create();

    try {
        $log->update(['reason' => 'tampering attempt']);
        expect(false)->toBeTrue('update() should have thrown.');
    } catch (AuditLogImmutableException $e) {
        expect($e->getMessage())->toContain('append-only');
    }
});

it('AuditLog::delete() throws AuditLogImmutableException', function (): void {
    $log = AuditLog::factory()->create();

    try {
        $log->delete();
        expect(false)->toBeTrue('delete() should have thrown.');
    } catch (AuditLogImmutableException $e) {
        expect($e->getMessage())->toContain('append-only');
    }

    expect(AuditLog::query()->whereKey($log->id)->exists())->toBeTrue();
});

it('AuditLog::save() on an existing row throws', function (): void {
    $log = AuditLog::factory()->create();
    $log->reason = 'tampering attempt';

    try {
        $log->save();
        expect(false)->toBeTrue('save() on existing row should have thrown.');
    } catch (AuditLogImmutableException $e) {
        expect($e->getMessage())->toContain('append-only');
    }
});

it('AuditLog::save() on a new row inserts normally', function (): void {
    $log = AuditLog::factory()->make();

    expect($log->save())->toBeTrue();
    expect($log->exists)->toBeTrue();
    expect(AuditLog::query()->whereKey($log->id)->exists())->toBeTrue();
});

it('AuditLog auto-populates ulid via HasUlid', function (): void {
    $log = AuditLog::query()->create([
        'actor_type' => 'system',
        'action' => AuditAction::AuthLoginSucceeded,
    ]);

    expect($log->ulid)->toBeString()->and(strlen($log->ulid))->toBe(26);
});

it('AuditLog casts action to AuditAction enum and metadata/before/after to arrays', function (): void {
    $log = AuditLog::factory()->create([
        'action' => AuditAction::AuthAccountUnlocked,
        'metadata' => ['ticket' => '#123'],
        'before' => ['is_suspended' => true],
        'after' => ['is_suspended' => false],
        'reason' => 'fraud review cleared',
    ]);

    $reloaded = AuditLog::query()->whereKey($log->id)->firstOrFail();

    expect($reloaded->action)->toBe(AuditAction::AuthAccountUnlocked)
        ->and($reloaded->metadata)->toBe(['ticket' => '#123'])
        ->and($reloaded->before)->toBe(['is_suspended' => true])
        ->and($reloaded->after)->toBe(['is_suspended' => false]);
});

it('AuditLog actor() relation resolves to the User row when actor_id is set', function (): void {
    $user = User::factory()->createOne();

    $log = AuditLog::factory()->create([
        'actor_type' => 'user',
        'actor_id' => $user->id,
    ]);

    expect($log->actor)->not->toBeNull()
        ->and($log->actor?->id)->toBe($user->id);
});

it('AuditLog subject() polymorphic relation resolves to the underlying model', function (): void {
    $user = User::factory()->createOne();

    $log = AuditLog::factory()->create([
        'subject_type' => $user->getMorphClass(),
        'subject_id' => $user->id,
        'subject_ulid' => $user->ulid,
    ]);

    expect($log->subject)->not->toBeNull()
        ->and($log->subject?->getKey())->toBe($user->id);
});

it('AuditLogImmutableException factories produce distinct messages', function (): void {
    expect(AuditLogImmutableException::forUpdate()->getMessage())->toContain('cannot be updated');
    expect(AuditLogImmutableException::forDelete()->getMessage())->toContain('cannot be deleted');
});
