import { watch } from 'vue'

import { pushChannelAvailable } from './pushChannelAvailable'
import type { PushHandlers, PushRegistration } from './pushRegistration'
import { usePushRegistration } from './usePushRegistration'

/**
 * AH-102 (C4.1) — start the creator app's push wiring. Called once from
 * `main.ts` after mount, beside `startAppResumeRefresh`, and a no-op everywhere
 * push cannot work (the web build; iOS before AH-102-ios).
 *
 * What it keeps running:
 *  - the token / foreground / tap listeners (`PushRegistration.start`);
 *  - a registration sync whenever the app comes back to the foreground (the
 *    WebView's `visibilitychange`, the same signal the notification poll uses) —
 *    throttled to once a day inside the registration;
 *  - a registration whenever a session APPEARS (the user id goes from nothing to
 *    something: a sign-in, or the bootstrap of a stored login). That registration
 *    does not trust the stored `registeredAt` — see
 *    {@link PushRegistration.registerAfterSignIn}: a revoked or lapsed login deletes
 *    the server's token row without the device ever knowing, so the next sign-in
 *    must re-register whatever the clock says. (AH-103 walk, W8: it did not, and
 *    the device then received no push until the next manual sign-out.)
 *
 * It never asks for permission: the OS prompt lives behind the soft-ask sheet.
 * The router and the notification refresh are injected, not imported — only the
 * wiring layer may touch those singletons (the `onSessionLapsed` precedent).
 */
export interface StartPushOptions extends PushHandlers {
  isAvailable?: () => boolean
  registration?: PushRegistration
  /** The foreground signal. Defaults to the document's `visibilitychange`. */
  document?: Pick<Document, 'addEventListener' | 'removeEventListener' | 'visibilityState'>
  /** The signed-in user's id, or null. A change from null to a value is a session appearing. */
  userId?: () => string | number | null
}

export interface StartedPush {
  stop: () => void
  /** Re-check permission and register if due (the foreground upkeep; throttled to a day). */
  sync: () => void
}

export function startPush(options: StartPushOptions): StartedPush {
  const { isAvailable = () => pushChannelAvailable(), onForeground, onTap } = options

  if (!isAvailable()) {
    return { stop: () => undefined, sync: () => undefined }
  }

  const registration = options.registration ?? usePushRegistration()
  const doc = options.document ?? document

  const sync = (): void => {
    void registration.syncIfGranted().catch(() => undefined)
  }
  const onVisibility = (): void => {
    if (doc.visibilityState === 'visible') {
      sync()
    }
  }

  const stopRegistration = registration.start({ onForeground, onTap })
  doc.addEventListener('visibilitychange', onVisibility)

  const stopSessionWatch =
    options.userId === undefined
      ? () => undefined
      : watch(options.userId, (id, previous) => {
          // null -> id is a session appearing. id -> id (a different user) is one too.
          if (id !== null && id !== previous) {
            void registration.registerAfterSignIn().catch(() => undefined)
          }
        })

  return {
    stop: () => {
      stopRegistration()
      stopSessionWatch()
      doc.removeEventListener('visibilitychange', onVisibility)
    },
    sync,
  }
}
