<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push\Fcm;

use JsonException;

/**
 * The Google service-account credential, decoded from the single base64 env var
 * `FCM_SERVICE_ACCOUNT_JSON_B64` (P-1). Decoding happens HERE, lazily, at send
 * time — a missing or malformed value is a {@see FcmConfigException} (→ a
 * ConfigError outcome), never a boot-time crash.
 *
 * The private key is a secret: this class has no `__toString`, and callers must
 * never log it or any part of the JSON.
 */
final readonly class FcmServiceAccount
{
    private function __construct(
        public string $clientEmail,
        public string $privateKey,
        public ?string $projectId,
    ) {}

    /**
     * @throws FcmConfigException
     */
    public static function fromBase64(?string $encoded): self
    {
        $encoded = $encoded === null ? '' : trim($encoded);

        if ($encoded === '') {
            throw new FcmConfigException('FCM service account is not configured (FCM_SERVICE_ACCOUNT_JSON_B64 is empty).');
        }

        $json = base64_decode($encoded, true);

        if ($json === false || $json === '') {
            throw new FcmConfigException('FCM_SERVICE_ACCOUNT_JSON_B64 is not valid base64.');
        }

        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FcmConfigException('FCM_SERVICE_ACCOUNT_JSON_B64 does not decode to valid JSON.');
        }

        if (! is_array($decoded)) {
            throw new FcmConfigException('FCM_SERVICE_ACCOUNT_JSON_B64 does not decode to a JSON object.');
        }

        $email = $decoded['client_email'] ?? null;
        $key = $decoded['private_key'] ?? null;
        $project = $decoded['project_id'] ?? null;

        if (! is_string($email) || $email === '' || ! is_string($key) || $key === '') {
            throw new FcmConfigException('FCM service account JSON is missing client_email or private_key.');
        }

        return new self($email, $key, is_string($project) && $project !== '' ? $project : null);
    }
}
