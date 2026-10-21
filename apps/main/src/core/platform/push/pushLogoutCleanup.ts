import { pushChannelAvailable } from './pushChannelAvailable'

/**
 * AH-102 (R-4) — called by the native sign-out BEFORE the token is cleared: the
 * `DELETE /me/push-tokens` still needs the bearer. Best-effort and bounded; it
 * never throws and never delays sign-out past a few seconds.
 *
 * A thin shim on purpose: the auth store imports this statically, and the
 * registration singleton (which needs the auth store to know whether a session
 * exists) is only ever loaded dynamically from here, so there is no import cycle.
 */
export async function pushLogoutCleanup(): Promise<void> {
  if (!pushChannelAvailable()) {
    return
  }
  try {
    const { usePushRegistration } = await import('./usePushRegistration')
    await usePushRegistration().logoutCleanup()
  } catch {
    // Sign-out proceeds regardless.
  }
}

/**
 * AH-104b-del — account deletion. The server has already removed this device's token
 * with every other credential, so the sign-out cleanup above (a bearer `DELETE`) would
 * only 401. This forgets the local record and the FCM token without any server call.
 * Never throws.
 */
export async function pushForgetLocal(): Promise<void> {
  if (!pushChannelAvailable()) {
    return
  }
  try {
    const { usePushRegistration } = await import('./usePushRegistration')
    await usePushRegistration().forgetLocal()
  } catch {
    // The request already succeeded; nothing here may fail it.
  }
}
