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
}
