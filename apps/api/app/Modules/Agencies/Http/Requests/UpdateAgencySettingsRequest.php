<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Requests;

use App\Core\Enums\Locale;
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
            // Content-language default: full 24 EU languages.
            'default_language' => ['sometimes', 'string', Rule::enum(Locale::class)],
            // Sprint 7 (D-4) — the FIRST API consumer of the agencies.settings
            // jsonb. Whether creators are emailed when blacklisted. Default OFF.
            'blacklist_notification_policy' => ['sometimes', 'boolean'],
        ];
    }
}
