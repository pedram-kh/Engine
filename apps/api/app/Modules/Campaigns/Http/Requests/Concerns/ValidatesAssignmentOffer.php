<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Requests\Concerns;

use App\Modules\Campaigns\Http\Requests\AcceptApplicationRequest;
use App\Modules\Campaigns\Http\Requests\InviteAssignmentRequest;
use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Validation\Validator;

/**
 * The OFFER half of an invite payload, shared by the two requests that carry one
 * (AH-058 S4).
 *
 * {@see InviteAssignmentRequest} adds `creator_id` (the agency picks the
 * creator); {@see AcceptApplicationRequest} adds nothing (the applicant is the
 * application's own creator). Everything else about an offer — the fee and its
 * currency, the free-text context, the one optional attachment, the deliverables
 * and the two deadlines — is identical, and it is identical because both paths
 * write the same columns through the same service.
 *
 * Extracted rather than copied for the reason the FE dialogs were refactored the
 * same way: duplicated fee/currency/attachment validation is the version that
 * diverges. A rule tightened on the invite path and forgotten on the accept path
 * would be a hole with no test to notice it, because each path's tests would
 * still pass.
 */
trait ValidatesAssignmentOffer
{
    /**
     * @return array<string, mixed>
     */
    protected function assignmentOfferRules(): array
    {
        return [
            // Fee validation (D-8): a POSITIVE integer in minor units; the
            // currency must equal the campaign's single currency when that is
            // set. NOT constrained to the campaign budget — per-assignment vs
            // budget tracking is a deferred business concern, not a rule.
            'agreed_fee_minor_units' => ['required', 'integer', 'min:1'],
            'agreed_fee_currency' => ['required', 'string', 'size:3'],

            // Invite-offer-details batch — free-text offer context, all optional.
            // `fee_per` is the unit the fee applies to ("per script");
            // agency-authored content, deliberately not an enum.
            'fee_per' => ['nullable', 'string', 'max:120'],
            'offer_description' => ['nullable', 'string', 'max:2000'],

            // ONE optional offer attachment, uploaded via the presigned
            // init/complete pair BEFORE the invite; the upload_id is re-verified
            // against the campaign prefix in the controller (isolation backstop).
            'attachment' => ['nullable', 'array'],
            'attachment.upload_id' => ['required_with:attachment', 'string', 'max:500'],
            'attachment.name' => ['required_with:attachment', 'string', 'max:255'],
            'attachment.mime_type' => ['required_with:attachment', 'string', 'max:120'],
            'attachment.size_bytes' => ['required_with:attachment', 'integer', 'min:1'],

            'deliverables' => ['nullable', 'array'],
            'posting_due_at' => ['nullable', 'date'],
            // Sprint 12 Chunk 3 (D-2) — the draft deadline, an exact mirror of
            // posting_due_at. Nullable, so draft_overdue is inert until set.
            'draft_due_at' => ['nullable', 'date'],

            // The soft-warn protocol flag (D-2): re-submit with
            // `acknowledged: true` to proceed past a hard AVAILABILITY conflict
            // (a 409). It has NO bearing on the blacklist hard block (422).
            'acknowledged' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The campaign-currency cross-check. Reads the route's campaign, so both
     * requests get it by virtue of being nested under the campaign.
     */
    protected function validateOfferCurrencyAgainstCampaign(Validator $validator): void
    {
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
    }
}
