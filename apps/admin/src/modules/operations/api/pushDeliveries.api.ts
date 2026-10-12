/**
 * Admin push-delivery log API (AH-102b, Half A).
 *
 *   GET /api/v1/admin/push-deliveries           — cursor-paginated attempt log
 *   GET /api/v1/admin/push-deliveries/summary   — terminal-only outcome counts
 *                                                 + the table-depth footer
 *
 * Auth is implicit via the admin SPA's `auth:web_admin` session cookie; the
 * backend gates both routes with the platform_admin bounded bypass +
 * EnsureMfaForAdmins. An unknown filter value returns an empty page (never an
 * error, never "everything").
 */

import { http } from '@/core/api'

export type PushOutcome = 'delivered' | 'token_invalid' | 'retryable' | 'config_error' | 'rejected'

/** `failures` is the server-side preset: terminal, non-delivered attempts. */
export type PushOutcomeFilter = PushOutcome | 'failures'

export type PushWindow = '24h' | '7d' | '30d'

export type PushInAppState = 'read' | 'unread' | 'none'

/**
 * The notification types that can reach the push log: exactly the push-eligible
 * set (`PushEligibility::TOGGLEABLE` + `ALWAYS_ON`), values as stored in
 * `push_deliveries.notification_type`. The filter on the page is a select over
 * this list, so an admin never types a type from memory. Kept in step with the
 * backend by `push-notification-types.spec.ts` (reads the PHP source).
 */
export const PUSH_NOTIFICATION_TYPES = [
  'assignment.invited',
  'assignment.revision_requested',
  'assignment.draft_approved',
  'assignment.draft_rejected',
  'assignment.completed_on_approval',
  'assignment.manually_verified',
  'message.received_by_creator',
  'message.relationship_received_by_creator',
  'campaign_application.accepted',
  'campaign.job_posted',
  'creator.approved',
  'creator.rejected',
  'agency_creator_relation.admin_connected',
  'agency_creator_relation.disconnected',
] as const

export type PushNotificationType = (typeof PUSH_NOTIFICATION_TYPES)[number]

/** i18n key of a type's readable label (dots are not allowed inside a key segment). */
export function pushNotificationTypeLabelKey(type: string): string {
  return `admin.operations.push_deliveries.types.${type.replace(/\./g, '_')}`
}

export interface AdminPushDelivery {
  id: string
  type: 'push_deliveries'
  attributes: {
    created_at: string
    platform: 'ios' | 'android'
    notification_type: string
    outcome: PushOutcome
    attempt: number
    final: boolean
    code: string | null
    http_status: number | null
    detail: string | null
    creator: { id: string; display_name: string | null; email: string | null } | null
    in_app: PushInAppState
  }
}

export interface AdminPushDeliveriesResponse {
  data: AdminPushDelivery[]
  meta: {
    per_page: number
    next_cursor: string | null
    prev_cursor: string | null
    has_more: boolean
  }
}

export interface AdminPushDeliveriesSummary {
  window: PushWindow
  since: string
  counts: {
    delivered: number
    token_invalid: number
    retryable_final: number
    config_error: number
    rejected: number
  }
  table: { total_rows: number; oldest_at: string | null }
}

export interface AdminPushDeliveriesParams {
  outcome?: PushOutcomeFilter
  notification_type?: string
  creator?: string
  date_from?: string
  date_to?: string
  per_page?: number
  cursor?: string
}

export const adminPushDeliveriesApi = {
  list(params: AdminPushDeliveriesParams = {}): Promise<AdminPushDeliveriesResponse> {
    const query = new URLSearchParams()
    if (params.outcome !== undefined) query.set('outcome', params.outcome)
    if (params.notification_type !== undefined && params.notification_type !== '')
      query.set('notification_type', params.notification_type)
    if (params.creator !== undefined && params.creator !== '') query.set('creator', params.creator)
    if (params.date_from !== undefined && params.date_from !== '')
      query.set('date_from', params.date_from)
    if (params.date_to !== undefined && params.date_to !== '') query.set('date_to', params.date_to)
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    if (params.cursor !== undefined && params.cursor !== '') query.set('cursor', params.cursor)
    const qs = query.toString()
    return http.get<AdminPushDeliveriesResponse>(
      `/admin/push-deliveries${qs === '' ? '' : `?${qs}`}`,
    )
  },

  summary(window: PushWindow): Promise<AdminPushDeliveriesSummary> {
    return http.get<AdminPushDeliveriesSummary>(
      `/admin/push-deliveries/summary?window=${encodeURIComponent(window)}`,
    )
  },
}
