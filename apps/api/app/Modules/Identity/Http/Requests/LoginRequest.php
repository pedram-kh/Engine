<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method string input(string $key, mixed $default = null)
 */
final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:320'],
            'password' => ['required', 'string', 'min:1', 'max:128'],
            // mfa_code is optional on the first request — the server
            // signals `auth.mfa_required` and the SPA re-submits with
            // it set. Width range covers both 6-digit TOTP and the
            // 19-char `xxxx-xxxx-xxxx-xxxx` recovery format.
            'mfa_code' => ['sometimes', 'nullable', 'string', 'min:6', 'max:32'],
            // AH-104b-del: re-send the SAME credentials with this set to restore an
            // account that is scheduled for deletion. Ignored for any other account.
            'cancel_deletion' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    public function emailInput(): string
    {
        return strtolower(trim((string) $this->input('email')));
    }

    public function passwordInput(): string
    {
        return (string) $this->input('password');
    }

    public function cancelDeletionInput(): bool
    {
        return $this->boolean('cancel_deletion');
    }

    public function mfaCodeInput(): ?string
    {
        $raw = $this->input('mfa_code');

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }
}
