/**
 * Module-scoped API surface for the creator-facing onboarding wizard
 * (Sprint 3 Chunk 3 sub-step 2, per Sprint 2 § 5.13).
 *
 * Endpoint prefix: `/api/v1/creators/me/*` (Chunk 1 backend).
 *
 * The wizard is a stateful collaboration with the backend: every step
 * submit is idempotent and the bootstrap response carries the
 * server's authoritative view of completion + flag state. The SPA
 * never derives "wizard state" locally — it re-reads from
 * `bootstrap()` after every state-flipping action.
 *
 * Vendor-bounce shape (kyc + payout + contract): the `initiate*`
 * endpoints return a `hosted_flow_url` the SPA must navigate to via
 * `window.location.href` (full-page nav, NOT router push — the
 * vendor's page is not part of the SPA). The `*Status` endpoints
 * poll the backend's view of vendor completion; the `*Return`
 * endpoints are the redirect-bounce targets the mock vendor lands
 * the creator on after completion. Both `status` and `return` go
 * through the same `WizardCompletionService` and emit the
 * `creator.wizard.{kind}_completed` audit on the success edge
 * (idempotent on re-poll, #6).
 *
 * Click-through-accept (contract step, flag-OFF): when
 * `contract_signing_enabled` is OFF the wizard renders the master
 * contract terms inline-scrollable + a checkbox + Continue button
 * (Decision E2=a). Continue fires `clickThroughAccept()` which
 * stamps `creators.click_through_accepted_at` (Chunk 2 Q-flag-off-2).
 */

import type {
  ContractInitiateResponse,
  ContractTermsResource,
  CreatorProfileUpdatePayload,
  CreatorResourceEnvelope,
  CreatorSocialConnectPayload,
  CreatorTaxUpdatePayload,
  KycInitiateResponse,
  PayoutInitiateResponse,
  PortfolioItemEnvelope,
  PortfolioVideoCompletePayload,
  PortfolioVideoInitPayload,
  PortfolioVideoInitResponse,
  WizardSagaStatusResponse,
} from '@catalyst/api-client'

import { http } from '@/core/api'

const BASE = '/creators/me'

export const onboardingApi = {
  // ── Bootstrap ────────────────────────────────────────────────────
  bootstrap(): Promise<CreatorResourceEnvelope> {
    return http.get<CreatorResourceEnvelope>(BASE)
  },

  // ── Profile (Step 2) ─────────────────────────────────────────────
  updateProfile(payload: CreatorProfileUpdatePayload): Promise<CreatorResourceEnvelope> {
    return http.patch<CreatorResourceEnvelope>(`${BASE}/wizard/profile`, payload)
  },

  // ── Social accounts (Step 3) ─────────────────────────────────────
  connectSocial(payload: CreatorSocialConnectPayload): Promise<CreatorResourceEnvelope> {
    return http.post<CreatorResourceEnvelope>(`${BASE}/wizard/social`, payload)
  },

  // ── KYC (Step 5) ─────────────────────────────────────────────────
  initiateKyc(): Promise<KycInitiateResponse> {
    return http.post<KycInitiateResponse>(`${BASE}/wizard/kyc`)
  },

  pollKycStatus(): Promise<WizardSagaStatusResponse> {
    return http.get<WizardSagaStatusResponse>(`${BASE}/wizard/kyc/status`)
  },

  // ── Tax (Step 6) ─────────────────────────────────────────────────
  updateTax(payload: CreatorTaxUpdatePayload): Promise<CreatorResourceEnvelope> {
    return http.patch<CreatorResourceEnvelope>(`${BASE}/wizard/tax`, payload)
  },

  // ── Payout (Step 7) ──────────────────────────────────────────────
  initiatePayout(): Promise<PayoutInitiateResponse> {
    return http.post<PayoutInitiateResponse>(`${BASE}/wizard/payout`)
  },

  pollPayoutStatus(): Promise<WizardSagaStatusResponse> {
    return http.get<WizardSagaStatusResponse>(`${BASE}/wizard/payout/status`)
  },

  // ── Contract (Step 8) ────────────────────────────────────────────
  initiateContract(): Promise<ContractInitiateResponse> {
    return http.post<ContractInitiateResponse>(`${BASE}/wizard/contract`)
  },

  pollContractStatus(): Promise<WizardSagaStatusResponse> {
    return http.get<WizardSagaStatusResponse>(`${BASE}/wizard/contract/status`)
  },

  clickThroughAccept(): Promise<CreatorResourceEnvelope> {
    return http.post<CreatorResourceEnvelope>(`${BASE}/wizard/contract/click-through-accept`)
  },

  /**
   * Server-rendered master contract terms (Chunk 3 sub-step 4,
   * Q-wizard-1 (c)). Returns sanitised HTML + a version identifier.
   * Both the flag-ON envelope flow (mock-vendor / real-vendor page)
   * and the flag-OFF click-through region source from this endpoint.
   */
  getContractTerms(): Promise<ContractTermsResource> {
    return http.get<ContractTermsResource>(`${BASE}/wizard/contract/terms`)
  },

  // ── Submit (Step 9) ──────────────────────────────────────────────
  submit(): Promise<CreatorResourceEnvelope> {
    return http.post<CreatorResourceEnvelope>(`${BASE}/wizard/submit`)
  },

  // ── Avatar (Step 2 sub-element) ──────────────────────────────────
  /**
   * Direct-multipart upload — 5 MB cap enforced backend-side; the
   * client validates shape pre-flight (`useAvatarUpload`).
   */
  uploadAvatar(file: File): Promise<CreatorResourceEnvelope> {
    const form = new FormData()
    form.append('avatar', file)
    return http.post<CreatorResourceEnvelope>(`${BASE}/avatar`, form)
  },

  deleteAvatar(): Promise<CreatorResourceEnvelope> {
    return http.delete<CreatorResourceEnvelope>(`${BASE}/avatar`)
  },

  // ── Portfolio (Step 4) — image direct-multipart, video presigned ─

  /**
   * Direct-multipart image upload. The backend (Intervention/Image)
   * resizes + re-encodes server-side; the SPA validates MIME +
   * size pre-flight via `usePortfolioUpload.validate()`.
   */
  uploadPortfolioImage(
    file: File,
    meta: { title?: string; description?: string } = {},
  ): Promise<PortfolioItemEnvelope> {
    const form = new FormData()
    form.append('file', file)
    if (meta.title !== undefined) form.append('title', meta.title)
    if (meta.description !== undefined) form.append('description', meta.description)
    return http.post<PortfolioItemEnvelope>(`${BASE}/portfolio/images`, form)
  },

  initiatePortfolioVideoUpload(
    payload: PortfolioVideoInitPayload,
  ): Promise<PortfolioVideoInitResponse> {
    return http.post<PortfolioVideoInitResponse>(`${BASE}/portfolio/videos/init`, payload)
  },

  completePortfolioVideoUpload(
    payload: PortfolioVideoCompletePayload,
  ): Promise<PortfolioItemEnvelope> {
    return http.post<PortfolioItemEnvelope>(`${BASE}/portfolio/videos/complete`, payload)
  },

  deletePortfolioItem(itemUlid: string): Promise<void> {
    return http.delete<void>(`${BASE}/portfolio/${itemUlid}`)
  },
}
