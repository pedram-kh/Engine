/**
 * AH-115 (L1.1) — the auth routes that take the creator look on the web.
 *
 * `AuthLayout` serves every auth page, and most of them are shared with agency users
 * (`/agency/sign-in`, 2FA, forgot and reset password, accept invitation). Ruling 1 of the
 * AH-115 plan review: the creator look applies by ROUTE NAME, on creator-reachable routes
 * only; the four shared screens and `/agency/sign-in` stay exactly as they are (logged as a
 * follow-up). The agency baseline walk records those screens and must reproduce them.
 *
 * Kept out of `AuthLayout.vue` so that file stays a structural shell
 * (`tests/unit/architecture/auth-layout-shape.spec.ts`).
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'

import { useCreatorTheme } from '@/modules/creators/theme/useCreatorTheme'

export const CREATOR_LOOK_AUTH_ROUTES: readonly string[] = [
  'auth.sign-in',
  'auth.sign-up',
  'auth.verify-email.pending',
]

export function hasCreatorLook(name: unknown): boolean {
  return typeof name === 'string' && CREATOR_LOOK_AUTH_ROUTES.includes(name)
}

/**
 * `AuthLayout`'s theme hook: the creator theme while the current route has the creator look,
 * `theme: undefined` (the global theme, untouched) on every other auth screen.
 */
export function useAuthCreatorTheme() {
  const route = useRoute()
  return useCreatorTheme(computed(() => hasCreatorLook(route.name)))
}
