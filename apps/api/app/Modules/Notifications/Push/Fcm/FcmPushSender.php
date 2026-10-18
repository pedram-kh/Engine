<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push\Fcm;

use App\Modules\Notifications\Push\PushMessage;
use App\Modules\Notifications\Push\PushPriority;
use App\Modules\Notifications\Push\PushSender;
use App\Modules\Notifications\Push\PushSendResult;
use App\Modules\Notifications\Push\PushTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Firebase Cloud Messaging HTTP v1 implementation of {@see PushSender}.
 *
 * THE ONLY class that knows FCM's JSON, its OAuth exchange and its error
 * vocabulary. Everything vendor-specific is contained under
 * `Notifications/Push/Fcm/` (pinned by a scan test), so replacing or adding a
 * provider for one platform (fallback B: APNs for iOS) touches this directory
 * and one binding — not eligibility, copy, the job, recording or the endpoints.
 *
 * ERROR TAXONOMY (FCM v1 vocabulary — from Google's documentation; NOT yet
 * verified against the live API, see the AH-102 review "Not verified"):
 *   200                                   → Delivered
 *   UNREGISTERED                          → TokenInvalid   (the one unambiguous dead-token signal)
 *   INVALID_ARGUMENT naming the token     → TokenInvalid
 *   INVALID_ARGUMENT otherwise            → Rejected       (OUR message is malformed; do NOT kill tokens)
 *   SENDER_ID_MISMATCH / THIRD_PARTY_AUTH_ERROR → ConfigError (alarm; never a token kill)
 *   401 (after one forced token refresh) / 403 / 404-without-detail → ConfigError
 *   QUOTA_EXCEEDED / UNAVAILABLE / INTERNAL / 429 / 5xx / timeout   → Retryable
 *   any other 4xx                         → Rejected
 * Invariant: a token is only ever declared dead on an EXPLICIT vendor signal.
 */
final class FcmPushSender implements PushSender
{
    /** Vendor limit: apns-collapse-id / collapse_key must stay within 64 bytes. */
    private const COLLAPSE_KEY_MAX_BYTES = 64;

    private const DETAIL_MAX_CHARS = 200;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly FcmAccessTokenProvider $tokens,
    ) {}

    public function send(PushMessage $message, PushTarget $target, bool $validateOnly = false): PushSendResult
    {
        try {
            $account = FcmServiceAccount::fromBase64(config('integrations.push.fcm.service_account_json_b64'));
            $projectId = $this->projectId($account);

            $accessToken = $this->tokens->token($account);
            $response = $this->post($projectId, $accessToken, $message, $target, $validateOnly);

            if ($response->status() === 401) {
                // The cached token may have been revoked/expired early: ONE forced refresh and retry.
                $this->tokens->forget($account);
                $accessToken = $this->tokens->token($account, forceRefresh: true);
                $response = $this->post($projectId, $accessToken, $message, $target, $validateOnly);
            }

            return $this->interpret($response, $target);
        } catch (FcmConfigException $e) {
            return PushSendResult::configError('fcm_config', $this->scrub($e->getMessage(), $target));
        } catch (FcmTransientException $e) {
            return PushSendResult::retryable('fcm_token_transient', $this->scrub($e->getMessage(), $target));
        } catch (ConnectionException $e) {
            return PushSendResult::retryable('fcm_connection', $this->scrub($e->getMessage(), $target));
        }
    }

    /**
     * @throws FcmConfigException
     */
    private function projectId(FcmServiceAccount $account): string
    {
        $configured = config('integrations.push.fcm.project_id');
        $projectId = is_string($configured) && trim($configured) !== '' ? trim($configured) : $account->projectId;

        if ($projectId === null || $projectId === '') {
            throw new FcmConfigException('FCM project id is not configured (FCM_PROJECT_ID is empty and the credential carries none).');
        }

        return $projectId;
    }

    private function post(string $projectId, string $accessToken, PushMessage $message, PushTarget $target, bool $validateOnly): Response
    {
        $timeout = max(1, (int) config('integrations.push.fcm.timeout', 5));
        $base = rtrim((string) config('integrations.push.fcm.send_base_url'), '/');

        $body = ['message' => $this->payload($message, $target)];

        if ($validateOnly) {
            $body['validate_only'] = true;
        }

        return $this->http
            ->withToken($accessToken)
            ->acceptJson()
            ->asJson()
            ->connectTimeout($timeout)
            ->timeout($timeout)
            ->post($base.'/v1/projects/'.rawurlencode($projectId).'/messages:send', $body);
    }

    /**
     * Neutral message → FCM v1 JSON. Both the Android and APNs blocks are always
     * emitted; FCM ignores the one that does not apply to the token's platform.
     *
     * @return array<string, mixed>
     */
    private function payload(PushMessage $message, PushTarget $target): array
    {
        $notification = ['title' => $message->title];

        if ($message->body !== null && $message->body !== '') {
            $notification['body'] = $message->body;
        }

        $android = [
            'priority' => $message->priority === PushPriority::High ? 'HIGH' : 'NORMAL',
            'ttl' => max(0, $message->ttlSeconds).'s',
            'notification' => ['channel_id' => $message->channelId],
        ];

        $apnsHeaders = [
            'apns-priority' => $message->priority === PushPriority::High ? '10' : '5',
            'apns-expiration' => (string) (time() + max(0, $message->ttlSeconds)),
        ];

        $aps = ['sound' => 'default'];

        // AH-108 — the icon number. Android gets nothing for it (no `notification_count`;
        // launcher badges are OEM-dependent, Q6). `isset` rather than `!== null`, on purpose:
        // a message serialized onto the queue by the PREVIOUS release has no `badge`
        // property at all, and reading an uninitialised readonly property throws. `isset`
        // is false for both "never set" and null, and true for a real 0 (which clears).
        if (isset($message->badge)) {
            $aps['badge'] = $message->badge;
        }

        $collapse = $this->collapseKey($message->collapseKey);

        if ($collapse !== null) {
            $android['collapse_key'] = $collapse;
            $android['notification']['tag'] = $collapse;
            $apnsHeaders['apns-collapse-id'] = $collapse;
            $aps['thread-id'] = $collapse;
        }

        $payload = [
            'token' => $target->token,
            'notification' => $notification,
            'android' => $android,
            'apns' => [
                'headers' => $apnsHeaders,
                'payload' => ['aps' => $aps],
            ],
        ];

        if ($message->data !== []) {
            $payload['data'] = array_map(static fn (mixed $v): string => (string) $v, $message->data);
        }

        return $payload;
    }

    private function collapseKey(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        // Over the vendor limit: a stable digest still collapses the same key together.
        return strlen($key) > self::COLLAPSE_KEY_MAX_BYTES ? substr(hash('sha256', $key), 0, 32) : $key;
    }

    private function interpret(Response $response, PushTarget $target): PushSendResult
    {
        $status = $response->status();

        if ($response->successful()) {
            return PushSendResult::delivered($status);
        }

        $errorCode = $this->fcmErrorCode($response) ?? '';
        $detail = $this->scrub($this->errorMessage($response), $target);

        // Explicit FCM error codes first — the vendor's own classification.
        switch ($errorCode) {
            case 'UNREGISTERED':
                return PushSendResult::tokenInvalid('UNREGISTERED', $detail, $status);
            case 'INVALID_ARGUMENT':
                return $this->namesTheToken($detail)
                    ? PushSendResult::tokenInvalid('INVALID_ARGUMENT', $detail, $status)
                    : PushSendResult::rejected('INVALID_ARGUMENT', $detail, $status);
            case 'SENDER_ID_MISMATCH':
            case 'THIRD_PARTY_AUTH_ERROR':
                return PushSendResult::configError($errorCode, $detail, $status);
            case 'QUOTA_EXCEEDED':
            case 'UNAVAILABLE':
            case 'INTERNAL':
                return PushSendResult::retryable($errorCode, $detail, $status);
        }

        // No (recognised) FCM error code: classify by HTTP status. NEVER a token kill from here.
        if ($status === 401 || $status === 403 || $status === 404) {
            // 401 after the refresh retry; 403 = API disabled / no permission; a 404 WITHOUT the
            // UNREGISTERED detail is a wrong project, not a dead token.
            return PushSendResult::configError('http_'.$status, $detail, $status);
        }

        if ($status === 429 || $status >= 500) {
            return PushSendResult::retryable('http_'.$status, $detail, $status);
        }

        return PushSendResult::rejected('http_'.$status, $detail, $status);
    }

    private function fcmErrorCode(Response $response): ?string
    {
        $details = $response->json('error.details');

        if (is_array($details)) {
            foreach ($details as $detail) {
                if (is_array($detail) && is_string($detail['errorCode'] ?? null)) {
                    return $detail['errorCode'];
                }
            }
        }

        // Some responses carry only the gRPC-style status (e.g. "UNAVAILABLE", "INVALID_ARGUMENT").
        $status = $response->json('error.status');

        return is_string($status) && in_array($status, ['UNAVAILABLE', 'INTERNAL', 'INVALID_ARGUMENT'], true) ? $status : null;
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('error.message');

        return is_string($message) ? $message : '';
    }

    /** INVALID_ARGUMENT is a dead token ONLY when FCM says the registration token is the problem. */
    private function namesTheToken(string $message): bool
    {
        return stripos($message, 'registration token') !== false
            || stripos($message, 'not a valid fcm registration') !== false;
    }

    /** Truncate, and never let the device token itself reach a log line. */
    private function scrub(string $text, PushTarget $target): string
    {
        if ($target->token !== '') {
            $text = str_replace($target->token, '[token]', $text);
        }

        return mb_substr($text, 0, self::DETAIL_MAX_CHARS);
    }
}
