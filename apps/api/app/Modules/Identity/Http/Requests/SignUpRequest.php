<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Rules\PasswordIsNotBreached;
use App\Modules\Identity\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for `POST /api/v1/auth/sign-up`.
 *
 * The same {@see StrongPassword} and {@see PasswordIsNotBreached} rules
 * the password-reset flow uses are reapplied here so a sign-up password
 * lands at exactly the same security floor as a reset password — no
 * surface-specific weakening (docs/05-SECURITY-COMPLIANCE.md §6.1).
 *
 * `email` uniqueness is enforced both at the validator (friendly 422)
 * and at the database (unique index — race-safe).
 */
final class SignUpRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:320',
                Rule::unique('users', 'email'),
            ],
            'password' => [
                'required',
                'string',
                'confirmed',
                app(StrongPassword::class),
                app(PasswordIsNotBreached::class),
            ],
            'password_confirmation' => ['required', 'string'],
            'preferred_language' => ['sometimes', 'string', 'in:en,pt,it'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => trans('auth.signup.email_taken'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedAttributes(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return $validated;
    }

    /**
     * Normalise the email and name BEFORE validation so the unique check
     * is case-insensitive and the SignUpService receives clean values.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        $name = $this->input('name');

        $this->merge([
            'email' => is_string($email) ? strtolower(trim($email)) : $email,
            'name' => is_string($name) ? trim($name) : $name,
        ]);
    }
}
