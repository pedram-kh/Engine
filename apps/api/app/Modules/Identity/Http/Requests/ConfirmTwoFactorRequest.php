<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmTwoFactorRequest extends FormRequest
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
            'provisional_token' => ['required', 'string', 'min:8', 'max:64'],
            'code' => ['required', 'string', 'min:6', 'max:6'],
        ];
    }

    public function provisionalToken(): string
    {
        return (string) $this->input('provisional_token');
    }

    public function code(): string
    {
        return trim((string) $this->input('code'));
    }
}
