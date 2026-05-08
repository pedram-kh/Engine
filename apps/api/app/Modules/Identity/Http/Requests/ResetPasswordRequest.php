<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Rules\PasswordIsNotBreached;
use App\Modules\Identity\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;

final class ResetPasswordRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:rfc', 'max:320'],
            'token' => ['required', 'string', 'min:1', 'max:255'],
            'password' => [
                'required',
                'string',
                'confirmed',
                app(StrongPassword::class),
                app(PasswordIsNotBreached::class),
            ],
            'password_confirmation' => ['required', 'string'],
        ];
    }

    public function emailInput(): string
    {
        return strtolower(trim((string) $this->input('email')));
    }

    public function tokenInput(): string
    {
        return (string) $this->input('token');
    }

    public function passwordInput(): string
    {
        return (string) $this->input('password');
    }
}
