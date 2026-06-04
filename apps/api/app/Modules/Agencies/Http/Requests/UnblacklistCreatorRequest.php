<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Requests;

use App\Modules\Agencies\Enums\BlacklistScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates an un-blacklist write (Sprint 7, A2 / A3).
 *
 *   - scope    : agency | brand — which blacklist to lift. Agency clears the
 *                relation columns; brand soft-deletes the brand_creator_blacklists
 *                row (D-3).
 *   - brand_id : the brand ULID, REQUIRED when scope=brand (forbidden under
 *                agency scope). No reason is needed to un-blacklist.
 */
final class UnblacklistCreatorRequest extends FormRequest
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
            'scope' => ['required', new Enum(BlacklistScope::class)],
            'brand_id' => [
                'required_if:scope,'.BlacklistScope::Brand->value,
                Rule::prohibitedIf(fn (): bool => $this->input('scope') === BlacklistScope::Agency->value),
                'string',
            ],
        ];
    }
}
