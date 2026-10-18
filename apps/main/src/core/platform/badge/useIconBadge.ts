import { onBeforeUnmount, watch } from 'vue'

import type { NotificationPollHandle } from '@/modules/notifications/composables/useNotificationPoll'

import { iconBadge, type IconBadge } from './iconBadge'

/**
 * AH-108 (4b) — keep the home-screen icon badge equal to the bell total.
 *
 * Called once by the creator shell, which already owns the one unread poll, so
 * there is no extra request: the number is `poll.unreadCount`, written
 *   - whenever it changes (a poll, a thread opened, mark-all-read), and
 *   - on every return to the foreground, forced, because a push may have set the
 *     icon to a different number while the app slept.
 * Nothing is written until the poll has read a real count (`hasLoaded`): the
 * placeholder 0 on a cold start would otherwise wipe a badge a push had just set.
 *
 * Inert off native iOS: the {@link IconBadge} gate stops every write there, and
 * the listeners it adds are two cheap no-ops.
 */
export interface UseIconBadgeOptions {
  badge?: IconBadge
  /** The foreground signal. Defaults to the document's `visibilitychange`. */
  document?: Pick<Document, 'addEventListener' | 'removeEventListener' | 'visibilityState'>
}

export function useIconBadge(
  poll: Pick<NotificationPollHandle, 'unreadCount' | 'hasLoaded'>,
  options: UseIconBadgeOptions = {},
): void {
  const badge = options.badge ?? iconBadge
  const doc = options.document ?? document

  // A new shell is mounting for a live session: writes are welcome again.
  badge.enable()

  const stopWatch = watch(
    () => [poll.hasLoaded.value, poll.unreadCount.value] as const,
    ([loaded, count]) => {
      if (loaded) {
        void badge.set(count)
      }
    },
    { immediate: true },
  )

  const onVisibility = (): void => {
    if (doc.visibilityState === 'visible' && poll.hasLoaded.value) {
      void badge.set(poll.unreadCount.value, { force: true })
    }
  }
  doc.addEventListener('visibilitychange', onVisibility)

  onBeforeUnmount(() => {
    stopWatch()
    doc.removeEventListener('visibilitychange', onVisibility)
  })
}
