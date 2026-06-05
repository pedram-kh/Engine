<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Requests;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Models\Creator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates a creator's counter-offer (Sprint 8 Chunk 2, D-8) on the
 * creator-self route `POST creators/me/assignments/{assignment}/counter`.
 *
 * Same fee rules as the invite (D-8): a POSITIVE integer in minor units; the
 * currency must equal the campaign's single currency (`budget_currency`) WHEN
 * set. The assignment is resolved within the authenticated creator's OWN
 * assignments (scope-bypassed + `creator_id`) so the currency check can read
 * the campaign — ownership/fail-closed guards stay in the controller.
 */
final class CounterAssignmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'countered_fee_minor_units' => ['required', 'integer', 'min:1'],
            'countered_fee_currency' => ['required', 'string', 'size:3'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $currency = $this->input('countered_fee_currency');
            if (! is_string($currency)) {
                return;
            }

            $campaignCurrency = $this->resolveCampaignCurrency();
            if ($campaignCurrency !== null && strtoupper($currency) !== strtoupper($campaignCurrency)) {
                $validator->errors()->add(
                    'countered_fee_currency',
                    "The counter currency must match the campaign currency ({$campaignCurrency}).",
                );
            }
        });
    }

    private function resolveCampaignCurrency(): ?string
    {
        $user = $this->user();
        $creator = $user?->creator;
        if (! $creator instanceof Creator) {
            return null;
        }

        $ulid = $this->route('assignment');
        if (! is_string($ulid)) {
            return null;
        }

        $assignment = CampaignAssignment::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('creator_id', $creator->id)
            ->where('ulid', $ulid)
            ->with('campaign:id,budget_currency')
            ->first();

        return $assignment?->campaign?->budget_currency;
    }
}
