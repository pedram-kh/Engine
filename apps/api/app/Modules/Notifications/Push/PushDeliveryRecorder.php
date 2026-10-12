<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

use App\Modules\Notifications\Models\UserPushToken;
use Illuminate\Support\Facades\Log;

/**
 * THE one place a finished send is recorded (AH-102, plan §5.2) — and the AH-102b
 * seam.
 *
 * Today it does two things: marks a provider-reported dead token invalid (so it
 * drops out of every future fan-out) and writes one structured log line (the
 * `DebouncedMessageMailer::logEmission()` precedent). AH-102b's delivery
 * telemetry replaces/extends the BODY of {@see self::record()} with an insert
 * into a `push_deliveries` table — every caller already goes through here, so
 * no call site changes. No schema for that table exists yet.
 *
 * Never logs the device token or any message text.
 */
final class PushDeliveryRecorder
{
    public function __construct(private readonly PushTokenRegistry $tokens) {}

    public function record(PushDeliveryOutcome $result): void
    {
        if ($result->outcome === PushOutcome::TokenInvalid) {
            $row = UserPushToken::query()->find($result->tokenId);

            if ($row instanceof UserPushToken) {
                $this->tokens->invalidate($row, 'provider_'.strtolower($result->code ?? 'invalid'));
            }
        }

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
    }
}
