<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Mock;

use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\KycInitiationResult;
use App\Modules\Creators\Integrations\DataTransferObjects\KycWebhookEvent;
use App\Modules\Creators\Jobs\SimulateKycWebhookJob;
use App\Modules\Creators\Models\Creator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * Mock KYC verification provider.
 *
 * Bound by {@see CreatorsServiceProvider} when
 * `kyc_verification_enabled` is ON (sub-step 8). Sprint 3's wizard
 * runs end-to-end against this — Sprint 4 swaps in a real vendor
 * adapter without touching the contract or the wizard.
 *
 * Session storage: per-creator simulated sessions live in the
 * default cache store with a 24-hour TTL (matches the
 * {@see KycInitiationResult::$expiresAt} the wizard advertises).
 * Tests use Laravel's standard `Cache::fake()` shape to round-trip
 * sessions deterministically; production stores them in Redis.
 *
 * Mock state transitions are driven by the local `/_mock-vendor/kyc/
 * {session}` Blade page (sub-step 5) — the creator clicks
 * "Complete (success)" / "Complete (fail)" / "Cancel"; the page's
 * POST handler updates the session state AND dispatches a
 * {@see SimulateKycWebhookJob} (sub-step
 * 5) that drives the production webhook path end-to-end via the
 * job pipeline (Q-mock-webhook-dispatch = (b)).
 *
 * @phpstan-type MockKycSession array{state: 'pending'|'success'|'fail'|'cancelled', creator_ulid: string, completed_at: ?string}
 */
final class MockKycProvider implements KycProvider
{
    /**
     * Mock-session TTL — 24 hours. Matches the
     * {@see KycInitiationResult::$expiresAt} hint surfaced to the
     * wizard, so the cache entry and the advertised expiry agree.
     */
    public const SESSION_TTL_SECONDS = 24 * 60 * 60;

    /**
     * Cache-key shape: `mock:kyc:session:{sessionId}`. Single source
     * of truth so any future {@see \App\Modules\Creators\Jobs\
     * SimulateKycWebhookJob} that needs to read the same record uses
     * an identical key without duplicating the convention.
     */
    public static function sessionCacheKey(string $sessionId): string
    {
        return 'mock:kyc:session:'.$sessionId;
    }

    /**
     * Pointer key: `mock:kyc:latest:{creatorUlid}` → latest session id.
     * Lets {@see self::getVerificationStatus()} find the most recent
     * session for the creator without scanning the cache.
     */
    public static function latestSessionPointerKey(string $creatorUlid): string
    {
        return 'mock:kyc:latest:'.$creatorUlid;
    }

    public function initiateVerification(Creator $creator): KycInitiationResult
    {
        $sessionId = 'mock_kyc_'.Str::ulid()->toBase32();

        Cache::put(
            self::sessionCacheKey($sessionId),
            [
                'state' => 'pending',
                'creator_ulid' => $creator->ulid,
                'completed_at' => null,
            ],
            self::SESSION_TTL_SECONDS,
        );

        Cache::put(
            self::latestSessionPointerKey($creator->ulid),
            $sessionId,
            self::SESSION_TTL_SECONDS,
        );

        return new KycInitiationResult(
            sessionId: $sessionId,
            hostedFlowUrl: url('/_mock-vendor/kyc/'.$sessionId),
            expiresAt: now()->addSeconds(self::SESSION_TTL_SECONDS)->toIso8601String(),
        );
    }

    public function getVerificationStatus(Creator $creator): KycStatus
    {
        $latestSessionId = Cache::get(self::latestSessionPointerKey($creator->ulid));

        if (! is_string($latestSessionId)) {
            return KycStatus::None;
        }

        $session = Cache::get(self::sessionCacheKey($latestSessionId));

        if (! is_array($session)) {
            return KycStatus::None;
        }

        return match ($session['state'] ?? null) {
            'success' => KycStatus::Verified,
            'fail' => KycStatus::Rejected,
            'pending' => KycStatus::Pending,
            // Cancelled sessions reset the wizard's expectation —
            // the creator can re-start the step. None (rather than
            // Pending) prevents the UI from spinning on a session
            // the creator deliberately abandoned.
            'cancelled' => KycStatus::None,
            default => KycStatus::None,
        };
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secret = self::webhookSecret();

        $expected = hash_hmac('sha256', $payload, $secret);

        // Constant-time comparison defends against timing attacks
        // even in the mock — keeps the test surface honest about
        // how the real adapter must behave (#40).
        return hash_equals($expected, $signature);
    }

    public function parseWebhookEvent(string $payload): KycWebhookEvent
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('MockKycProvider received malformed JSON payload: '.$e->getMessage(), previous: $e);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('MockKycProvider expects a JSON object payload; got '.gettype($decoded));
        }

        $eventId = $decoded['event_id'] ?? null;
        $eventType = $decoded['event_type'] ?? null;

        if (! is_string($eventId) || $eventId === '') {
            throw new InvalidArgumentException('MockKycProvider payload missing required string field: event_id');
        }

        if (! is_string($eventType) || $eventType === '') {
            throw new InvalidArgumentException('MockKycProvider payload missing required string field: event_type');
        }

        $creatorUlid = is_string($decoded['creator_ulid'] ?? null) && $decoded['creator_ulid'] !== ''
            ? $decoded['creator_ulid']
            : null;

        $verificationResult = match ($decoded['verification_result'] ?? null) {
            'verified' => KycStatus::Verified,
            'rejected' => KycStatus::Rejected,
            'pending' => KycStatus::Pending,
            default => null,
        };

        return new KycWebhookEvent(
            providerEventId: $eventId,
            eventType: $eventType,
            creatorUlid: $creatorUlid,
            verificationResult: $verificationResult,
            rawPayload: $decoded,
        );
    }

    /**
     * The HMAC-SHA256 secret. Public so {@see \App\Modules\Creators\
     * Jobs\SimulateKycWebhookJob} (sub-step 5) can use the same
     * secret to sign the simulated payload — single source of truth.
     */
    public static function webhookSecret(): string
    {
        $secret = config('integrations.kyc.mock_webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw new \LogicException(
                'integrations.kyc.mock_webhook_secret must be a non-empty string in config/integrations.php.',
            );
        }

        return $secret;
    }
}
