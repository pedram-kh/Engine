<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Events\AccountLocked;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AccountLockoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

it('temporaryLock + isTemporarilyLocked + clearTemporaryLock round-trip', function (): void {
    $service = app(AccountLockoutService::class);

    expect($service->isTemporarilyLocked('a@example.com'))->toBeFalse();

    $service->temporaryLock('a@example.com');
    expect($service->isTemporarilyLocked('a@example.com'))->toBeTrue();

    $service->clearTemporaryLock('a@example.com');
    expect($service->isTemporarilyLocked('a@example.com'))->toBeFalse();
});

it('temporary lock expires after the short-window minutes elapse', function (): void {
    Carbon::setTestNow('2026-05-08T00:00:00Z');
    $service = app(AccountLockoutService::class);
    $service->temporaryLock('user@example.com');

    Carbon::setTestNow('2026-05-08T00:14:59Z');
    expect($service->isTemporarilyLocked('user@example.com'))->toBeTrue();

    Carbon::setTestNow('2026-05-08T00:15:01Z');
    expect($service->isTemporarilyLocked('user@example.com'))->toBeFalse();
});

it('escalate() suspends the user with the documented reason and audits', function (): void {
    Event::fake([AccountLocked::class]);

    $user = User::factory()->createOne();
    $service = app(AccountLockoutService::class);
    $service->escalate($user);

    $user->refresh();

    expect($user->is_suspended)->toBeTrue()
        ->and($user->suspended_reason)->toBe(AccountLockoutService::ESCALATION_REASON)
        ->and($user->suspended_at)->not->toBeNull();

    $audit = AuditLog::query()->where('action', AuditAction::AuthAccountLockedSuspended->value)->latest('id')->firstOrFail();
    expect($audit->reason)->toBe(AccountLockoutService::ESCALATION_REASON)
        ->and($audit->subject_id)->toBe($user->id);

    Event::assertDispatched(AccountLocked::class, fn (AccountLocked $event): bool => $event->user->is($user));
});

it('escalate() is idempotent — re-running on a suspended user does nothing', function (): void {
    Event::fake([AccountLocked::class]);

    $user = User::factory()->createOne();
    $service = app(AccountLockoutService::class);
    $service->escalate($user);

    $rowsAfterFirst = AuditLog::query()->where('action', AuditAction::AuthAccountLockedSuspended->value)->count();

    $service->escalate($user);

    $rowsAfterSecond = AuditLog::query()->where('action', AuditAction::AuthAccountLockedSuspended->value)->count();

    expect($rowsAfterSecond)->toBe($rowsAfterFirst);
    Event::assertDispatchedTimes(AccountLocked::class, 1);
});
