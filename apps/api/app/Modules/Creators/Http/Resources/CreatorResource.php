<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Resources;

use App\Modules\Creators\Enums\WizardStep;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of a Creator.
 *
 * Q2 (resume UX): the bootstrap response shape is stable between
 *
 *   GET /api/v1/creators/me                    (creator's own view)
 *   GET /api/v1/admin/creators/{creator}       (Chunk 3, admin view)
 *
 * — same resource, gated only by policy. The admin route additionally
 * surfaces the rejection_reason and the full kyc_verifications history;
 * those fields are appended via the `withAdmin()` factory method when
 * Chunk 3 builds out admin endpoints.
 *
 * Sensitive fields NEVER returned by this resource:
 *   - tax_id, legal_name, address  (encrypted PII; admin drill-in only)
 *   - oauth tokens                 (encrypted; never user-facing)
 *   - account_id (Stripe acct_*)   (admin drill-in only)
 *
 * @mixin Creator
 */
final class CreatorResource extends JsonResource
{
    public function __construct(
        Creator $resource,
        private readonly CompletenessScoreCalculator $calculator,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $creator = $this->resource;
        assert($creator instanceof Creator);

        // Q2 (resume UX): per-step status keyed by WizardStep::value
        // (string identifier) — robust to future re-ordering.
        $stepCompletion = $this->calculator->stepCompletion($creator);
        $nextStep = $this->calculator->nextStep($creator);

        return [
            'id' => $creator->ulid,
            'type' => 'creators',
            'attributes' => [
                'display_name' => $creator->display_name,
                'bio' => $creator->bio,
                'country_code' => $creator->country_code,
                'region' => $creator->region,
                'primary_language' => $creator->primary_language,
                'secondary_languages' => $creator->secondary_languages,
                'categories' => $creator->categories,
                'avatar_path' => $creator->avatar_path,
                'cover_path' => $creator->cover_path,
                'verification_level' => $creator->verification_level->value,
                'application_status' => $creator->application_status->value,
                'tier' => $creator->tier,
                'kyc_status' => $creator->kyc_status->value,
                'kyc_verified_at' => $creator->kyc_verified_at?->toIso8601String(),
                'tax_profile_complete' => $creator->tax_profile_complete,
                'payout_method_set' => $creator->payout_method_set,
                'has_signed_master_contract' => $creator->signed_master_contract_id !== null,
                'profile_completeness_score' => $creator->profile_completeness_score,
                'submitted_at' => $creator->submitted_at?->toIso8601String(),
                'approved_at' => $creator->approved_at?->toIso8601String(),
                'created_at' => $creator->created_at->toIso8601String(),
            ],
            'wizard' => [
                'next_step' => $nextStep->value,
                'is_submitted' => $creator->submitted_at !== null,
                'steps' => array_map(
                    fn (WizardStep $step): array => [
                        'id' => $step->value,
                        'is_complete' => $stepCompletion[$step->value] ?? false,
                    ],
                    array_filter(
                        WizardStep::ordered(),
                        fn (WizardStep $s): bool => $s !== WizardStep::Review,
                    ),
                ),
                'weights' => $this->calculator->weights(),
            ],
        ];
    }
}
