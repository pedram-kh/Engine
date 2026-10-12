<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

use App\Modules\Notifications\Jobs\SendPushNotificationJob;
use App\Modules\Notifications\Models\PushDelivery;
use App\Modules\Notifications\Models\UserPushToken;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * THE one place a finished send attempt is recorded (AH-102, plan §5.2; AH-102b
 * plan §3.2). Every send attempt goes through {@see self::record()}:
 *
 *  1. marks a provider-reported dead token invalid (so it drops out of every
 *     future fan-out);
 *  2. writes one append-only `push_deliveries` row (what the admin Push
 *     deliveries page reads);
 *  3. writes one structured log line (the `DebouncedMessageMailer::logEmission()`
 *     precedent).
 *
 * CONTAINMENT IS THE REQUIREMENT. This runs inside {@see SendPushNotificationJob}
 * AFTER the push has been handed to FCM. If any step threw, the job would fail,
 * be retried, and send the user a DUPLICATE push. So every step is its own
 * `try/catch (Throwable)`, a failure is logged as `push.delivery_record_failed`
 * and **never rethrown**, and even that failure log is guarded. The steps are
 * independent: a throwing step never prevents the next one.
 *
 * Order matters: invalidate FIRST — token hygiene is worth more than the audit
 * row and must not depend on the insert succeeding — then insert, then log. The
 * likeliest real-world failure is `permission denied` on the new table or its
 * sequence for the production role (no GRANT yet); that costs rows, not
 * duplicate pushes.
 *
 * Savepoints are deliberately not used: the queue worker does not run this
 * inside a transaction. A future caller that does (on Postgres, a failed
 * statement poisons the surrounding transaction) is a new call site with its
 * own review.
 *
 * Never stores or logs the device token or any message text. `detail` is
 * written only for non-delivered outcomes and capped.
 */
final class PushDeliveryRecorder
{
    private const int CODE_MAX = 64;

    private const int DETAIL_MAX = 200;

    private const int ULID_LENGTH = 26;

    public function __construct(private readonly PushTokenRegistry $tokens) {}

    public function record(PushDeliveryOutcome $result): void
    {
        if ($result->outcome === PushOutcome::TokenInvalid) {
            $this->contain('invalidate', $result, function () use ($result): void {
                $row = UserPushToken::query()->find($result->tokenId);

                if ($row instanceof UserPushToken) {
                    $this->tokens->invalidate($row, 'provider_'.strtolower($result->code ?? 'invalid'));
                }
            });
        }

        $this->contain('insert', $result, function () use ($result): void {
            PushDelivery::query()->create([
                'user_id' => $result->userId,
                'user_push_token_id' => $result->tokenId,
                'platform' => $result->platform,
                'notification_type' => mb_substr($result->notificationType, 0, 64),
                'notification_ulid' => $result->notificationUlid === null
                    ? null
                    : mb_substr($result->notificationUlid, 0, self::ULID_LENGTH),
                'outcome' => $result->outcome,
                'attempt' => $result->attempt,
                'final' => $result->final,
                'code' => $result->code === null ? null : mb_substr($result->code, 0, self::CODE_MAX),
                'http_status' => $result->httpStatus,
                'detail' => $result->outcome === PushOutcome::Delivered || $result->detail === null
                    ? null
                    : mb_substr($result->detail, 0, self::DETAIL_MAX),
                'created_at' => now(),
            ]);
        });

        $this->contain('log', $result, function () use ($result): void {
            $context = [
                'token_id' => $result->tokenId,
                'user_id' => $result->userId,
                'platform' => $result->platform->value,
                'notification_type' => $result->notificationType,
                'notification_ulid' => $result->notificationUlid,
                'outcome' => $result->outcome->value,
                'attempt' => $result->attempt,
                'code' => $result->code,
                'http_status' => $result->httpStatus,
                'final' => $result->final,
            ];

            match ($result->outcome) {
                PushOutcome::Delivered => Log::info('push.delivery', $context),
                PushOutcome::TokenInvalid => Log::info('push.token_invalidated', $context),
                PushOutcome::Retryable => Log::warning('push.delivery_retryable', $context + ['detail' => $result->detail]),
                // Config errors are an ALARM (the whole channel is down), rejections are our payload being wrong.
                PushOutcome::ConfigError => Log::error('push.config_error', $context + ['detail' => $result->detail]),
                PushOutcome::Rejected => Log::error('push.delivery_rejected', $context + ['detail' => $result->detail]),
            };
        });
    }

    /**
     * Run one step; a failure is reported and swallowed, never rethrown.
     *
     * @param  Closure(): void  $step
     */
    private function contain(string $name, PushDeliveryOutcome $result, Closure $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            $this->reportFailure($name, $result, $e);
        }
    }

    /**
     * Context is identifiers, the step name and the exception CLASS only — the
     * exception message is deliberately left out (a driver error can echo SQL
     * bindings, and the bindings of an insert are the row).
     */
    private function reportFailure(string $step, PushDeliveryOutcome $result, Throwable $e): void
    {
        try {
            Log::error('push.delivery_record_failed', [
                'step' => $step,
                'token_id' => $result->tokenId,
                'user_id' => $result->userId,
                'outcome' => $result->outcome->value,
                'exception' => $e::class,
            ]);
        } catch (Throwable) {
            // A failing logger must not turn a recorded send into a retried job.
        }
    }
}
