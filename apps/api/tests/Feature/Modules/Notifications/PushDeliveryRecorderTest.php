<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\PushDelivery;
use App\Modules\Notifications\Models\UserPushToken;
use App\Modules\Notifications\Push\PushDeliveryOutcome;
use App\Modules\Notifications\Push\PushDeliveryRecorder;
use App\Modules\Notifications\Push\PushOutcome;
use App\Modules\Notifications\Push\PushPlatform;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-102b C1.3 — PushDeliveryRecorder: the contained write path
|--------------------------------------------------------------------------
|
| The recorder runs AFTER the push was handed to FCM, inside the send job. If
| it threw, the job would retry and the user would get a DUPLICATE push. So
| the requirement under test is containment: each step (invalidate, insert,
| log) is independent and none of them can escape record().
|
| The job-level pins (one send(), no release, no retry) live in
| SendPushNotificationJobTest; this file drives the recorder directly.
*/

function pdrRecorder(): PushDeliveryRecorder
{
    return app(PushDeliveryRecorder::class);
}

function pdrOutcome(UserPushToken $token, PushOutcome $outcome = PushOutcome::Delivered, int $attempt = 1, bool $final = false, ?string $code = null, ?string $detail = null, ?int $httpStatus = null, ?string $ulid = null): PushDeliveryOutcome
{
    return new PushDeliveryOutcome(
        tokenId: $token->id,
        userId: $token->user_id,
        platform: $token->platform,
        notificationType: 'assignment.draft_approved',
        outcome: $outcome,
        attempt: $attempt,
        code: $code,
        detail: $detail,
        httpStatus: $httpStatus,
        final: $final,
        notificationUlid: $ulid,
    );
}

final class PdrLogSink
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];
}

function pdrCaptureLogs(): PdrLogSink
{
    $sink = new PdrLogSink;
    Log::listen(function ($event) use ($sink): void {
        $sink->records[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
    });

    return $sink;
}

afterEach(function (): void {
    // The invalidate-failure tests hang a throwing listener on the model; never leak it.
    UserPushToken::flushEventListeners();
    PushDelivery::flushEventListeners();
});

// =============================================================================
// One row per outcome
// =============================================================================

it('writes one row per PushOutcome case with the outcome\'s own columns (enum is fully handled)', function (): void {
    $cases = [
        [PushOutcome::Delivered, null, null, 200],
        [PushOutcome::TokenInvalid, 'UNREGISTERED', 'gone', 404],
        [PushOutcome::Retryable, 'UNAVAILABLE', 'busy', 503],
        [PushOutcome::ConfigError, 'fcm_config', 'not configured', null],
        [PushOutcome::Rejected, 'INVALID_ARGUMENT', 'bad', 400],
    ];

    expect(array_map(fn (array $c): string => $c[0]->value, $cases))
        ->toBe(array_map(fn (PushOutcome $o): string => $o->value, PushOutcome::cases()));

    foreach ($cases as [$outcome, $code, $detail, $status]) {
        $token = UserPushToken::factory()->create();
        pdrRecorder()->record(pdrOutcome($token, $outcome, code: $code, detail: $detail, httpStatus: $status, ulid: '01JABCDEFGHJKMNPQRSTVWXYZ0'));

        $row = PushDelivery::query()->where('user_push_token_id', $token->id)->sole();

        expect($row->outcome)->toBe($outcome)
            ->and($row->user_id)->toBe($token->user_id)
            ->and($row->platform)->toBe(PushPlatform::Android)
            ->and($row->notification_type)->toBe('assignment.draft_approved')
            ->and($row->notification_ulid)->toBe('01JABCDEFGHJKMNPQRSTVWXYZ0')
            ->and($row->attempt)->toBe(1)
            ->and($row->final)->toBeFalse()
            ->and($row->code)->toBe($code)
            ->and($row->http_status)->toBe($status)
            ->and($row->detail)->toBe($detail)
            ->and($row->created_at)->not->toBeNull();
    }

    expect(PushDelivery::query()->count())->toBe(5);
});

it('a three-attempt retry sequence writes three rows, attempt 1/2/3, with final only on the last', function (): void {
    $token = UserPushToken::factory()->create();

    pdrRecorder()->record(pdrOutcome($token, PushOutcome::Retryable, 1, false, 'UNAVAILABLE'));
    pdrRecorder()->record(pdrOutcome($token, PushOutcome::Retryable, 2, false, 'UNAVAILABLE'));
    pdrRecorder()->record(pdrOutcome($token, PushOutcome::Retryable, 3, true, 'UNAVAILABLE'));

    $rows = PushDelivery::query()->orderBy('id')->get();

    expect($rows->pluck('attempt')->all())->toBe([1, 2, 3])
        ->and($rows->pluck('final')->all())->toBe([false, false, true])
        ->and($rows->pluck('outcome')->unique()->all())->toBe([PushOutcome::Retryable]);
});

it('detail is never written for Delivered, and is capped at 200 chars (code at 64, ULID at 26) otherwise', function (): void {
    $token = UserPushToken::factory()->create();

    pdrRecorder()->record(pdrOutcome($token, PushOutcome::Delivered, detail: 'must not be stored'));
    pdrRecorder()->record(pdrOutcome($token, PushOutcome::Rejected, 2, code: str_repeat('C', 100), detail: str_repeat('é', 500), ulid: str_repeat('U', 40)));

    $delivered = PushDelivery::query()->where('outcome', 'delivered')->sole();
    $rejected = PushDelivery::query()->where('outcome', 'rejected')->sole();

    expect($delivered->detail)->toBeNull()
        ->and(mb_strlen((string) $rejected->detail))->toBe(200)
        ->and(mb_strlen((string) $rejected->code))->toBe(64)
        ->and(strlen((string) $rejected->notification_ulid))->toBe(26);
});

it('PRIVACY: neither the stored rows nor any log line contain the device token or message text', function (): void {
    $token = UserPushToken::factory()->create(['token' => 'DEVICE-TOKEN-VALUE-abc123']);
    $logs = pdrCaptureLogs();

    foreach (PushOutcome::cases() as $i => $outcome) {
        pdrRecorder()->record(pdrOutcome($token, $outcome, $i + 1, code: 'X', detail: 'provider detail', httpStatus: 400));
    }

    $dump = (string) json_encode([PushDelivery::query()->get()->toArray(), $logs->records], JSON_UNESCAPED_UNICODE);

    expect($dump)->toContain('push.')
        ->and(str_contains($dump, 'DEVICE-TOKEN-VALUE-abc123'))->toBeFalse()
        ->and(str_contains($dump, 'Your draft was approved'))->toBeFalse();
});

// =============================================================================
// Containment — the write path must never fail the job
// =============================================================================

it('CONTAINMENT: a failing insert (table gone — the stand-in for a missing GRANT) does not escape record(); the failure is logged with identifiers only', function (): void {
    $token = UserPushToken::factory()->create(['token' => 'DEVICE-TOKEN-VALUE-abc123']);
    Schema::drop('push_deliveries');
    $logs = pdrCaptureLogs();

    pdrRecorder()->record(pdrOutcome($token, PushOutcome::Delivered, detail: 'x'));

    $failed = array_values(array_filter($logs->records, fn (array $r): bool => $r['message'] === 'push.delivery_record_failed'));

    expect($failed)->toHaveCount(1)
        ->and($failed[0]['level'])->toBe('error')
        ->and($failed[0]['context'])->toBe([
            'step' => 'insert',
            'token_id' => $token->id,
            'user_id' => $token->user_id,
            'outcome' => 'delivered',
            'exception' => QueryException::class,
        ])
        ->and(str_contains((string) json_encode($logs->records), 'DEVICE-TOKEN-VALUE-abc123'))->toBeFalse();

    // The log step is independent and still ran.
    expect(array_column($logs->records, 'message'))->toContain('push.delivery');
});

it('CONTAINMENT: a failing insert still invalidates a TokenInvalid token (invalidate runs first and independently)', function (): void {
    $token = UserPushToken::factory()->create();
    Schema::drop('push_deliveries');

    pdrRecorder()->record(pdrOutcome($token, PushOutcome::TokenInvalid, code: 'UNREGISTERED'));

    $fresh = $token->fresh();
    expect($fresh?->invalidated_at)->not->toBeNull()
        ->and($fresh?->invalidation_reason)->toBe('provider_unregistered');
});

it('CONTAINMENT: a failing invalidate still writes the delivery row and the log line', function (): void {
    $token = UserPushToken::factory()->create();
    UserPushToken::saving(function (): void {
        throw new RuntimeException('simulated invalidate failure');
    });
    $logs = pdrCaptureLogs();

    pdrRecorder()->record(pdrOutcome($token, PushOutcome::TokenInvalid, code: 'UNREGISTERED'));

    $messages = array_column($logs->records, 'message');
    $failed = array_values(array_filter($logs->records, fn (array $r): bool => $r['message'] === 'push.delivery_record_failed'));

    expect($token->fresh()?->invalidated_at)->toBeNull('the simulated failure really blocked the invalidation')
        ->and(PushDelivery::query()->where('user_push_token_id', $token->id)->sole()->outcome)->toBe(PushOutcome::TokenInvalid)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]['context']['step'])->toBe('invalidate')
        ->and($failed[0]['context']['exception'])->toBe(RuntimeException::class)
        ->and($messages)->toContain('push.token_invalidated');
});

it('ORDER: the token is already invalidated when the delivery row is inserted (invalidate first, then insert)', function (): void {
    $token = UserPushToken::factory()->create();
    $invalidatedAtInsertTime = null;
    PushDelivery::creating(function () use ($token, &$invalidatedAtInsertTime): void {
        $invalidatedAtInsertTime = UserPushToken::query()->find($token->id)?->invalidated_at;
    });

    pdrRecorder()->record(pdrOutcome($token, PushOutcome::TokenInvalid, code: 'UNREGISTERED'));

    expect($invalidatedAtInsertTime)->not->toBeNull();
});

it('CONTAINMENT: a throwing logger does not escape record() — the token is still invalidated and the row still written', function (): void {
    $token = UserPushToken::factory()->create();
    Log::listen(function (): void {
        throw new RuntimeException('logger down');
    });

    pdrRecorder()->record(pdrOutcome($token, PushOutcome::TokenInvalid, code: 'UNREGISTERED'));

    expect($token->fresh()?->invalidated_at)->not->toBeNull()
        ->and(PushDelivery::query()->where('user_push_token_id', $token->id)->count())->toBe(1);
});

it('CONTAINMENT: every step failing at once still does not throw', function (): void {
    $token = UserPushToken::factory()->create();
    Schema::drop('push_deliveries');
    UserPushToken::saving(function (): void {
        throw new RuntimeException('simulated invalidate failure');
    });
    Log::listen(function (): void {
        throw new RuntimeException('logger down');
    });

    expect(fn () => pdrRecorder()->record(pdrOutcome($token, PushOutcome::TokenInvalid, code: 'UNREGISTERED')))->not->toThrow(Throwable::class);
});
