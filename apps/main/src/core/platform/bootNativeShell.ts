import { nativePlatform, type NativePlatform } from './isNativePlatform'

/* c8 ignore start -- the Capacitor bridge call; exercised on device (the
   AH-100 walkthrough), injected as a fake in the spec. */
async function setDarkSystemBars(): Promise<void> {
  const { SystemBars, SystemBarsStyle } = await import('@capacitor/core')
  await SystemBars.setStyle({ style: SystemBarsStyle.Dark })
}
/* c8 ignore stop */

/**
 * AH-100 D4 — prepare the document for the creator app before the first
 * paint:
 *   - mark `<html>` with `catalyst-native` + `platform-{ios|android}`, the
 *     scope every native-only style hangs off;
 *   - extend the viewport to the full screen (`viewport-fit=cover`) so the
 *     safe-area insets are reported and the shell pads around the notch
 *     and home indicator itself;
 *   - load the native token layer;
 *   - put light status/navigation-bar content over the dark-only app (iOS
 *     ignores the config's `SystemBars.style`, so it is set here). Not
 *     awaited: a failed or unanswered bridge call only costs bar contrast,
 *     so it must never hold up the mount.
 * On the web this is a no-op: no class, no viewport change, no stylesheet.
 */
export async function bootNativeShell(
  doc: Document,
  platform: NativePlatform | null = nativePlatform(),
  loadNativeCss: () => Promise<unknown> = () => import('@catalyst/design-tokens/native.css'),
  setBarsStyle: () => Promise<void> = setDarkSystemBars,
): Promise<void> {
  if (platform === null) {
    return
  }
  doc.documentElement.classList.add('catalyst-native', `platform-${platform}`)
  const viewport = doc.querySelector<HTMLMetaElement>('meta[name="viewport"]')
  if (viewport !== null && !viewport.content.includes('viewport-fit')) {
    viewport.content = `${viewport.content}, viewport-fit=cover`
  }
  setBarsStyle().catch(() => undefined)
  await loadNativeCss()
}
