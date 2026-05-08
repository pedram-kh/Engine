<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Disable requires BOTH the user's current password AND a working 2FA
 * code (either TOTP or recovery). See chunk 5 priority #10. The
 * controller does the actual verification; this request only bounds
 * the input shape.
 */
final class DisableTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:1', 'max:128'],
            'mfa_code' => ['required', 'string', 'min:6', 'max:32'],
        ];
    }

    public function password(): string
    {
        return (string) $this->input('password');
    }

    public function mfaCode(): string
    {
        return trim((string) $this->input('mfa_code'));
    }
}
