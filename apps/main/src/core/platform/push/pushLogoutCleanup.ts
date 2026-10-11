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
