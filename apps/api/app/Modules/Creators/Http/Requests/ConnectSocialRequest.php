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
    /**
     * Allowed handle characters across IG/TikTok/YouTube: letters, digits,
     * dot, underscore and hyphen, 2–30 chars. Deliberately rejects spaces,
     * slashes and `:` so a pasted profile URL (the most common mistake) is
     * caught with a clear message rather than stored as a broken handle.
     */
    private const HANDLE_PATTERN = '/^[A-Za-z0-9._-]{2,30}$/';

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
            'handle' => ['required', 'string', 'regex:'.self::HANDLE_PATTERN],
            'profile_url' => ['required', 'url', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'handle.regex' => 'Enter a valid username: 2–30 letters, numbers, dots, underscores or hyphens — no spaces, @, or URLs.',
        ];
    }

    /**
     * Normalize before validation: trim and strip a single leading `@` so
     * "@catalyst" and "catalyst" are treated identically and stored bare.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('handle')) {
            $this->merge([
                'handle' => ltrim(trim((string) $this->input('handle')), '@'),
            ]);
        }
    }
}
