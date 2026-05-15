/**
 * Wire-contract types for the creator-domain API surfaces introduced
 * in Sprint 3. These mirror the backend JSON:API-flavoured resource
 * shapes verbatim (snake_case, ISO 8601 dates, ULID identifiers).
 *
 * Conventions follow `packages/api-client/src/types/user.ts`:
 *   - All keys snake_case matching backend `toArray()` output.
 *   - No re-casing; drift between FE and BE is loud and immediate.
 *   - Timestamps are `string` (ISO 8601 from Carbon).
 *   - Discriminator unions stay aligned with backend enums (the cases
 *     are pinned per chunk-3 sub-step 1; adding a case requires
 *     editing the matching PHP enum in the same commit).
 *
 * The resource shape is consumed by two surfaces (Q2 from chunk-1
 * review):
 *   - `apps/main` — the creator-self bootstrap at
 *     `GET /api/v1/creators/me`. The `admin_attributes` block is
 *     absent on this view.
 *   - `apps/admin` — the admin-read bootstrap at
 *     `GET /api/v1/admin/creators/{creator}` (Chunk 3 sub-step 9).
 *     The `admin_attributes` block IS present, populated by
 *     `CreatorResource::withAdmin()` on the backend.
 *
 * Tech-debt entry 4 closure (Chunk 1 → Chunk 3): one resource, two
 * audiences, symmetric shape. The TS-level optional `admin_attributes`
 * field carries the asymmetry without forcing two parallel types.
 */

// ---------------------------------------------------------------------------
// Enum mirrors — pin to the backend enum cases.
// ---------------------------------------------------------------------------

/**
 * Mirrors `App\Modules\Creators\Enums\ApplicationStatus`.
 */
export type CreatorApplicationStatus = 'incomplete' | 'pending' | 'approved' | 'rejected'

/**
 * Mirrors `App\Modules\Creators\Enums\KycStatus`. The `not_required`
 * case (Chunk 2 Q-flag-off-1) is stamped at submit time when the
 * `kyc_verification_enabled` flag is OFF, providing a forensic
 * marker distinct from `verified` and `none`.
 */
export type CreatorKycStatus = 'none' | 'pending' | 'verified' | 'rejected' | 'not_required'

/**
 * Mirrors `App\Modules\Creators\Enums\VerificationLevel`.
 */
export type CreatorVerificationLevel =
  | 'unverified'
  | 'email_verified'
  | 'kyc_verified'
  | 'tier_verified'

/**
 * Mirrors `App\Modules\Creators\Enums\WizardStep`. The step ordering
 * lives on the backend enum's `ordered()` static; do NOT re-derive
 * the order in the SPA — read `wizard.steps[]` from the bootstrap
 * response instead.
 */
export type CreatorWizardStepId =
  | 'profile'
  | 'social'
  | 'portfolio'
  | 'kyc'
  | 'tax'
  | 'payout'
  | 'contract'
  | 'review'

/**
 * Mirrors `App\Modules\Creators\Enums\SocialPlatform`.
 */
export type CreatorSocialPlatform = 'instagram' | 'tiktok' | 'youtube'

/**
 * Mirrors `App\Modules\Creators\Enums\TaxFormType`.
 */
export type CreatorTaxFormType =
  | 'eu_self_employed'
  | 'eu_company'
  | 'uk_self_employed'
  | 'uk_company'

/**
 * Mirrors `App\Modules\Creators\Enums\PortfolioItemKind`.
 */
export type CreatorPortfolioItemKind = 'video' | 'image' | 'link'

/**
 * Mirrors `App\Modules\Creators\Enums\KycVerificationStatus`.
 */
export type CreatorKycVerificationStatus = 'started' | 'pending' | 'passed' | 'failed' | 'expired'

// ---------------------------------------------------------------------------
// CreatorResource shape — `GET /api/v1/creators/me` + admin variant.
// ---------------------------------------------------------------------------

/**
 * The flat attribute map under `data.attributes` of the
 * `CreatorResource` bootstrap response.
 *
 * `bio` is markdown-source; the SPA renders it via `markdown-it` +
 * DOMPurify (Q-wizard-1 (a) for creator-supplied content).
 *
 * `click_through_accepted_at` and `has_signed_master_contract` are
 * the two ways the master-contract step can be satisfied; the
 * completeness calculator treats either as a hit (Chunk 2
 * Q-flag-off-2 = (a)).
 *
 * `updated_at` carries the "last activity" approximation used by the
 * Welcome Back UX (Chunk 3 Refinement 6 — captured separately as a
 * known approximation since `updated_at` only reflects DB writes,
 * not navigation; analytics-quality activity tracking is Sprint 6+
 * tech debt).
 */
export interface CreatorAttributes {
  display_name: string | null
  bio: string | null
  country_code: string | null
  region: string | null
  primary_language: string | null
  secondary_languages: string[] | null
  categories: string[] | null
  avatar_path: string | null
  cover_path: string | null
  verification_level: CreatorVerificationLevel
  application_status: CreatorApplicationStatus
  tier: string | null
  kyc_status: CreatorKycStatus
  kyc_verified_at: string | null
  tax_profile_complete: boolean
  payout_method_set: boolean
  has_signed_master_contract: boolean
  click_through_accepted_at: string | null
  /**
   * The creator's connected social accounts (Sprint 3 Chunk 3
   * sub-step 5). Only public-facing fields are surfaced — OAuth
   * tokens and audience demographics stay backend-side.
   */
  social_accounts: CreatorSocialAccountSummary[]
  /**
   * The creator's persisted portfolio items (Sprint 3 Chunk 3
   * sub-step 6). The Filesystem `url()` resolution lives in the SPA
   * — backend ships the storage path verbatim.
   */
  portfolio: CreatorPortfolioItemSummary[]
  profile_completeness_score: number
  submitted_at: string | null
  approved_at: string | null
  created_at: string
  updated_at: string
}

/**
 * Per-step completion booleans surfaced in `wizard.steps[]`. The
 * frontend uses these to render the progress indicator's per-step
 * status WITHOUT a round-trip per step (Q2 resume UX baseline).
 */
export interface CreatorWizardStepStatus {
  id: CreatorWizardStepId
  is_complete: boolean
}

/**
 * Pennant feature flag states surfaced on every bootstrap (Chunk 3
 * sub-step 1, closing pause-condition-7). The frontend uses these
 * to render documented flag-OFF skip-paths:
 *
 *   - `kyc_verification_enabled` OFF       → KYC step renders "Skipped"
 *   - `creator_payout_method_enabled` OFF  → Payout step renders "Skipped"
 *   - `contract_signing_enabled` OFF       → Contract step renders the
 *                                            click-through-accept UI
 *
 * The flag names match the backend's Pennant feature definitions
 * 1:1 (`App\Modules\Creators\Features\*Enabled::NAME`).
 */
export interface CreatorWizardFlags {
  kyc_verification_enabled: boolean
  creator_payout_method_enabled: boolean
  contract_signing_enabled: boolean
}

/**
 * The `wizard` envelope sub-block of `CreatorResource`. Drives the
 * onboarding wizard's progress indicator + auto-advance decision +
 * skip-path rendering.
 */
export interface CreatorWizardEnvelope {
  next_step: CreatorWizardStepId
  is_submitted: boolean
  steps: CreatorWizardStepStatus[]
  weights: Record<string, number>
  flags: CreatorWizardFlags
}

/**
 * Per-attempt KYC verification surfaced in `admin_attributes.kyc_verifications`.
 *
 * NEVER includes `decision_data` (encrypted PII) or `failure_reason`
 * (PII / sensitive operator detail). Sprint 4's approval queue may
 * expose a separate drill-in surface; Chunk 3 ships only the
 * lifecycle summary fields below.
 */
export interface CreatorKycVerificationSummary {
  id: string
  provider: string
  status: CreatorKycVerificationStatus
  started_at: string | null
  completed_at: string | null
  expires_at: string | null
}

/**
 * Admin-only attributes appended by `CreatorResource::withAdmin()`.
 * Absent on the creator-self bootstrap surface; present on the
 * admin-read bootstrap surface (Chunk 3 sub-step 9). Closes Chunk 1
 * tech-debt entry 4.
 */
export interface CreatorAdminAttributes {
  rejection_reason: string | null
  rejected_at: string | null
  last_active_at: string | null
  kyc_verifications: CreatorKycVerificationSummary[]
}

/**
 * The full `CreatorResource::toArray()` output. The
 * `admin_attributes` field is optional at the type level — present
 * on the admin GET, absent on the creator GET — so callers must
 * narrow before accessing it (the admin SPA narrows via the route
 * boundary; the main SPA never asks for the admin shape).
 */
export interface CreatorResource {
  id: string
  type: 'creators'
  attributes: CreatorAttributes
  wizard: CreatorWizardEnvelope
  admin_attributes?: CreatorAdminAttributes
}

export interface CreatorResourceEnvelope {
  data: CreatorResource
}

// ---------------------------------------------------------------------------
// Wizard-step request payloads.
// ---------------------------------------------------------------------------

export interface CreatorProfileUpdatePayload {
  display_name?: string
  bio?: string | null
  country_code?: string
  region?: string | null
  primary_language?: string
  secondary_languages?: string[]
  categories?: string[]
}

export interface CreatorSocialConnectPayload {
  platform: CreatorSocialPlatform
  handle: string
  profile_url: string
}

export interface CreatorTaxAddress {
  country_code: string
  city: string
  postal_code: string
  street: string
}

export interface CreatorTaxUpdatePayload {
  tax_form_type: CreatorTaxFormType
  legal_name: string
  tax_id: string
  address: CreatorTaxAddress
}

// ---------------------------------------------------------------------------
// Vendor-bounce responses (KYC / payout / contract initiate endpoints).
// ---------------------------------------------------------------------------

/**
 * Shared shape of all three vendor-bounce `initiate*` endpoints. The
 * SPA navigates the browser to `hosted_flow_url` via
 * `window.location.href` (NOT router push — vendor pages aren't part
 * of the SPA). Each endpoint returns its own session-identifier
 * field name (`session_id` / `account_id` / `envelope_id`) under
 * `data`; the SPA only reads `hosted_flow_url`-equivalent + cosmetic
 * `expires_at`.
 */
export interface KycInitiateResponse {
  data: {
    session_id: string
    hosted_flow_url: string
    expires_at: string
  }
}

export interface PayoutInitiateResponse {
  data: {
    account_id: string
    onboarding_url: string
    expires_at: string
  }
}

export interface ContractInitiateResponse {
  data: {
    envelope_id: string
    signing_url: string
    expires_at: string
  }
}

/**
 * Public summary of a connected social account.
 *
 * Mirrors `CreatorResource::toArray()['attributes']['social_accounts'][n]`
 * (Sprint 3 Chunk 3 sub-step 5). Sensitive fields (oauth_*,
 * audience_demographics, metrics) are NEVER surfaced on this shape.
 */
export interface CreatorSocialAccountSummary {
  platform: CreatorSocialPlatform
  handle: string
  profile_url: string
  is_primary: boolean
}

/**
 * Public summary of a persisted portfolio item.
 *
 * Mirrors `CreatorResource::toArray()['attributes']['portfolio'][n]`
 * (Sprint 3 Chunk 3 sub-step 6).
 *
 * For uploaded media, `s3_path` is populated; for link items,
 * `external_url` is populated and `s3_path` is null. The kind enum
 * disambiguates which is canonical.
 */
export interface CreatorPortfolioItemSummary {
  id: string
  kind: 'image' | 'video' | 'link'
  title: string | null
  description: string | null
  s3_path: string | null
  external_url: string | null
  thumbnail_path: string | null
  mime_type: string | null
  size_bytes: number | null
  duration_seconds: number | null
  position: number
}

// ---------------------------------------------------------------------------
// Wizard-completion status — shape returned by
// `GET /api/v1/creators/me/wizard/{step}/status` (Chunk 2 sub-step 6).
// ---------------------------------------------------------------------------

export interface WizardSagaStatusResponse {
  data: {
    /**
     * String form of the per-step terminal-state enum:
     *   - KYC      → 'none' | 'pending' | 'verified' | 'rejected' | 'expired'
     *   - contract → 'pending' | 'succeeded' | 'failed'
     *   - payout   → 'pending' | 'succeeded' | 'failed'
     */
    status: string
    /**
     * `true` when this poll caused the saga to transition into a
     * terminal state for the first time. Idempotent on re-poll
     * (subsequent polls return `transitioned: false`).
     */
    transitioned: boolean
  }
}

// ---------------------------------------------------------------------------
// Portfolio upload — direct-multipart for images, presigned-S3 for video.
// ---------------------------------------------------------------------------

/**
 * Initiate-video payload mirroring the backend at
 * `PortfolioController::initiateVideoUpload` (Chunk 1):
 *   - `mime_type` — MIME type of the video the SPA is about to upload.
 *   - `declared_bytes` — declared size; backend cross-checks the
 *     S3-object size on completion (defence against client-side
 *     size lying).
 */
export interface PortfolioVideoInitPayload {
  mime_type: string
  declared_bytes: number
}

export interface PortfolioVideoInitResponse {
  data: {
    upload_id: string
    upload_url: string
    storage_path: string
    expires_at: string
  }
}

/**
 * Complete-video payload mirroring the backend at
 * `PortfolioController::completeVideoUpload`. The `upload_id` is the
 * value returned from the init endpoint; the SPA includes the same
 * metadata it already validated client-side so the audit/persistence
 * row carries the full provenance.
 */
export interface PortfolioVideoCompletePayload {
  upload_id: string
  title?: string
  description?: string
  mime_type: string
  size_bytes: number
  duration_seconds?: number
}

export interface PortfolioItemSummary {
  id: string
  kind: CreatorPortfolioItemKind
  s3_path: string
  position: number
}

export interface PortfolioItemEnvelope {
  data: PortfolioItemSummary
}

export interface PortfolioItemResource {
  id: string
  type: 'creator_portfolio_items'
  attributes: {
    kind: CreatorPortfolioItemKind
    title: string
    description: string | null
    external_url: string | null
    storage_path: string | null
    thumbnail_path: string | null
    position: number
    created_at: string
  }
}

// ---------------------------------------------------------------------------
// Master contract terms — `GET /api/v1/creators/me/wizard/contract/terms`.
// ---------------------------------------------------------------------------

/**
 * Server-rendered contract terms surface (Chunk 3 sub-step 4,
 * Q-wizard-1 (c)). The backend renders the master contract markdown
 * to sanitised HTML via League CommonMark + an allowlist sanitiser;
 * the frontend renders the pre-sanitised HTML inside a bordered
 * scrollable region via `v-html`, with a docblock at the consumer
 * noting the trusted-content boundary.
 *
 * `version` is a content-hash or semver string identifying the
 * exact contract revision; useful for audit attribution when the
 * creator click-through-accepts. Phase 1 ships a single static
 * version (`v1`); Phase 2+ may version per-locale.
 */
export interface ContractTermsResource {
  data: {
    /** Pre-sanitised HTML rendered server-side from the master agreement markdown. */
    html: string
    /** Semantic version (e.g. "1.0"). Phase 1 ships a single version. */
    version: string
    /** Resolved locale (the backend falls back to `en` when the requested locale has no source file). */
    locale: string
  }
}
