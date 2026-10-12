/**
 * AH-103 — the push-tap payload → destination step, composed from the two pure
 * pieces ({@link parsePushTapData}, {@link resolveNotificationDestination}) so
 * `core/platform/push/pushTapRouting.ts` can stay free of module imports: `main.ts`
 * builds this with the real deps and hands it over as `resolveTap`.
 *
 * Never rejects. No usable `type` → no destination (the list), but a parseable
 * `notification_ulid` is still returned so the row is marked read.
 */

import type { TapResolution } from '@/core/platform/push/pushTapRouting'

import {
  parsePushTapData,
  resolveNotificationDestination,
  type DestinationDeps,
} from './notificationDestination'

export function createTapResolver(
  deps: DestinationDeps,
): (data: unknown) => Promise<TapResolution> {
  return async (data) => {
    const parsed = parsePushTapData(data)

    if (parsed === null) {
      return { to: null }
    }

    const resolution = await resolveNotificationDestination(
      { type: parsed.type, ids: parsed.ids },
      deps,
    )

    return {
      to: resolution.kind === 'route' ? resolution.to : null,
      ...(parsed.notificationUlid !== undefined
        ? { notificationUlid: parsed.notificationUlid }
        : {}),
    }
  }
}
