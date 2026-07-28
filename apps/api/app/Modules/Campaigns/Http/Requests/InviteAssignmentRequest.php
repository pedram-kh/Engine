<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Requests;

use App\Modules\Campaigns\Http\Requests\Concerns\ValidatesAssignmentOffer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates a single creator invite (Sprint 8 Chunk 2, D-3/D-8). Role
 * authorization (the `invite` execute ability) lives in the controller via
 * Gate::authorize (the house pattern).
 *
 * ⚠ The OFFER rules moved to {@see ValidatesAssignmentOffer} (AH-058 S4) when
 * accept-an-application became a second path carrying the same offer. This class
 * keeps exactly what is specific to a direct invite: the creator the agency
 * picked. Nothing about the validation changed in that move.
 */
final class InviteAssignmentRequest extends FormRequest
{
    use ValidatesAssignmentOffer;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The creator's PUBLIC ULID; discoverability + blacklist gates run
            // in the controller (D-1/D-4), not as `exists` here.
            'creator_id' => ['required', 'string'],
            ...$this->assignmentOfferRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateOfferCurrencyAgainstCampaign($validator);
        });
    }
}
