/**
 * Admin-side API surface for the Creator drill-in (Sprint 3 Chunk 3
 * sub-step 9).
 *
 * Endpoint: GET /api/v1/admin/creators/{ulid}
 *
 * The response shape reuses `CreatorResource` with the
 * `admin_attributes` block appended (rejection_reason +
 * kyc_verifications history; PII like decision_data /
 * failure_reason is NEVER surfaced — admin drill-in for those
 * fields ships in Sprint 4+).
 *
 * Authentication: implicit via the admin SPA's `auth:web_admin`
 * session cookie set by UseAdminSessionCookie. The backend
 * additionally gates this route with EnsureMfaForAdmins.
 */

import type {
  CreatorApplicationStatus,
  CreatorKycStatus,
  CreatorResourceEnvelope,
} from '@catalyst/api-client'

import { http } from '@/core/api'

/**
 * A single row in the admin review queue (GET /admin/creators). Slim
 * list-card shape — the full drill-in lives at the show route.
 */
export interface AdminCreatorListItem {
  id: string
  type: 'creators'
  attributes: {
    display_name: string | null
    email: string | null
    application_status: CreatorApplicationStatus
    kyc_status: CreatorKycStatus
    profile_completeness_score: number
    submitted_at: string | null
    created_at: string
  }
}

export interface AdminCreatorListResponse {
  data: AdminCreatorListItem[]
  meta: {
    total: number
    page: number
    per_page: number
    last_page: number
  }
}

export interface AdminCreatorListParams {
  status?: CreatorApplicationStatus
  kyc_status?: CreatorKycStatus
  page?: number
  per_page?: number
}

/**
 * A single read-only assignment-history row (Sprint 13, D-4). Payment
 * columns are deliberately absent — the creator-detail payment section is
 * a discrete coming-soon block (D-13) lit up in S10.
 */
export interface AdminCreatorAssignment {
  id: string
  type: 'campaign_assignments'
  attributes: {
    status: string
    campaign_name: string | null
    brand_name: string | null
    agency_name: string | null
    invited_at: string | null
    accepted_at: string | null
    posted_at: string | null
    created_at: string
  }
}

/** A single per-creator audit-trail row (Sprint 13, D-4). */
export interface AdminCreatorAuditLog {
  id: string
  type: 'audit_logs'
  attributes: {
    action: string
    actor_name: string | null
    actor_email: string | null
    reason: string | null
    created_at: string
  }
}

export interface AdminCreatorAssignmentResponse {
  data: AdminCreatorAssignment[]
  meta: { total: number; page: number; per_page: number; last_page: number }
}

export interface AdminCreatorAuditLogResponse {
  data: AdminCreatorAuditLog[]
  meta: { total: number; page: number; per_page: number; last_page: number }
}

export interface AdminCreatorHistoryParams {
  page?: number
  per_page?: number
}

/**
 * Editable fields exposed by the admin per-field PATCH endpoint
 * (Sprint 3 Chunk 4 sub-step 1 — `AdminUpdateCreatorRequest::EDITABLE_FIELDS`).
 * `application_status` is intentionally absent: the generic PATCH
 * refuses it; status transitions land via dedicated approve / reject
 * endpoints (Decision E2=b, sub-step 2).
 */
export type AdminEditableField =
  | 'display_name'
  | 'bio'
  | 'country_code'
  | 'region'
  | 'primary_language'
  | 'secondary_languages'
  | 'accent'
  | 'categories'

/**
 * Backend-mirrored list of fields that require a free-text `reason`
 * audit-metadata entry (Sprint 3 Chunk 4 sub-step 1 —
 * `AdminUpdateCreatorRequest::REASON_REQUIRED_FIELDS`). The UI
 * pre-validates against this list per Q-chunk-4-3 = (b); the
 * backend re-validates as the SOT.
 */
export const ADMIN_REASON_REQUIRED_FIELDS: ReadonlyArray<AdminEditableField> = ['bio', 'categories']

export const adminCreatorsApi = {
  /**
   * Fetch the review queue — Sprint 4 Chunk 3 (Cluster 3) backend
   * GET /api/v1/admin/creators. platform_admin-gated, filterable by
   * application_status, paginated. Returns the list-card shape.
   */
  list(params: AdminCreatorListParams = {}): Promise<AdminCreatorListResponse> {
    const query = new URLSearchParams()
    if (params.status !== undefined) query.set('status', params.status)
    if (params.kyc_status !== undefined) query.set('kyc_status', params.kyc_status)
    if (params.page !== undefined) query.set('page', String(params.page))
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    const qs = query.toString()
    return http.get<AdminCreatorListResponse>(`/admin/creators${qs === '' ? '' : `?${qs}`}`)
  },

  /**
   * Read-only assignment history for a creator (Sprint 13, D-4),
   * GET /admin/creators/{ulid}/assignments. Cross-agency; payment-free.
   */
  assignments(
    creatorUlid: string,
    params: AdminCreatorHistoryParams = {},
  ): Promise<AdminCreatorAssignmentResponse> {
    const query = new URLSearchParams()
    if (params.page !== undefined) query.set('page', String(params.page))
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    const qs = query.toString()
    return http.get<AdminCreatorAssignmentResponse>(
      `/admin/creators/${creatorUlid}/assignments${qs === '' ? '' : `?${qs}`}`,
    )
  },

  /**
   * Per-creator audit trail (Sprint 13, D-4),
   * GET /admin/creators/{ulid}/audit-logs. Reads the append-only
   * `audit_logs` rows whose subject is this creator.
   */
  auditLogs(
    creatorUlid: string,
    params: AdminCreatorHistoryParams = {},
  ): Promise<AdminCreatorAuditLogResponse> {
    const query = new URLSearchParams()
    if (params.page !== undefined) query.set('page', String(params.page))
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    const qs = query.toString()
    return http.get<AdminCreatorAuditLogResponse>(
      `/admin/creators/${creatorUlid}/audit-logs${qs === '' ? '' : `?${qs}`}`,
    )
  },

  /**
   * Fetch a single Creator by its ULID. Surfaces the
   * `admin_attributes` block on the response (via the backend's
   * `CreatorResource::withAdmin()`). Returns the standard
   * `CreatorResourceEnvelope` shape; the SPA reads the
   * `admin_attributes` block via `as` narrowing where needed.
   */
  show(creatorUlid: string): Promise<CreatorResourceEnvelope> {
    return http.get<CreatorResourceEnvelope>(`/admin/creators/${creatorUlid}`)
  },

  /**
   * Update a single creator field via the admin per-field PATCH.
   *
   * Sprint 3 Chunk 4 sub-step 9 (consumes the sub-step 1 backend).
   * The backend enforces one-field-at-a-time + the optional `reason`
   * audit-metadata field. The frontend mirrors the same rule to
   * surface the requirement upfront rather than via a 422 round-trip.
   *
   * The response is a fresh `CreatorResource` envelope (the backend
   * re-renders the resource with `withAdmin(true)`).
   *
   * Idempotency: same-value updates are 200 OK no-ops at the backend
   * (no audit emitted; no `updated_at` re-stamp). The frontend treats
   * them as success and refreshes the store regardless — the UI
   * doesn't need to distinguish.
   */
  updateField(
    creatorUlid: string,
    field: AdminEditableField,
    value: unknown,
    reason: string | null = null,
  ): Promise<CreatorResourceEnvelope> {
    const payload: Record<string, unknown> = { [field]: value }
    if (reason !== null && reason.trim() !== '') {
      payload.reason = reason.trim()
    }
    return http.patch<CreatorResourceEnvelope>(`/admin/creators/${creatorUlid}`, payload)
  },

  /**
   * Approve a creator's application — Sprint 3 Chunk 4 sub-step 2 backend.
   *
   * Dedicated approve workflow per Decision E2=b. `welcome_message` is
   * optional; backend persists it to `creators.welcome_message`. Returns
   * a fresh `CreatorResource` envelope on 200 OK. Calling approve on a
   * creator that's already approved is 409 + `creator.already_approved`
   * (idempotency-rule #6 closure). The admin SPA surfaces the error
   * code via `useErrorMessage` and treats it as a non-destructive
   * "already done" state.
   */
  approve(
    creatorUlid: string,
    welcomeMessage: string | null = null,
  ): Promise<CreatorResourceEnvelope> {
    const payload: Record<string, unknown> = {}
    if (welcomeMessage !== null && welcomeMessage.trim() !== '') {
      payload.welcome_message = welcomeMessage.trim()
    }
    return http.post<CreatorResourceEnvelope>(`/admin/creators/${creatorUlid}/approve`, payload)
  },

  /**
   * Reject a creator's application — Sprint 3 Chunk 4 sub-step 2 backend.
   *
   * `rejection_reason` is REQUIRED (backend: min 10 / max 2000). Returns
   * a fresh `CreatorResource` envelope on 200 OK. Calling reject on an
   * already-rejected creator returns 409 + `creator.already_rejected`.
   */
  reject(creatorUlid: string, rejectionReason: string): Promise<CreatorResourceEnvelope> {
    return http.post<CreatorResourceEnvelope>(`/admin/creators/${creatorUlid}/reject`, {
      rejection_reason: rejectionReason,
    })
  },

  /**
   * Manually clear a creator's identity verification — Sprint 4 Chunk 3
   * (D-c3-3) backend POST /api/v1/admin/creators/{ulid}/verify-identity.
   *
   * The live identity-clearing action: sets kyc_status=verified +
   * kyc_method=manual + verified_by_user_id + an audit row. Optional
   * `note` captured in the audit metadata. Returns a fresh
   * `CreatorResource` envelope on 200 OK. Already-verified → 409 +
   * `creator.kyc_already_verified`.
   */
  verifyIdentity(
    creatorUlid: string,
    note: string | null = null,
  ): Promise<CreatorResourceEnvelope> {
    const payload: Record<string, unknown> = {}
    if (note !== null && note.trim() !== '') {
      payload.note = note.trim()
    }
    return http.post<CreatorResourceEnvelope>(
      `/admin/creators/${creatorUlid}/verify-identity`,
      payload,
    )
  },
}
