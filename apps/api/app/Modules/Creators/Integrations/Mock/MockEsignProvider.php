<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Mock;

use App\Modules\Creators\Enums\EsignStatus;
use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\EsignEnvelopeResult;
use App\Modules\Creators\Integrations\DataTransferObjects\EsignWebhookEvent;
use App\Modules\Creators\Models\Creator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * Mock e-signature provider — analogue of {@see MockKycProvider}.
 * See that class for the storage / state-machine / webhook-secret
 * conventions; this class follows the identical shape with the
 * envelope vocabulary substituted for verification-session
 * vocabulary. Sprint 9 swaps in a real adapter.
 *
 * @phpstan-type MockEsignSession array{state: 'sent'|'signed'|'declined'|'expired'|'cancelled', creator_ulid: string, completed_at: ?string}
 */
final class MockEsignProvider implements EsignProvider
{
    public const SESSION_TTL_SECONDS = 24 * 60 * 60;

    public static function envelopeCacheKey(string $envelopeId): string
    {
        return 'mock:esign:envelope:'.$envelopeId;
    }

    public static function latestEnvelopePointerKey(string $creatorUlid): string
    {
        return 'mock:esign:latest:'.$creatorUlid;
    }

    public function sendEnvelope(Creator $creator): EsignEnvelopeResult
    {
        $envelopeId = 'mock_env_'.Str::ulid()->toBase32();

        Cache::put(
            self::envelopeCacheKey($envelopeId),
            [
                'state' => 'sent',
                'creator_ulid' => $creator->ulid,
                'completed_at' => null,
            ],
            self::SESSION_TTL_SECONDS,
        );

        Cache::put(
            self::latestEnvelopePointerKey($creator->ulid),
            $envelopeId,
            self::SESSION_TTL_SECONDS,
        );

        return new EsignEnvelopeResult(
            envelopeId: $envelopeId,
            signingUrl: url('/_mock-vendor/esign/'.$envelopeId),
            expiresAt: now()->addSeconds(self::SESSION_TTL_SECONDS)->toIso8601String(),
        );
    }

    public function getEnvelopeStatus(Creator $creator): EsignStatus
    {
        $latestEnvelopeId = Cache::get(self::latestEnvelopePointerKey($creator->ulid));

        if (! is_string($latestEnvelopeId)) {
            return EsignStatus::Sent;
        }

        $envelope = Cache::get(self::envelopeCacheKey($latestEnvelopeId));

        if (! is_array($envelope)) {
            return EsignStatus::Sent;
        }

        return match ($envelope['state'] ?? null) {
            'signed' => EsignStatus::Signed,
            'declined' => EsignStatus::Declined,
            'expired' => EsignStatus::Expired,
            // Cancelled envelopes look like "still sent, awaiting
            // creator action" to the wizard — re-sending an envelope
            // is allowed; we don't need a separate state.
            'sent', 'cancelled' => EsignStatus::Sent,
            default => EsignStatus::Sent,
        };
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secret = self::webhookSecret();

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    public function parseWebhookEvent(string $payload): EsignWebhookEvent
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('MockEsignProvider received malformed JSON payload: '.$e->getMessage(), previous: $e);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('MockEsignProvider expects a JSON object payload; got '.gettype($decoded));
        }

        $eventId = $decoded['event_id'] ?? null;
        $eventType = $decoded['event_type'] ?? null;

        if (! is_string($eventId) || $eventId === '') {
            throw new InvalidArgumentException('MockEsignProvider payload missing required string field: event_id');
        }

        if (! is_string($eventType) || $eventType === '') {
            throw new InvalidArgumentException('MockEsignProvider payload missing required string field: event_type');
        }

        $creatorUlid = is_string($decoded['creator_ulid'] ?? null) && $decoded['creator_ulid'] !== ''
            ? $decoded['creator_ulid']
            : null;

        $envelopeStatus = match ($decoded['envelope_status'] ?? null) {
            'signed' => EsignStatus::Signed,
            'declined' => EsignStatus::Declined,
            'expired' => EsignStatus::Expired,
            'sent' => EsignStatus::Sent,
            default => null,
        };

        return new EsignWebhookEvent(
            providerEventId: $eventId,
            eventType: $eventType,
            creatorUlid: $creatorUlid,
            envelopeStatus: $envelopeStatus,
            rawPayload: $decoded,
        );
    }

    public static function webhookSecret(): string
    {
        $secret = config('integrations.esign.mock_webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw new \LogicException(
                'integrations.esign.mock_webhook_secret must be a non-empty string in config/integrations.php.',
            );
        }

        return $secret;
    }
}
