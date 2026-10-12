<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Push\PushDeliveryOutcome;
use App\Modules\Notifications\Push\PushDeliveryRecorder;
use App\Modules\Notifications\Push\PushMessage;
use App\Modules\Notifications\Push\PushOutcome;
use App\Modules\Notifications\Push\PushSender;
use App\Modules\Notifications\Push\PushTarget;
use App\Modules\Notifications\Push\PushTokenRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends ONE push message to ONE device token (AH-102, plan §5.2). The dispatcher
 * fans a notification out to one job per live token.
 *
 * QUEUE. The `default` queue — the only queue the production worker consumes
 * (`docs/runbooks/production-queue-worker.md`). A named queue would never run.
 * The HTTP timeout (integrations.push.fcm.timeout, 2 calls worst case) plus this
 * `$timeout` stay far below the connection's `retry_after` (300 s), so a slow
 * send is never delivered twice.
 *
 * AFTER COMMIT. `$afterCommit` is true (set in the constructor): a push for an event whose transaction
 * rolls back must never be sent (`notify()` can run inside the state machine's
 * `DB::transaction`).
 *
 * The job carries the rendered {@see PushMessage} (title + allowlisted names +
 * ids — no message text, feedback or reasons) and a token id, NOT the device
 * token: the token is re-read, and re-checked for liveness, at run time.
 *
 * Outcome handling is by {@see PushOutcome} only — this class knows no vendor:
 *   Delivered    → record
 *   TokenInvalid → record (marks the token invalid) — NO retry
 *   Retryable    → release with backoff; when attempts run out, record as final
 *   ConfigError / Rejected → record at error level — NO retry (a retry cannot fix it)
 */
final class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Seconds before the 2nd and 3rd attempts. */
    private const array BACKOFF_SECONDS = [30, 120];

    public int $tries = 3;

    public int $timeout = 20;

    public function __construct(
        public readonly int $tokenId,
        public readonly PushMessage $message,
        public readonly string $notificationType,
    ) {
        // `Queueable` already declares `$afterCommit`; this sets it (true).
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return self::BACKOFF_SECONDS;
    }

    public function handle(PushSender $sender, PushTokenRegistry $tokens, PushDeliveryRecorder $recorder): void
    {
        // Gone, invalidated, bearer revoked/expired, or user deleted since dispatch → nothing to do.
        $token = $tokens->findLive($this->tokenId);

        if ($token === null) {
            return;
        }

        $result = $sender->send($this->message, new PushTarget($token->platform, $token->token));

        $attempt = max(1, $this->attempts());
        $exhausted = $result->outcome === PushOutcome::Retryable && $attempt >= $this->tries;

        $recorder->record(new PushDeliveryOutcome(
            tokenId: $token->id,
            userId: $token->user_id,
            platform: $token->platform,
            notificationType: $this->notificationType,
            outcome: $result->outcome,
            attempt: $attempt,
            code: $result->code,
            detail: $result->detail,
            httpStatus: $result->httpStatus,
            final: $exhausted,
            notificationUlid: $this->notificationUlid(),
        ));

        if ($result->outcome === PushOutcome::Retryable && ! $exhausted) {
            $this->release(self::BACKOFF_SECONDS[min($attempt, count(self::BACKOFF_SECONDS)) - 1]);
        }
    }

    /**
     * Only an unexpected exception (the sender contract says it never throws for
     * provider problems) reaches here. Logged without the device token.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('push.job_failed', [
            'token_id' => $this->tokenId,
            'notification_type' => $this->notificationType,
            'exception' => $exception === null ? null : $exception::class,
            'message' => $exception?->getMessage(),
        ]);
    }

    /** The in-app notification this push mirrors, if the message carries one (AH-102b delivery join key). */
    private function notificationUlid(): ?string
    {
        $ulid = $this->message->data['notification_ulid'] ?? null;

        return is_string($ulid) && $ulid !== '' ? $ulid : null;
    }
}
