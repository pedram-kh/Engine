/**
 * Admin GDPR-compliance API.
 *
 *   GET  /admin/compliance/export-requests                       — data-subject export queue (SHELL, Sprint 13 D-11)
 *   GET  /admin/compliance/erasure-queue                         — the erasure queue (AH-104c C2): six tabs
 *   POST /admin/compliance/erasure-queue/{user}/hold | /release  — the legal hold (reason required)
 *
 * The export queue still returns an empty `data: []` with `meta.shell: true`. The erasure
 * queue is real: a tab per state, counts for all of them, and an id cursor. `{user}` is the
 * USER ulid (the row id), the one key the engine, the ledger and the runbook all use.
 *
 * Auth is implicit via the admin SPA's `auth:web_admin` session cookie; the backend gates the
 * routes with the platform_admin bounded bypass + EnsureMfaForAdmins.
 */

import { http } from '@/core/api'

export interface ComplianceRequest {
  id: string
  type: 'data_export_requests'
  attributes: {
    subject_email: string | null
    status: string
    requested_at: string
  }
}

export interface ComplianceQueueResponse {
  data: ComplianceRequest[]
  meta: {
    total: number
    shell: boolean
  }
}

export const ERASURE_TABS = [
  'pending',
  'ready',
  'blocked',
  'held',
  'in_progress',
  'erased',
] as const

export type ErasureTab = (typeof ERASURE_TABS)[number]

/** The guards' verdict as codes (never their sentences), plus `not_erasable` for a missing profile. */
export type ErasureBlocker = 'payment' | 'delivered_work' | 'vendor' | 'not_erasable'

/** How far a claimed account's run got: the ledger state, or `claimed` before the run writes one. */
export type ErasureLedgerState = 'claimed' | 'started' | 'db_done' | 'incomplete' | 'completed'

/** A live (not yet erased) account. */
export interface ErasureQueueRow {
  id: string
  type: 'erasure_queue_rows'
  attributes: {
    state: Exclude<ErasureTab, 'erased'>
    creator_ulid: string | null
    name: string
    email: string
    requested_at: string | null
    due_at: string | null
    days_left: number | null
    held: boolean
    held_at: string | null
    held_by: string | null
    claimed_at: string | null
    ledger_state: ErasureLedgerState | null
    blockers: ErasureBlocker[]
  }
}

/** An erased account: the ledger's row, and nothing of the person. */
export interface ErasedQueueRow {
  id: string
  type: 'erasure_queue_rows'
  attributes: {
    state: 'erased'
    ledger_state: 'completed'
    erased_at: string | null
  }
}

export type ErasureQueueItem = ErasureQueueRow | ErasedQueueRow

export interface ErasureQueueCounts {
  pending: number
  ready: number
  blocked: number
  held: number
  in_progress: number
  erased: number
  /** True when more accounts are due than one request checks (the first 200 are). */
  due_scan_capped: boolean
}

export interface ErasureQueueResponse {
  data: ErasureQueueItem[]
  meta: {
    tab: ErasureTab
    per_page: number
    next_cursor: string | null
    counts: ErasureQueueCounts
  }
}

export interface ErasureHoldResponse {
  data: ErasureQueueRow | null
  meta: { changed: boolean }
}

export const adminComplianceApi = {
  listExports(): Promise<ComplianceQueueResponse> {
    return http.get<ComplianceQueueResponse>('/admin/compliance/export-requests')
  },
  listErasureQueue(params: {
    tab: ErasureTab
    cursor?: string | null
    perPage?: number
  }): Promise<ErasureQueueResponse> {
    const query = new URLSearchParams({ tab: params.tab })
    if (params.cursor !== undefined && params.cursor !== null && params.cursor !== '')
      query.set('cursor', params.cursor)
    if (params.perPage !== undefined) query.set('per_page', String(params.perPage))
    return http.get<ErasureQueueResponse>(`/admin/compliance/erasure-queue?${query.toString()}`)
  },
  holdErasure(userUlid: string, reason: string): Promise<ErasureHoldResponse> {
    return http.post<ErasureHoldResponse>(`/admin/compliance/erasure-queue/${userUlid}/hold`, {
      reason,
    })
  },
  releaseErasure(userUlid: string, reason: string): Promise<ErasureHoldResponse> {
    return http.post<ErasureHoldResponse>(`/admin/compliance/erasure-queue/${userUlid}/release`, {
      reason,
    })
  },
}
