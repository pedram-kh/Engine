<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of POST /api/v1/me/account-deletion. Only bounds the input shape; the
 * service verifies the password and the second factor.
 *
 * `acknowledged` is the creator's tick on the consequences list. The typed
 * word ("DELETE", localised) is a client-side gate and is not sent: a script
 * with the password and a valid code could always skip it, so the server
 * asks for what it can verify and nothing it cannot.
 */
final class RequestAccountDeletionRequest extends FormRequest
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
            'mfa_code' => ['nullable', 'string', 'max:32'],
            'acknowledged' => ['required', 'boolean', 'accepted'],
        ];
    }

    public function password(): string
    {
        return (string) $this->input('password');
    }

    public function mfaCode(): ?string
    {
        $code = $this->input('mfa_code');

        return is_string($code) && trim($code) !== '' ? trim($code) : null;
    }
}
