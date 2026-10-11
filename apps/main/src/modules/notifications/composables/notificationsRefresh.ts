/**
 * AH-102 — "something new arrived, re-read the bell now".
 *
 * The unread-count poll is composable-scoped (one per mounted bell, no shared
 * store — see `useNotificationPoll`), so a foreground push has nothing to call
 * directly. A document event is the seam: the push wiring (`main.ts`) dispatches
 * it, every running poll listens and refetches. It carries no data, holds no
 * state, and does nothing when no bell is mounted.
 */
export const NOTIFICATIONS_REFRESH_EVENT = 'catalyst:notifications-refresh'

export function requestNotificationsRefresh(target: EventTarget = document): void {
  target.dispatchEvent(new Event(NOTIFICATIONS_REFRESH_EVENT))
}
