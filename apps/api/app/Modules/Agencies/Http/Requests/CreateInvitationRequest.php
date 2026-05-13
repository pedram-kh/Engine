<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Requests;

use App\Modules\Agencies\Enums\AgencyRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class CreateInvitationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', new Enum(AgencyRole::class)],
        ];
    }
}
