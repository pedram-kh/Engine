<?php

declare(strict_types=1);

use App\Modules\Notifications\Jobs\SendPushNotificationJob;
use App\Modules\Notifications\Models\UserPushToken;
use App\Modules\Notifications\Push\PushMessage;
use App\Modules\Notifications\Push\PushOutcome;
use App\Modules\Notifications\Push\PushPlatform;
use App\Modules\Notifications\Push\PushSender;
use App\Modules\Notifications\Push\PushSendResult;
use App\Modules\Notifications\Push\PushTarget;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-102 C2.3 — SendPushNotificationJob + PushDeliveryRecorder
|--------------------------------------------------------------------------
|
| The sender is a scripted fake: this file proves the JOB's behaviour per
| outcome (what it records, whether it retries, whether it kills the token),
| the run-time liveness re-check, the after-commit guarantee, and that the
| recorder never writes the device token or message text anywhere.
*/

/** A PushSender that returns scripted results and remembers what it was asked to send. */
final class PushScriptedSender implements PushSender
{
    /** @var list<array{message: PushMessage, target: PushTarget, validateOnly: bool}> */
    public array $sent = [];

    /** @param  list<PushSendResult>  $script  consumed in order; the last repeats */
    public function __construct(private array $script) {}

    public function send(PushMessage $message, PushTarget $target, bool $validateOnly = false): PushSendResult
    {
        $this->sent[] = ['message' => $message, 'target' => $target, 'validateOnly' => $validateOnly];

        return count($this->script) > 1 ? array_shift($this->script) : $this->script[0];
    }
}

/** A FakeJob that is a real queue Job contract and reports a chosen attempt number. */
final class PushAttemptJob extends FakeJob implements JobContract
{
    public function __construct(int $attempts = 1)
    {
        $this->attempts = $attempts;
    }
}

/** @param  list<PushSendResult>  $script */
function pjSender(array $script): PushScriptedSender
{
    $sender = new PushScriptedSender($script);
    app()->instance(PushSender::class, $sender);

    return $sender;
}

function pjMessage(): PushMessage
{
    return new PushMessage(title: 'Draft approved', body: 'Your draft was approved: Summer Launch', data: ['type' => 'assignment.draft_approved', 'v' => 1]);
}

function pjJob(UserPushToken $token, int $attempt = 1): SendPushNotificationJob
{
    $job = new SendPushNotificationJob($token->id, pjMessage(), 'assignment.draft_approved');
    $job->setJob(new PushAttemptJob($attempt));

    return $job;
}

function pjReleased(SendPushNotificationJob $job): bool
{
    return $job->job instanceof PushAttemptJob && $job->job->isReleased();
}

function pjReleaseDelay(SendPushNotificationJob $job): mixed
{
    return $job->job instanceof PushAttemptJob ? $job->job->releaseDelay : null;
}

function pjRun(SendPushNotificationJob $job): void
{
    app()->call([$job, 'handle']);
}

final class PushLogSink
{
    /** @var list<array<string, mixed>> */
    public array $records = [];
}

function pjCaptureLogs(): PushLogSink
{
    $sink = new PushLogSink;
    Log::listen(function ($event) use ($sink): void {
        $sink->records[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
    });

    return $sink;
}

/**
 * @param  list<array<string, mixed>>  $records
 * @return array<string, mixed>
 */
function pjLog(array $records, string $message): array
{
    foreach ($records as $record) {
        if (($record['message'] ?? null) === $message) {
            return $record;
        }
    }

    throw new RuntimeException("no log record '{$message}'");
}

function pjLiveToken(): UserPushToken
{
    return UserPushToken::factory()->create(['token' => 'DEVICE-TOKEN-VALUE-abc123']);
}

// =============================================================================
// Shape
// =============================================================================

it('is a queued job on the DEFAULT queue with after-commit, 3 tries, a 20s timeout and a 30s/120s backoff', function (): void {
    $job = new SendPushNotificationJob(1, pjMessage(), 'assignment.invited');

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->afterCommit)->toBeTrue()
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(20)
        ->and($job->backoff())->toBe([30, 120])
        ->and($job->queue)->toBeNull('no named queue: the production worker only consumes default')
        ->and($job->connection)->toBeNull();
});

it('serialises without the device token (it carries a token ID and the neutral message only)', function (): void {
    $token = pjLiveToken();
    $payload = serialize(new SendPushNotificationJob($token->id, pjMessage(), 'assignment.draft_approved'));

    expect($payload)->not->toContain('DEVICE-TOKEN-VALUE-abc123');
});

// =============================================================================
// Outcomes
// =============================================================================

it('Delivered: sends once to the token\'s value and platform, records, does not retry, keeps the token live', function (): void {
    $token = pjLiveToken();
    $sender = pjSender([PushSendResult::delivered()]);
    $logs = pjCaptureLogs();

    $job = pjJob($token);
    pjRun($job);

    expect($sender->sent)->toHaveCount(1)
        ->and($sender->sent[0]['target']->token)->toBe('DEVICE-TOKEN-VALUE-abc123')
        ->and($sender->sent[0]['target']->platform)->toBe(PushPlatform::Android)
        ->and($sender->sent[0]['message']->title)->toBe('Draft approved')
        ->and($sender->sent[0]['validateOnly'])->toBeFalse()
        ->and(pjReleased($job))->toBeFalse()
        ->and($token->fresh()?->invalidated_at)->toBeNull();

    $delivered = pjLog($logs->records, 'push.delivery');
    expect($delivered['level'])->toBe('info')
        ->and($delivered['context'])->toMatchArray([
            'token_id' => $token->id,
            'user_id' => $token->user_id,
            'platform' => 'android',
            'notification_type' => 'assignment.draft_approved',
            'outcome' => 'delivered',
            'attempt' => 1,
        ]);
});

it('TokenInvalid: invalidates the token (kept for audit, with a reason), does NOT retry, and the token leaves the live set', function (): void {
    $token = pjLiveToken();
    $sender = pjSender([PushSendResult::tokenInvalid('UNREGISTERED', 'gone', 404)]);

    $job = pjJob($token);
    pjRun($job);

    $fresh = $token->fresh();
    expect($sender->sent)->toHaveCount(1)
        ->and(pjReleased($job))->toBeFalse()
        ->and($fresh?->invalidated_at)->not->toBeNull()
        ->and($fresh?->invalidation_reason)->toBe('provider_unregistered');

    // A second job for the same token is now a no-op.
    pjRun(pjJob($token));
    expect($sender->sent)->toHaveCount(1);
});

it('Retryable on attempt 1 and 2: released with the 30s then 120s backoff; the token is untouched', function (int $attempt, int $delay): void {
    $token = pjLiveToken();
    pjSender([PushSendResult::retryable('UNAVAILABLE', 'busy', 503)]);

    $job = pjJob($token, $attempt);
    pjRun($job);

    expect(pjReleased($job))->toBeTrue()
        ->and(pjReleaseDelay($job))->toBe($delay)
        ->and($token->fresh()?->invalidated_at)->toBeNull();
})->with([
    'attempt 1 → 30s' => [1, 30],
    'attempt 2 → 120s' => [2, 120],
]);

it('Retryable on the LAST attempt: not released again, recorded as final, token untouched', function (): void {
    $token = pjLiveToken();
    pjSender([PushSendResult::retryable('UNAVAILABLE', 'busy', 503)]);
    $logs = pjCaptureLogs();

    $job = pjJob($token, 3);
    pjRun($job);

    expect(pjReleased($job))->toBeFalse()
        ->and($token->fresh()?->invalidated_at)->toBeNull();

    $final = pjLog($logs->records, 'push.delivery_retryable');
    expect($final['context']['final'])->toBeTrue()->and($final['context']['attempt'])->toBe(3);
});

it('ConfigError: logged at ERROR, no retry, the token is NEVER invalidated (the whole channel is down, not this device)', function (): void {
    $token = pjLiveToken();
    pjSender([PushSendResult::configError('fcm_config', 'FCM service account is not configured')]);
    $logs = pjCaptureLogs();

    $job = pjJob($token);
    pjRun($job);

    expect(pjReleased($job))->toBeFalse()
        ->and($token->fresh()?->invalidated_at)->toBeNull();

    $record = pjLog($logs->records, 'push.config_error');
    expect($record['level'])->toBe('error')->and($record['context']['code'])->toBe('fcm_config');
});

it('Rejected: logged at ERROR, no retry, token untouched', function (): void {
    $token = pjLiveToken();
    pjSender([PushSendResult::rejected('INVALID_ARGUMENT', 'bad data', 400)]);
    $logs = pjCaptureLogs();

    $job = pjJob($token);
    pjRun($job);

    expect(pjReleased($job))->toBeFalse()
        ->and($token->fresh()?->invalidated_at)->toBeNull();

    expect(pjLog($logs->records, 'push.delivery_rejected')['level'])->toBe('error');
});

// =============================================================================
// Run-time liveness re-check — the sender is NEVER called for a dead token
// =============================================================================

it('skips (no send, no log) when the token is gone, invalidated, its bearer revoked/expired, or its user soft-deleted', function (string $case): void {
    config(['sanctum.expiration' => 60]);
    $token = pjLiveToken();
    $sender = pjSender([PushSendResult::delivered()]);

    match ($case) {
        'deleted row' => $token->delete(),
        'invalidated' => $token->forceFill(['invalidated_at' => now()])->save(),
        'bearer revoked' => PersonalAccessToken::query()->whereKey($token->personal_access_token_id)->delete(),
        'bearer expired by age' => PersonalAccessToken::query()->whereKey($token->personal_access_token_id)->update(['created_at' => now()->subMinutes(61)]),
        'bearer expires_at past' => PersonalAccessToken::query()->whereKey($token->personal_access_token_id)->update(['expires_at' => now()->subMinute()]),
        'user soft-deleted' => DB::table('users')->where('id', $token->user_id)->update(['deleted_at' => now()]),
        default => throw new InvalidArgumentException($case),
    };

    pjRun(new SendPushNotificationJob($token->id, pjMessage(), 'assignment.draft_approved'));

    expect($sender->sent)->toBe([]);
})->with(['deleted row', 'invalidated', 'bearer revoked', 'bearer expired by age', 'bearer expires_at past', 'user soft-deleted']);

it('the live control: the same token with nothing wrong IS sent', function (): void {
    $token = pjLiveToken();
    $sender = pjSender([PushSendResult::delivered()]);

    pjRun(new SendPushNotificationJob($token->id, pjMessage(), 'assignment.draft_approved'));

    expect($sender->sent)->toHaveCount(1);
});

it('a token id that never existed is a no-op', function (): void {
    $sender = pjSender([PushSendResult::delivered()]);

    pjRun(new SendPushNotificationJob(999999, pjMessage(), 'assignment.draft_approved'));

    expect($sender->sent)->toBe([]);
});

// =============================================================================
// After-commit (BR-8 / BR-9 target)
// =============================================================================

it('AFTER COMMIT: a dispatch inside a transaction that ROLLS BACK never runs', function (): void {
    $token = pjLiveToken();
    $sender = pjSender([PushSendResult::delivered()]);

    try {
        DB::transaction(function () use ($token, $sender): void {
            SendPushNotificationJob::dispatch($token->id, pjMessage(), 'assignment.draft_approved');

            expect($sender->sent)->toBe([], 'must not run inside the open transaction');

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($sender->sent)->toBe([]);
});

it('AFTER COMMIT: a dispatch inside a transaction runs only once it COMMITS (positive control)', function (): void {
    $token = pjLiveToken();
    $sender = pjSender([PushSendResult::delivered()]);

    DB::transaction(function () use ($token, $sender): void {
        SendPushNotificationJob::dispatch($token->id, pjMessage(), 'assignment.draft_approved');

        expect($sender->sent)->toBe([], 'must not run inside the open transaction');
    });

    expect($sender->sent)->toHaveCount(1);
});

// =============================================================================
// Recorder privacy
// =============================================================================

it('PRIVACY: no log line from any outcome contains the device token or any message text', function (PushSendResult $result): void {
    $token = pjLiveToken();
    pjSender([$result]);
    $logs = pjCaptureLogs();

    pjRun(pjJob($token, 3));

    $dump = (string) json_encode($logs->records, JSON_UNESCAPED_UNICODE);

    expect($dump)->toContain('push.')
        ->and(str_contains($dump, 'DEVICE-TOKEN-VALUE-abc123'))->toBeFalse()
        ->and(str_contains($dump, 'Your draft was approved'))->toBeFalse()
        ->and(str_contains($dump, 'Summer Launch'))->toBeFalse();
})->with([
    'delivered' => [fn () => PushSendResult::delivered()],
    'token invalid' => [fn () => PushSendResult::tokenInvalid('UNREGISTERED', 'gone', 404)],
    'retryable' => [fn () => PushSendResult::retryable('UNAVAILABLE', 'busy', 503)],
    'config error' => [fn () => PushSendResult::configError('fcm_config', 'not configured')],
    'rejected' => [fn () => PushSendResult::rejected('INVALID_ARGUMENT', 'bad', 400)],
]);

it('failed(): an unexpected exception is logged without the device token and does not throw', function (): void {
    $logs = pjCaptureLogs();
    $job = new SendPushNotificationJob(5, pjMessage(), 'assignment.draft_approved');

    $job->failed(new RuntimeException('boom'));

    $record = pjLog($logs->records, 'push.job_failed');
    expect($record['level'])->toBe('error')
        ->and($record['context']['token_id'])->toBe(5)
        ->and($record['context']['message'])->toBe('boom');
});

it('the outcome enum is fully handled: every PushOutcome case has a recorder log line', function (): void {
    $token = pjLiveToken();
    $logs = pjCaptureLogs();
    $results = [
        PushOutcome::Delivered->value => PushSendResult::delivered(),
        PushOutcome::TokenInvalid->value => PushSendResult::tokenInvalid('UNREGISTERED'),
        PushOutcome::Retryable->value => PushSendResult::retryable('UNAVAILABLE'),
        PushOutcome::ConfigError->value => PushSendResult::configError('fcm_config'),
        PushOutcome::Rejected->value => PushSendResult::rejected('INVALID_ARGUMENT'),
    ];

    expect(array_keys($results))->toBe(array_map(fn (PushOutcome $o): string => $o->value, PushOutcome::cases()));

    foreach ($results as $result) {
        $fresh = UserPushToken::factory()->create();
        pjSender([$result]);
        pjRun(pjJob($fresh));
    }

    $outcomes = array_values(array_filter(array_map(fn (array $r): mixed => $r['context']['outcome'] ?? null, $logs->records)));
    sort($outcomes);
    $expected = array_map(fn (PushOutcome $o): string => $o->value, PushOutcome::cases());
    sort($expected);

    expect($outcomes)->toBe($expected)->and($token->exists)->toBeTrue();
});

// =============================================================================
// AH-102b C1.2 — the notification ULID reaches the recorder
// =============================================================================

it('AH-102b: the in-app notification ULID in the message data reaches the recorder (visible in its log context)', function (): void {
    $token = pjLiveToken();
    pjSender([PushSendResult::delivered()]);
    $logs = pjCaptureLogs();
    $ulid = '01JABCDEFGHJKMNPQRSTVWXYZ0';
    $message = new PushMessage(title: 't', body: 'b', data: ['type' => 'assignment.draft_approved', 'v' => 1, 'notification_ulid' => $ulid]);
    $job = new SendPushNotificationJob($token->id, $message, 'assignment.draft_approved');
    $job->setJob(new PushAttemptJob(1));

    pjRun($job);

    expect(pjLog($logs->records, 'push.delivery')['context']['notification_ulid'])->toBe($ulid);
});

it('AH-102b: a message with no notification ULID (or a non-string one) reaches the recorder as null', function (mixed $data): void {
    $token = pjLiveToken();
    pjSender([PushSendResult::delivered()]);
    $logs = pjCaptureLogs();
    $job = new SendPushNotificationJob($token->id, new PushMessage(title: 't', data: $data), 'assignment.draft_approved');
    $job->setJob(new PushAttemptJob(1));

    pjRun($job);

    $context = pjLog($logs->records, 'push.delivery')['context'];
    expect(array_key_exists('notification_ulid', $context))->toBeTrue()
        ->and($context['notification_ulid'])->toBeNull();
})->with([
    'absent' => [['type' => 'assignment.draft_approved', 'v' => 1]],
    'empty string' => [['notification_ulid' => '']],
    'non-string' => [['notification_ulid' => 12345]],
]);
