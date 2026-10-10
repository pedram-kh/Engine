<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Notifications\Push\PushPlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body of `POST /me/push-tokens` (AH-102). The owner is NEVER in the body —
 * it is the authenticated creator, and the device login is the bearer the
 * request arrived under.
 *
 *   { "platform": "ios"|"android", "token": "<FCM registration token>", "app_version": "1.2.3"? }
 */
final class RegisterPushTokenRequest extends FormRequest
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
            'platform' => ['required', 'string', Rule::enum(PushPlatform::class)],
            'token' => ['required', 'string', 'min:1', 'max:512'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function platform(): PushPlatform
    {
        return PushPlatform::from((string) $this->validated('platform'));
    }

    public function deviceToken(): string
    {
        return (string) $this->validated('token');
    }

    public function appVersion(): ?string
    {
        $version = $this->validated('app_version');

        return is_string($version) && $version !== '' ? $version : null;
    }
}
