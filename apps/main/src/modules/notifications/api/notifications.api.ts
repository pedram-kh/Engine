/**
 * Typed wrapper for the per-user notification feed endpoints (S11.0 Ch3a).
 *
 * USER-AGNOSTIC: the backend scopes every action to `recipient_user_id = auth
 * user` (Ch1 D-9), so — unlike `settings.api.ts` which threads a
 * `currentAgencyId` — these methods take NO agency/role argument. The same
 * `notificationsApi` object is consumed identically by the agency shell and
 * the creator shell.
 */

import type {
  NotificationFeedEnvelope,
  NotificationMarkReadEnvelope,
  NotificationPreferencesEnvelope,
  NotificationReadAllEnvelope,
  NotificationUnreadCountEnvelope,
  UpdateNotificationPreferencesPayload,
} from '@catalyst/api-client'

import { http } from '@/core/api'

export interface NotificationListParams {
  page?: number
  perPage?: number
}

export const notificationsApi = {
  /** GET /api/v1/me/notifications — the paginated feed, newest first. */
  list(params: NotificationListParams = {}): Promise<NotificationFeedEnvelope> {
    const query = new URLSearchParams()
    if (params.page !== undefined) {
      query.set('page', String(params.page))
    }
    if (params.perPage !== undefined) {
      query.set('per_page', String(params.perPage))
    }
    const suffix = query.toString()
    return http.get<NotificationFeedEnvelope>(
      suffix.length > 0 ? `/me/notifications?${suffix}` : '/me/notifications',
    )
  },

  /** GET /api/v1/me/notifications/unread-count — the cheap count-only endpoint. */
  unreadCount(): Promise<NotificationUnreadCountEnvelope> {
    return http.get<NotificationUnreadCountEnvelope>('/me/notifications/unread-count')
  },

  /** PATCH /api/v1/me/notifications/{ulid}/read — idempotent server-side. */
  markRead(ulid: string): Promise<NotificationMarkReadEnvelope> {
    return http.patch<NotificationMarkReadEnvelope>(`/me/notifications/${ulid}/read`)
  },

  /** POST /api/v1/me/notifications/read-all — idempotent server-side. */
  readAll(): Promise<NotificationReadAllEnvelope> {
    return http.post<NotificationReadAllEnvelope>('/me/notifications/read-all')
  },

  /**
   * GET /api/v1/me/notification-preferences — the caller's SPARSE rows (only
   * divergences from the channel default) plus the server-authoritative
   * `defaults` block. The page composes display state as
   * `row?.is_enabled ?? defaults[channel]` (S11.0 Ch3b, D-3).
   */
  getPreferences(): Promise<NotificationPreferencesEnvelope> {
    return http.get<NotificationPreferencesEnvelope>('/me/notification-preferences')
  },

  /**
   * PATCH /api/v1/me/notification-preferences — the product's first user
   * self-write. The backend stores sparsely (diverge → upsert, return-to-default
   * → delete) and returns the recomputed state; safe to send the full visible
   * set every save (D-1).
   */
  updatePreferences(
    payload: UpdateNotificationPreferencesPayload,
  ): Promise<NotificationPreferencesEnvelope> {
    return http.patch<NotificationPreferencesEnvelope>('/me/notification-preferences', payload)
  },
}
