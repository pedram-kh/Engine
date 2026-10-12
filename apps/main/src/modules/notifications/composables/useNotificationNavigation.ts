/**
 * AH-103 — "open this notification" for the in-app feed (the row-click twin of the
 * push tap in `core/platform/push/pushTapRouting.ts`). It runs the SAME resolver
 * over the same registry, so a row and a push for one notification land on one
 * screen.
 *
 * A row differs from a push in what it knows: it carries its whole `data` bag and
 * a `subject` (class basename + ULID), so an OLD row written before a route key
 * existed can still resolve through the subject (`campaign.job_posted` only) —
 * and one whose id was never stored falls back instead of becoming a dead click.
 *
 * Resolving to the fallback means STAY: the user is already on the list.
 * Never rejects — a row click must not throw into Vue's event handler.
 */

import type { NotificationResource } from '@catalyst/api-client'
import { useRouter } from 'vue-router'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

import { idsFromRow, resolveNotificationDestination } from '../notificationDestination'
import { lookupThreadCounterparty } from '../threadLookup'

export function useNotificationNavigation(): {
  open: (row: NotificationResource) => Promise<void>
} {
  const router = useRouter()

  // Stores are read at CLICK time, not setup time: only a relationship-thread row
  // needs either, and a row that resolves to a plain route must not require them.
  async function open(row: NotificationResource): Promise<void> {
    try {
      const { notification_type: type, data, subject } = row.attributes

      const resolution = await resolveNotificationDestination(
        { type, ids: idsFromRow(data), subject },
        {
          hasRoute: (name) => router.hasRoute(name),
          hasSession: () => useAuthStore().user !== null,
          lookupThread: (side, threadUlid) =>
            lookupThreadCounterparty(side, threadUlid, useAgencyStore().currentAgencyId),
        },
      )

      if (resolution.kind === 'route') {
        await router.push(resolution.to)
      }
    } catch {
      // A failed navigation leaves the user where they are, on the list.
    }
  }

  return { open }
}
