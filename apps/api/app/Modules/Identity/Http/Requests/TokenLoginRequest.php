<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of POST /api/v1/auth/token — the creator app's login (AH-099).
 *
 * Identical credential fields to {@see LoginRequest} plus `platform`, which
 * names the per-device token (`creator-app:{ios|android}`). The credential
 * rules are deliberately the same so the two doors reject the same malformed
 * input in the same way.
 *
 * @method string input(string $key, mixed $default = null)
 */
final class TokenLoginRequest extends FormRequest
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
            'mfa_code' => ['sometimes', 'nullable', 'string', 'min:6', 'max:32'],
            // AH-104b-del: re-send the SAME credentials with this set to restore an
            // account that is scheduled for deletion. Ignored for any other account.
            'cancel_deletion' => ['sometimes', 'nullable', 'boolean'],
            'platform' => ['required', 'string', 'in:ios,android'],
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

    /**
     * @return 'ios'|'android'
     */
    public function platformInput(): string
    {
        return $this->input('platform') === 'android' ? 'android' : 'ios';
    }
}
