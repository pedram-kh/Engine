<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Requests;

use App\Modules\Creators\Enums\SocialPlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /api/v1/creators/me/wizard/social.
 *
 * Sprint 3 Chunk 1 records platform + handle without OAuth — the real
 * OAuth exchange lands in Chunk 4. The handle is the displayed
 * identifier (e.g. `@catalyst`), profile_url is the public profile URL.
 */
final class ConnectSocialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'platform' => ['required', Rule::enum(SocialPlatform::class)],
            'handle' => ['required', 'string', 'max:128'],
            'profile_url' => ['required', 'url', 'max:2048'],
        ];
    }
}
