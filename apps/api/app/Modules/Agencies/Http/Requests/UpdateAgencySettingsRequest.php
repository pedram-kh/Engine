<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAgencySettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'default_currency' => ['sometimes', 'string', 'size:3'],
            'default_language' => ['sometimes', 'string', Rule::in(['en', 'pt', 'it'])],
        ];
    }
}
