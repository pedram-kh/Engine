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
            // Sprint 7 (D-4) — the FIRST API consumer of the agencies.settings
            // jsonb. Whether creators are emailed when blacklisted. Default OFF.
            'blacklist_notification_policy' => ['sometimes', 'boolean'],
        ];
    }
}
