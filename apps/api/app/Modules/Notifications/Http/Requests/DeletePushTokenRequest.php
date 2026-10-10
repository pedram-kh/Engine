<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of `DELETE /me/push-tokens` (AH-102): the device token the client wants
 * forgotten (it was just told the user opted out, or it is about to
 * `deleteToken()` on the device).
 */
final class DeletePushTokenRequest extends FormRequest
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
            'token' => ['required', 'string', 'min:1', 'max:512'],
        ];
    }

    public function deviceToken(): string
    {
        return (string) $this->validated('token');
    }
}
