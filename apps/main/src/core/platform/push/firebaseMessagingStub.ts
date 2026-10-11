/**
 * AH-102 (C4.1) — build-time stand-in for `firebase/messaging`.
 *
 * `@capacitor-firebase/messaging` ships a WEB implementation
 * (`dist/esm/web.js`) that imports the Firebase web SDK, which is declared an
 * OPTIONAL peer and is deliberately not installed: push exists only in the native
 * app, the web build never calls the plugin (`pushChannelAvailable()` is false
 * there), and pulling the web SDK into `dist/` — which Capacitor copies into the
 * APK — would add weight for code that can never run.
 *
 * Without this, `vite build` fails on the unresolved import. `vite.config.ts`
 * aliases `firebase/messaging` here; the plugin's web chunk then bundles against
 * this stub and reports "not supported" if it were ever reached. The native
 * Android/iOS code paths never touch it.
 */

export async function isSupported(): Promise<boolean> {
  return false
}

function unavailable(): never {
  throw new Error('Firebase web messaging is not part of the Catalyst app; push is native-only.')
}

export const getMessaging = unavailable
export const getToken = unavailable
export const deleteToken = unavailable
export const onMessage = unavailable
