<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Requests;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Enums\CampaignObjective;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates campaign creation (Sprint 8 Chunk 1, D-8). Role authorization
 * lives in the controller via Gate::authorize (the house pattern).
 *
 * `brand_id` is the brand's PUBLIC ULID; it must belong to the path agency
 * (the `exists` scope is the tenancy guard). Money is integer minor units
 * (D-3) + an ISO-4217 currency. The structured `brief` sub-fields land in the
 * `brief` jsonb blob (NOT normalized tables).
 *
 * `objective` is OPTIONAL at the edge and defaults to `ugc` when absent
 * (campaign-form simplification, D-1) — {@see prepareForValidation()}. The
 * contract only RELAXES: an explicit valid objective in the payload still
 * validates against the enum and is honored.
 */
final class CreateCampaignRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $agency = $this->route('agency');
        $agencyId = $agency instanceof Agency ? $agency->id : null;

        return [
            'brand_id' => [
                'required',
                'string',
                Rule::exists('brands', 'ulid')->where('agency_id', $agencyId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'objective' => ['sometimes', new Enum(CampaignObjective::class)],

            // Money — integer minor units (D-3) + ISO-4217 currency.
            'budget_minor_units' => ['required', 'integer', 'min:0'],
            'budget_currency' => ['required', 'string', 'size:3'],

            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'posting_window_starts_at' => ['nullable', 'date'],
            'posting_window_ends_at' => ['nullable', 'date', 'after_or_equal:posting_window_starts_at'],

            'target_creator_count' => ['nullable', 'integer', 'min:0'],
            'requires_per_campaign_contract' => ['sometimes', 'boolean'],

            // Structured brief blob → jsonb. Sub-fields are validated loosely.
            'brief' => ['nullable', 'array'],
            'brief.deliverables' => ['sometimes', 'array'],
            'brief.dos' => ['sometimes', 'array'],
            'brief.donts' => ['sometimes', 'array'],
            'brief.hashtags' => ['sometimes', 'array'],
            'brief.mentions' => ['sometimes', 'array'],
            'brief.links' => ['sometimes', 'array'],
            'brief.usage_rights' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'brief.attachments' => ['sometimes', 'array'],
        ];
    }

    /**
     * Default a missing `objective` to `ugc` BEFORE validation, so the enum
     * rule sees a valid value and the controller persists it uniformly (no
     * null-coalesce at the write site). An explicit objective is untouched.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('objective') || $this->input('objective') === null || $this->input('objective') === '') {
            $this->merge(['objective' => CampaignObjective::Ugc->value]);
        }
    }
}
