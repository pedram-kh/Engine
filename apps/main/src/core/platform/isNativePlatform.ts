import { Capacitor } from '@capacitor/core'

/**
 * AH-100 — the single platform probe for the creator app. Everything
 * that behaves differently inside the Capacitor shell (bearer transport,
 * token storage, native theme, bottom-tab shell) branches on this, never
 * on viewport width or user agent. On the web build Capacitor reports
 * `false`, so every web code path stays exactly as it was.
 */
export function isNativePlatform(): boolean {
  return Capacitor.isNativePlatform()
}

export type NativePlatform = 'ios' | 'android'

/**
 * The native OS, or `null` on the web. Sent as `platform` on
 * `POST /auth/token` (the token is named after it server-side) and used
 * for the `platform-{ios|android}` root class.
 */
export function nativePlatform(): NativePlatform | null {
  if (!isNativePlatform()) {
    return null
  }
  const platform = Capacitor.getPlatform()
  return platform === 'ios' || platform === 'android' ? platform : null
}
