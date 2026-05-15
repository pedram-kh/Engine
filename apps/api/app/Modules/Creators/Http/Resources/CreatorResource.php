<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Resources;

use App\Modules\Creators\Enums\WizardStep;
use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Features\CreatorPayoutMethodEnabled;
use App\Modules\Creators\Features\KycVerificationEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorKycVerification;
use App\Modules\Creators\Models\CreatorPortfolioItem;
use App\Modules\Creators\Models\CreatorSocialAccount;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Pennant\Feature;

/**
 * JSON representation of a Creator.
 *
 * Q2 (resume UX): the bootstrap response shape is stable between
 *
 *   GET /api/v1/creators/me                    (creator's own view)
 *   GET /api/v1/admin/creators/{creator}       (Chunk 3 admin view)
 *
 * — same resource, gated only by policy. The admin route additionally
 * surfaces the rejection_reason and the full kyc_verifications history
 * via {@see self::withAdmin()}. Chunk 3 tech-debt entry 4 closure: the
 * factory keeps the surface symmetric (one resource class, one toArray
 * shape) — adding fields per-audience would split into separate
 * resources, which we explicitly chose not to do.
 *
 * `wizard.flags` (Sprint 3 Chunk 3 sub-step 1 — pause-condition-7
 * closure): exposes the three Phase-1 creator-side feature-flag
 * states on every bootstrap so the wizard renders the documented
 * flag-OFF skip-paths without a second round-trip. The booleans
 * mirror the backend's `Feature::active()` checks 1:1, so a flag
 * flip on the operator side is immediately visible to the SPA on
 * the next bootstrap.
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
    /**
     * Toggled by {@see self::withAdmin()}. When true, {@see self::toArray()}
     * appends the `admin_attributes` block. When false (default), the
     * resource ships the creator-self view only.
     */
    private bool $isAdminView = false;

    public function __construct(
        Creator $resource,
        private readonly CompletenessScoreCalculator $calculator,
    ) {
        parent::__construct($resource);
    }

    /**
     * Fluent setter — toggle the admin-view flag and return `$this` so
     * controllers can chain in a single expression:
     *
     *   (new CreatorResource($creator, $calc))->withAdmin()->response();
     *
     * The admin route at GET /api/v1/admin/creators/{creator} (Chunk 3
     * sub-step 9) calls this; the creator-facing GET /api/v1/creators/me
     * does NOT. Closes Chunk 1 tech-debt entry 4 — one resource, two
     * gated audiences, symmetric shape.
     */
    public function withAdmin(bool $isAdminView = true): self
    {
        $this->isAdminView = $isAdminView;

        return $this;
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

        $payload = [
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
                'click_through_accepted_at' => $creator->click_through_accepted_at?->toIso8601String(),
                // Sprint 3 Chunk 3 sub-step 5: connected social accounts.
                // OAuth tokens are intentionally excluded — the SPA only
                // needs the public display fields (platform, handle,
                // profile_url) to render `SocialAccountList`. Sensitive
                // fields (encrypted via casts) never leave the backend.
                'social_accounts' => $this->mapSocialAccounts($creator),
                // Sprint 3 Chunk 3 sub-step 6: persisted portfolio items.
                // Storage paths are kept opaque — the SPA must request
                // signed view URLs via a future drill-in endpoint, not
                // construct them directly. Phase 1 ships the path
                // verbatim for the SPA to feed into a `<v-img>` via
                // the Filesystem disk's `url()` derivation.
                'portfolio' => $this->mapPortfolio($creator),
                'profile_completeness_score' => $creator->profile_completeness_score,
                'submitted_at' => $creator->submitted_at?->toIso8601String(),
                'approved_at' => $creator->approved_at?->toIso8601String(),
                'created_at' => $creator->created_at->toIso8601String(),
                'updated_at' => $creator->updated_at->toIso8601String(),
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
                'flags' => [
                    KycVerificationEnabled::NAME => Feature::active(KycVerificationEnabled::NAME),
                    CreatorPayoutMethodEnabled::NAME => Feature::active(CreatorPayoutMethodEnabled::NAME),
                    ContractSigningEnabled::NAME => Feature::active(ContractSigningEnabled::NAME),
                ],
            ],
        ];

        if ($this->isAdminView) {
            $payload['admin_attributes'] = [
                'rejection_reason' => $creator->rejection_reason,
                'rejected_at' => $creator->rejected_at?->toIso8601String(),
                'last_active_at' => $creator->last_active_at?->toIso8601String(),
                // Eager-loaded by the admin controller via with('kycVerifications')
                // — defensive ?? [] so a non-eager-loaded call doesn't blow up.
                // PII (decision_data, failure_reason) is NEVER surfaced here;
                // the admin drill-in lands at a separate endpoint in Sprint 4+
                // when the approval queue ships.
                'kyc_verifications' => $creator->kycVerifications
                    ->sortByDesc('started_at')
                    ->map(fn (CreatorKycVerification $v): array => [
                        'id' => $v->ulid,
                        'provider' => $v->provider,
                        'status' => $v->status->value,
                        'started_at' => $v->started_at?->toIso8601String(),
                        'completed_at' => $v->completed_at?->toIso8601String(),
                        'expires_at' => $v->expires_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $payload;
    }

    /**
     * Map the connected social accounts to the public summary shape.
     *
     * @return list<array<string, mixed>>
     */
    private function mapSocialAccounts(Creator $creator): array
    {
        $accounts = $creator->relationLoaded('socialAccounts')
            ? $creator->socialAccounts
            : $creator->socialAccounts()->get(['platform', 'handle', 'profile_url', 'is_primary']);

        // array_values() pins the list shape for Larastan — Eloquent's
        // Collection::all() returns array<TKey, TValue> regardless of
        // a preceding ->values() reindex, so PHPStan can't infer the
        // list invariant from the Collection chain alone.
        return array_values(
            $accounts
                ->map(fn (CreatorSocialAccount $account): array => [
                    'platform' => $account->platform->value,
                    'handle' => $account->handle,
                    'profile_url' => $account->profile_url,
                    'is_primary' => $account->is_primary,
                ])
                ->all(),
        );
    }

    /**
     * Map persisted portfolio items to the SPA-consumable summary shape.
     *
     * The `s3_path` and `thumbnail_path` fields are surfaced verbatim;
     * the SPA's `<PortfolioGallery>` component is expected to read them
     * via the Filesystem disk's `url()` helper (or a future signed-URL
     * drill-in endpoint when the assets move to private storage).
     *
     * @return list<array<string, mixed>>
     */
    private function mapPortfolio(Creator $creator): array
    {
        $items = $creator->relationLoaded('portfolioItems')
            ? $creator->portfolioItems
            : $creator->portfolioItems()->get();

        // array_values() pins the list shape for Larastan — see the
        // note on mapSocialAccounts() above.
        return array_values(
            $items
                ->map(fn (CreatorPortfolioItem $item): array => [
                    'id' => $item->ulid,
                    'kind' => $item->kind->value,
                    'title' => $item->title,
                    'description' => $item->description,
                    's3_path' => $item->s3_path,
                    'external_url' => $item->external_url,
                    'thumbnail_path' => $item->thumbnail_path,
                    'mime_type' => $item->mime_type,
                    'size_bytes' => $item->size_bytes,
                    'duration_seconds' => $item->duration_seconds,
                    'position' => $item->position,
                ])
                ->all(),
        );
    }
}
