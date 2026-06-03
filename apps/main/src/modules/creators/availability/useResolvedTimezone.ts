/**
 * Reactive resolved-timezone for the availability UI (D-b7).
 *
 * Reads `users.timezone` from the auth store (a nullable IANA id) and
 * falls back to the browser tz when null. Kept as a thin composable over
 * the pure `resolveTimezone` helper so components stay store-agnostic and
 * the resolution logic is unit-tested in isolation (`datetime.ts`).
 */

import { computed, type ComputedRef } from 'vue'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { resolveTimezone } from './datetime'

export function useResolvedTimezone(): ComputedRef<string> {
  const authStore = useAuthStore()
  return computed(() => resolveTimezone(authStore.user?.attributes.timezone))
}
