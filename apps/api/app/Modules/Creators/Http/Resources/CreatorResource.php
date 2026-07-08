<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Resources;

use App\Modules\Creators\Enums\WizardStep;
use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Features\CreatorPayoutMethodEnabled;
use App\Modules\Creators\Features\KycVerificationEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorKycVerification;
use App\Modules\Creators\Models\CreatorSocialAccount;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use App\Modules\Creators\Services\PortfolioUploadService;
use App\Modules\Creators\Support\PortfolioItemPresenter;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
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
     * TTL for the signed view URLs we mint on every bootstrap. 60 minutes
     * is comfortably longer than a typical wizard sitting (median ~12 min
     * across Sprint 3 telemetry) and short enough that a leaked URL stops
     * working before it can travel. The SPA refetches `/creators/me` on
     * every wizard hydrate / dashboard mount, so the URLs are renewed on
     * each navigation — clients never hold a single URL past its expiry
     * in normal flows.
     */
    private const int SIGNED_URL_TTL_MINUTES = 60;

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
                // AH-005 — optional contact details. These live in the BASE
                // (owner-self) attributes because the creator must read their
                // own values to hydrate the wizard's Contact-details form. The
                // admin inherits them through the same resource (admin view-only,
                // D6 — NOT added to admin_attributes, NOT to EDITABLE_FIELDS).
                // Agencies NEVER reach this resource — they read the gated
                // AgencyCreatorDetailResource — so this is not an agency surface.
                'phone' => $creator->phone,
                'whatsapp' => $creator->whatsapp,
                'address_street' => $creator->address_street,
                'address_postal_code' => $creator->address_postal_code,
                'primary_language' => $creator->primary_language,
                'secondary_languages' => $creator->secondary_languages,
                'accent' => $creator->accent,
                'categories' => $creator->categories,
                'avatar_path' => $creator->avatar_path,
                'cover_path' => $creator->cover_path,
                // Signed view URLs for the private `media` disk. Phase 1
                // (Sprint 3 stabilization): backend mints a presigned GET
                // URL on every bootstrap so the SPA's <img src> can fetch
                // the asset directly. The `_path` fields are kept for one
                // sprint as the old contract; the SPA reads `_url` only.
                // Null when the underlying path is null OR when the disk
                // is non-S3 (test fakes via `Storage::fake('media')` use
                // the local driver, which doesn't support temporaryUrl).
                'avatar_url' => $this->signedViewUrl($creator->avatar_path),
                'cover_url' => $this->signedViewUrl($creator->cover_path),
                'verification_level' => $creator->verification_level->value,
                'application_status' => $creator->application_status->value,
                'tier' => $creator->tier,
                'kyc_status' => $creator->kyc_status->value,
                'kyc_verified_at' => $creator->kyc_verified_at?->toIso8601String(),
                // Sprint 4 Chunk 3 (D-c3-1, Cluster 5): the rejection
                // feedback is surfaced on the creator-facing payload (not
                // just admin_attributes) so CreatorDashboardPage's rejected
                // banner can render the reason as editing guidance. Null
                // unless the application has been rejected.
                'rejection_reason' => $creator->rejection_reason,
                'rejected_at' => $creator->rejected_at?->toIso8601String(),
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
                // Each entry carries both the raw storage path (`s3_path`,
                // `thumbnail_path`) and a freshly-minted signed view URL
                // (`view_url`, `thumbnail_view_url`) so the SPA can render
                // <img> directly against the private `media` disk without
                // a second drill-in round trip. See {@see self::mapPortfolio()}.
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
                // Only the VISIBLE substantive steps are surfaced. Build-time
                // hidden steps (WizardStep::WIZARD_HIDDEN_STEPS, ad-hoc AH-003)
                // are excluded here so the SPA never renders, numbers, or gates
                // on them; Review is the submit action, not a step row.
                'steps' => array_map(
                    fn (WizardStep $step): array => [
                        'id' => $step->value,
                        'is_complete' => $stepCompletion[$step->value] ?? false,
                    ],
                    array_values(array_filter(
                        WizardStep::visibleOrdered(),
                        fn (WizardStep $s): bool => $s !== WizardStep::Review,
                    )),
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
                // The creator's account email — admin-only PII surfaced so
                // reviewers can identify / contact the applicant. Lives on
                // the related User; eager-loaded by the admin controller.
                'email' => $creator->user?->email,
                'rejection_reason' => $creator->rejection_reason,
                'rejected_at' => $creator->rejected_at?->toIso8601String(),
                'last_active_at' => $creator->last_active_at?->toIso8601String(),
                // Sprint 4 Chunk 3 (Cluster 1/4): the KYC-method
                // discriminator + manual-verify attribution + whether a
                // real vendor adapter is wired. kyc_vendor_available drives
                // the admin SPA's "Request vendor verification" disabled
                // affordance (D-c3-6/D-NEW-2): true only when the KYC flag
                // is ON AND a real (non-mock) vendor driver is configured —
                // false today (kyc_verification_enabled OFF, realDrivers []).
                'kyc_method' => $creator->kyc_method?->value,
                'verified_by_user_id' => $creator->verified_by_user_id,
                'kyc_vendor_available' => Feature::active(KycVerificationEnabled::NAME)
                    && ! in_array((string) config('integrations.kyc.driver'), ['', 'mock'], true),
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
     * Each entry exposes both the raw storage path and a freshly-minted
     * signed view URL. Link items (kind=link) have no S3 path; their URL
     * fields stay null and the SPA renders `external_url` instead.
     *
     * @return list<array<string, mixed>>
     */
    private function mapPortfolio(Creator $creator): array
    {
        // AH-004: routed through the shared presenter so the server-authoritative
        // `ready`-gate (withhold signed URLs for processing/failed items) lives
        // in one place across all portfolio surfaces.
        return (new PortfolioItemPresenter)->mapForCreator($creator);
    }

    /**
     * Mint a presigned GET URL against the private `media` disk.
     *
     * Returns null when:
     *   - the path is null (no asset persisted yet), OR
     *   - the disk's underlying adapter is not S3-compatible. This
     *     happens in tests that call `Storage::fake('media')` — the
     *     fake uses the local-filesystem driver, which throws on
     *     `temporaryUrl()`. Mirrors the guard pattern already used in
     *     {@see PortfolioUploadService::initiatePresignedUpload()}.
     */
    private function signedViewUrl(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $disk = Storage::disk('media');
        if (! $disk instanceof AwsS3V3Adapter) {
            return null;
        }

        return $disk->temporaryUrl($path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
    }
}
