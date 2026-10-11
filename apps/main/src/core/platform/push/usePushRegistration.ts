import { pushPreferenceStorage } from '@/composables/usePushPreference'
import { i18n } from '@/core/i18n'

import { nativePlatform } from '../isNativePlatform'
import { pushChannelAvailable } from './pushChannelAvailable'
import { capacitorPushClient } from './pushClient'
import { createPushRegistration, type PushRegistration } from './pushRegistration'
import { pushTokensApi } from './pushTokens.api'

/**
 * AH-102 — the app's one {@link PushRegistration}, wired to the real seams.
 *
 * A singleton because the soft-ask sheet, the preferences row, the boot wiring
 * and the sign-out cleanup must all see the same permission state. The auth
 * store is read lazily (never imported at module level) for the same reason
 * `appResume.ts` does it: this module stays free of store imports and cycles.
 */

/* c8 ignore start -- Capacitor bridge + real stores; the logic around them is
   specced with fakes in pushRegistration.spec.ts. */
async function readAppVersion(): Promise<string | undefined> {
  try {
    const { App } = await import('@capacitor/app')
    return (await App.getInfo()).version
  } catch {
    return undefined
  }
}

async function hasSession(): Promise<boolean> {
  const { useAuthStore } = await import('@/modules/auth/stores/useAuthStore')
  return useAuthStore().user !== null
}
/* c8 ignore stop */

/** The Android channel id; the server message sets the same `channel_id` (`PushMessage::DEFAULT_CHANNEL`). */
export const PUSH_CHANNEL_ID = 'catalyst_default'

let instance: PushRegistration | null = null

export function usePushRegistration(): PushRegistration {
  instance ??= createPushRegistration({
    client: capacitorPushClient,
    tokens: pushTokensApi,
    storage: pushPreferenceStorage,
    isAvailable: () => pushChannelAvailable(),
    platform: nativePlatform,
    isSignedIn: hasSession,
    channel: () => ({
      id: PUSH_CHANNEL_ID,
      name: i18n.global.t('notifications.push.channel.name'),
      description: i18n.global.t('notifications.push.channel.description'),
    }),
    appVersion: readAppVersion,
  })
  return instance
}
