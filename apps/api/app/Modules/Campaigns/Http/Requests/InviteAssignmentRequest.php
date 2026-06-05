<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Requests;

use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates a single creator invite (Sprint 8 Chunk 2, D-3/D-8). Role
 * authorization (the `invite` execute ability) lives in the controller via
 * Gate::authorize (the house pattern).
 *
 * Fee validation (D-8): a POSITIVE integer in minor units; the currency must
 * equal the campaign's single currency (`budget_currency`) WHEN that is set
 * (it is nullable). NOT constrained to the campaign budget — per-assignment-
 * vs-budget tracking is a deferred business concern, not a validation rule.
 *
 * `acknowledged` is the soft-warn protocol flag (D-2): the agency re-submits
 * with `acknowledged: true` to proceed past a hard AVAILABILITY conflict (a
 * 409). It has NO bearing on the blacklist hard block (422).
 */
final class InviteAssignmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The creator's PUBLIC ULID; discoverability + blacklist gates run
            // in the controller (D-1/D-4), not as `exists` here.
            'creator_id' => ['required', 'string'],

            'agreed_fee_minor_units' => ['required', 'integer', 'min:1'],
            'agreed_fee_currency' => ['required', 'string', 'size:3'],

            'deliverables' => ['nullable', 'array'],
            'posting_due_at' => ['nullable', 'date'],

            'acknowledged' => ['sometimes', 'boolean'],
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
