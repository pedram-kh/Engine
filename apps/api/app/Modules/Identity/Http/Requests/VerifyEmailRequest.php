<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Services\EmailVerificationToken;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for `POST /api/v1/auth/verify-email`.
 *
 * The token is opaque — its structure is validated cryptographically
 * inside {@see EmailVerificationToken},
 * not at the validator. We just enforce a sane upper bound so we don't
 * waste cycles on multi-megabyte payloads from naive scrapers.
 */
final class VerifyEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:1', 'max:1024'],
        ];
    }

    public function tokenInput(): string
    {
        return (string) $this->input('token');
    }
}
