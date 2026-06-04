<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Requests;

use App\Modules\Agencies\Enums\BlacklistScope;
use App\Modules\Agencies\Enums\BlacklistType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates a blacklist write (Sprint 7, A4 / D-6 / D-7).
 *
 *   - scope    : agency | brand (the {@see BlacklistScope} enum). Drives which
 *                write path runs — agency-wide columns on the relation (D-2),
 *                or a brand_creator_blacklists row (D-2: no relation touch).
 *   - type     : hard | soft (the {@see BlacklistType} enum). hard = exclude
 *                (discovery + send gate, Part B); soft = warn only (D-1).
 *   - reason   : REQUIRED (D-7 — you only ever blacklist WITH a reason).
 *                Free-text, GDPR-sensitive: never audit-logged (D-5), never on
 *                any read resource (B4).
 *   - brand_id : the brand ULID, REQUIRED when scope=brand (and forbidden
 *                otherwise — a brand_id under agency scope is a contradiction).
 *                Resolved to an agency-owned brand in the controller.
 *
 * Authorization is the controller's Gate::authorize('blacklist', ...)
 * (admin/manager) after the route's tenancy check, so this authorizes
 * unconditionally (mirrors UpdateAgencyCreatorRelationRequest).
 */
final class BlacklistCreatorRequest extends FormRequest
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
            'type' => ['required', new Enum(BlacklistType::class)],
            'reason' => ['required', 'string', 'max:5000'],
            'brand_id' => [
                'required_if:scope,'.BlacklistScope::Brand->value,
                Rule::prohibitedIf(fn (): bool => $this->input('scope') === BlacklistScope::Agency->value),
                'string',
            ],
        ];
    }
}
