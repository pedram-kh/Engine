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
 *  - {@link StartedPush.sync}, which `main.ts` calls when a session appears, so a
 *    creator who signs in on a device that already granted permission registers
 *    without restarting the app.
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
}

export interface StartedPush {
  stop: () => void
  /** Re-check permission and register if due (call when a session appears). */
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

  return {
    stop: () => {
      stopRegistration()
      doc.removeEventListener('visibilitychange', onVisibility)
    },
    sync,
  }
}
