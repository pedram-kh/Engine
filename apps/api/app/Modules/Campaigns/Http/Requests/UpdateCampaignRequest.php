<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Requests;

use App\Modules\Campaigns\Enums\CampaignObjective;
use App\Modules\Campaigns\Enums\CampaignStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates the campaign Settings edit (Sprint 8 Chunk 1, D-8/D-10 — the
 * Settings tab, admin/manager gated in the controller). Partial PATCH — every
 * field is `sometimes`. `brand_id` / `agency_id` are NOT editable (a campaign
 * is anchored to its brand).
 */
final class UpdateCampaignRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'objective' => ['sometimes', new Enum(CampaignObjective::class)],
            'status' => ['sometimes', new Enum(CampaignStatus::class)],

            'budget_minor_units' => ['sometimes', 'integer', 'min:0'],
            'budget_currency' => ['sometimes', 'string', 'size:3'],

            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'posting_window_starts_at' => ['sometimes', 'nullable', 'date'],
            'posting_window_ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:posting_window_starts_at'],

            'target_creator_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'requires_per_campaign_contract' => ['sometimes', 'boolean'],

            'brief' => ['sometimes', 'nullable', 'array'],
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
}
