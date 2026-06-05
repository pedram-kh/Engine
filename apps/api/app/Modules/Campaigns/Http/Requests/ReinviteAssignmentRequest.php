<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Requests;

use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates the agency's re-offer after a creator counter (Sprint 8 Chunk 2,
 * D-7). The verb-on-an-existing-assignment shape: the assignment is the route
 * binding, so (unlike the create-path invite) NO `creator_id` is needed.
 *
 * Same fee rules as the invite (D-8): a POSITIVE integer in minor units; the
 * currency must equal the campaign's `budget_currency` WHEN set.
 */
final class ReinviteAssignmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'agreed_fee_minor_units' => ['required', 'integer', 'min:1'],
            'agreed_fee_currency' => ['required', 'string', 'size:3'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $campaign = $this->route('campaign');
            if (! $campaign instanceof Campaign || $campaign->budget_currency === null) {
                return;
            }

            $currency = $this->input('agreed_fee_currency');
            if (is_string($currency) && strtoupper($currency) !== strtoupper($campaign->budget_currency)) {
                $validator->errors()->add(
                    'agreed_fee_currency',
                    "The fee currency must match the campaign currency ({$campaign->budget_currency}).",
                );
            }
        });
    }
}
