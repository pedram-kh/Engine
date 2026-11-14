/**
 * AH-121 C2/C3: Sentry for the main SPA. Called from `main.ts` right after Pinia is
 * installed and before the router is. With no DSN this does nothing at all:
 * `Sentry.init` is never called and nothing is sent.
 *
 * Two clients, never mixed: the browser client reads `VITE_SENTRY_DSN_MAIN` (catalyst-main);
 * inside the Capacitor shell the native client reads `VITE_SENTRY_DSN_APP` (catalyst-app)
 * and never the MAIN one (`sentry-source-inspection.spec.ts` pins both directions).
 *
 * The user is the auth store's ulid (`user.id`) plus the `user_type` tag, set on
 * sign-in and cleared on sign-out; see `sentryClient.ts`.
 */

import type { App } from 'vue'
import type { Router } from 'vue-router'

import { isNativePlatform } from '@/core/platform/isNativePlatform'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

import { startEarlyErrorBuffer } from './earlyErrors'
import { startSentry } from './sentryClient'

export function initSentry(app: App, router: Router): boolean {
  const user = () => {
    const auth = useAuthStore()
    return { id: auth.user?.id ?? null, type: auth.userType }
  }

  if (isNativePlatform()) {
    const dsn = import.meta.env.VITE_SENTRY_DSN_APP
    if ((dsn ?? '').trim() === '') {
      return false
    }
    // Before anything else can throw: the native client takes a bridge round trip to exist.
    startEarlyErrorBuffer(app)
    void import('./sentryNative').then(({ startNativeSentry }) =>
      startNativeSentry({
        app,
        router,
        dsn,
        // Vite's MODE is `native-production` there; the Sentry environment is one of two names.
        environment: import.meta.env.MODE === 'native-production' ? 'production' : 'development',
        user,
      }),
    )
    return true
  }

  return startSentry({
    app,
    router,
    dsn: import.meta.env.VITE_SENTRY_DSN_MAIN,
    release: __SENTRY_RELEASE__,
    environment: import.meta.env.MODE,
    user,
  })
}
