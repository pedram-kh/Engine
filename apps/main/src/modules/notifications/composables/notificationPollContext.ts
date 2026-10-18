/**
 * AH-108 (D2) — one unread-count poll per shell, shared by everything that
 * draws a count.
 *
 * `useNotificationPoll` is composable-scoped by design (no module singleton, no
 * store), so two components that each call it would run two 45 s polls against
 * the same endpoint. The creator shell now needs the SAME numbers in two places
 * (the bell's total, the Messages tab's message count), so the layout creates the
 * one poll and `provide`s it; the bell and the nav items `inject` it.
 *
 * Provide/inject keeps the docblock's promise: the state still lives in a
 * component scope (the layout's), it dies with that scope, and nothing outside
 * the tree can reach it. The agency layout provides nothing, so its bell falls
 * back to creating its own poll exactly as before.
 */

import { inject, provide, type InjectionKey } from 'vue'

import { useNotificationPoll, type NotificationPollHandle } from './useNotificationPoll'

export const NOTIFICATION_POLL_KEY: InjectionKey<NotificationPollHandle> = Symbol(
  'catalyst.notification-poll',
)

/** Called once by a shell layout: create the poll, start it, share it. */
export function provideNotificationPoll(): NotificationPollHandle {
  const poll = useNotificationPoll()
  void poll.start()
  provide(NOTIFICATION_POLL_KEY, poll)
  return poll
}

/** The shell's shared poll, or `null` when no layout provided one. */
export function injectNotificationPoll(): NotificationPollHandle | null {
  return inject(NOTIFICATION_POLL_KEY, null)
}
