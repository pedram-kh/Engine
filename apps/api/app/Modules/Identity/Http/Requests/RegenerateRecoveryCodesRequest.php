<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Regeneration requires a working 2FA code so a stolen-session attacker
 * who briefly has authenticated access cannot rotate the recovery codes
 * out from under the legitimate user.
 */
final class RegenerateRecoveryCodesRequest extends FormRequest
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
            'mfa_code' => ['required', 'string', 'min:6', 'max:32'],
        ];
    }

    public function mfaCode(): string
    {
        return trim((string) $this->input('mfa_code'));
    }
}
