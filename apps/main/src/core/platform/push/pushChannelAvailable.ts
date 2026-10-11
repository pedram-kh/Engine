import { Capacitor } from '@capacitor/core'

import { isNativePlatform, type NativePlatform } from '../isNativePlatform'

/**
 * AH-102 (P-8) — whether the creator app can receive push on this build, and
 * therefore whether the `push` channel is shown or sent at all.
 *
 * "Never a dead control" is the intent: a push switch must exist only where a
 * push can actually arrive. That is narrower than `isNativePlatform()` because
 * the iOS build ships before iOS push does (AH-102-ios needs the Apple
 * account): there `isNativePlatform()` is true but nothing can be delivered, so
 * a bare native check would show iOS creators a switch that gates nothing.
 *
 * The web build is always `false`. Capacitor reports no platform there, and
 * every web code path stays exactly as it was.
 */

/**
 * Flip to `true` in the AH-102-ios chunk, together with the Apple capability,
 * the APNs key and the Firebase iOS app. It is a constant, not an env var, on
 * purpose: shipping iOS push is a code change that gets reviewed and built, never
 * a runtime toggle that could light up a channel with no credentials behind it.
 */
export const IOS_PUSH_ENABLED = false

export interface PushAvailabilityOptions {
  /** The native OS, `null` on the web. Injectable so each polarity is specced. */
  platform?: () => NativePlatform | null
  /** Overridable so the iOS-on polarity is specced without editing the constant. */
  iosPushEnabled?: boolean
}

/**
 * The native OS, gated on the one platform probe. Reads `isNativePlatform()`
 * (rather than `nativePlatform()`) so a spec that stubs the probe alone — as the
 * preferences page spec does — also turns this off: no shell, no push.
 */
function currentPlatform(): NativePlatform | null {
  if (!isNativePlatform()) {
    return null
  }
  const platform = Capacitor.getPlatform()
  return platform === 'ios' || platform === 'android' ? platform : null
}

export function pushChannelAvailable(options: PushAvailabilityOptions = {}): boolean {
  const { platform = currentPlatform, iosPushEnabled = IOS_PUSH_ENABLED } = options

  switch (platform()) {
    case 'android':
      return true
    case 'ios':
      return iosPushEnabled
    default:
      return false
  }
}
