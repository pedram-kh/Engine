<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the batch body of `PATCH /me/notification-preferences` (S11.0
 * Chunk 3b, D-7).
 *
 * Owner-scope is structural: there is NO `{user}` path segment and no policy —
 * the controller resolves the owner from `$request->user()`. This request only
 * shapes the payload.
 *
 * The body is a batch of per-row toggles:
 *   { "preferences": [ { "notification_type": …, "channel": …, "is_enabled": bool }, … ] }
 *
 * Channels are validated against the FULL enum (in_app/email/digest), not just
 * the in-app subset the UI currently exposes (D-2): the sparse backend supports
 * any channel the moment a consumer ships, with no request change.
 */
final class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.notification_type' => ['required', Rule::enum(NotificationType::class)],
            'preferences.*.channel' => ['required', Rule::enum(NotificationChannel::class)],
            'preferences.*.is_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * The validated rows, cast to their enums.
     *
     * @return list<array{type: NotificationType, channel: NotificationChannel, is_enabled: bool}>
     */
    public function preferences(): array
    {
        /** @var list<array{notification_type: string, channel: string, is_enabled: bool}> $rows */
        $rows = $this->validated()['preferences'];

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'type' => NotificationType::from($row['notification_type']),
                'channel' => NotificationChannel::from($row['channel']),
                'is_enabled' => (bool) $row['is_enabled'],
            ];
        }

        return $out;
    }
}
