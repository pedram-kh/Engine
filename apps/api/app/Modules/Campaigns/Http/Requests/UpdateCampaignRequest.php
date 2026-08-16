<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Requests;

use App\Modules\Campaigns\Enums\CampaignObjective;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Http\Requests\Concerns\ValidatesJobsBoardListing;
use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates the campaign Settings edit (Sprint 8 Chunk 1, D-8/D-10 — the
 * Settings tab, admin/manager gated in the controller). Partial PATCH — every
 * field is `sometimes`. `brand_id` / `agency_id` are NOT editable (a campaign
 * is anchored to its brand).
 *
 * Jobs board (AH-054): this is the ONLY request that accepts
 * `listed_on_jobs_board` (D4 — listing is an edit-time act). Two cross-field
 * rules guard it, and they are deliberately different shapes:
 *
 *   - **D3, completeness — a RESULTING-STATE rule.** Any write that leaves the
 *     campaign listed must leave it complete. Gating on the false→true
 *     transition alone would let an agency list a full job and then gut it —
 *     the Settings form re-sends the whole payload on every save, so clearing
 *     the description of a listed campaign is one keystroke away. Judging the
 *     result closes that hole and needs no extra client cooperation.
 *   - **D5, terminal status — a TRANSITION rule.** Switching the flag ON is
 *     refused while the campaign is `completed` / `cancelled`. It is NOT a
 *     resulting-state rule, because D5 also rules that moving a listed
 *     campaign to a terminal status must be allowed and must leave the flag
 *     untouched (no auto-clear; the flag is stored intent, and
 *     {@see Campaign::scopeListedOnJobsBoard()} enforces invisibility at read
 *     time). A resulting-state rule here would block that status change
 *     outright — the two rules must not be collapsed into one.
 */
final class UpdateCampaignRequest extends FormRequest
{
    use ValidatesJobsBoardListing;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->listingFieldRules(),

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

            // AH-069 D1 — editable on the Settings tab. The flip-to-OFF refusal
            // while cards sit in Posted is enforced in the controller (D6), not
            // here: it needs the board, and a FormRequest is the wrong place to
            // reach for one.
            'creator_posts_content' => ['sometimes', 'boolean'],

            'listed_on_jobs_board' => ['sometimes', 'boolean'],

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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $campaign = $this->route('campaign');
            if (! $campaign instanceof Campaign) {
                return;
            }

            $resultingListed = $this->has('listed_on_jobs_board')
                ? $this->boolean('listed_on_jobs_board')
                : $campaign->listed_on_jobs_board;

            // Toggling OFF (or staying off) never gates — D3/D5 exist to stop a
            // bad listing going live, not to trap an agency into keeping one.
            if (! $resultingListed) {
                return;
            }

            // D5 — the switch cannot be turned ON for a terminal campaign. A
            // campaign that was ALREADY listed when it ended keeps its flag and
            // stays editable; only the false→true transition is refused.
            $resultingStatus = is_string($this->input('status'))
                ? $this->input('status')
                : $campaign->status->value;

            if (! $campaign->listed_on_jobs_board
                && ! in_array($resultingStatus, Campaign::LISTABLE_STATUSES, true)) {
                $validator->errors()->add(
                    'listed_on_jobs_board',
                    "A {$resultingStatus} campaign cannot be added to the jobs board.",
                );

                // Stop here: piling the floor errors on top would bury the real
                // reason behind five field-level messages.
                return;
            }

            // D3 — a listed job is never half-empty. Every missing floor field
            // is named, so the SPA can bind them all in one round-trip.
            foreach ($this->missingListingFloorFields($campaign) as $field) {
                $validator->errors()->add(
                    $field,
                    'This field is required before the campaign can be added to the jobs board.',
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeListingRegions();
    }
}
