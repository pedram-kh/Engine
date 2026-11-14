/**
 * AH-121 C2: Sentry for the main SPA. Called from `main.ts` right after Pinia is
 * installed and before the router is. With no `VITE_SENTRY_DSN_MAIN` this does
 * nothing at all: `Sentry.init` is never called and nothing is sent.
 *
 * The user is the auth store's ulid (`user.id`) plus the `user_type` tag, set on
 * sign-in and cleared on sign-out; see `sentryClient.ts`.
 */

import type { App } from 'vue'
import type { Router } from 'vue-router'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

import { startSentry } from './sentryClient'

export function initSentry(app: App, router: Router): boolean {
  return startSentry({
    app,
    router,
    dsn: import.meta.env.VITE_SENTRY_DSN_MAIN,
    release: __SENTRY_RELEASE__,
    environment: import.meta.env.MODE,
    user: () => {
      const auth = useAuthStore()
      return { id: auth.user?.id ?? null, type: auth.userType }
    },
  })
}
