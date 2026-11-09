/**
 * AH-115 (L1.3) — "render the native look", as a question separate from "run on a phone".
 *
 * Until AH-115 every look difference of the creator app (bottom-tab styling, Figma app bar,
 * sheet layouts, field and card shapes) was gated on `isNativePlatform()`, which also gates
 * what is genuinely the platform: the bearer transport, token storage, push, the app-resume
 * handler. The creator web now adopts the native look, so the look gates ask this instead:
 *
 *   `useNativeLook()` = `isNativePlatform() || the creator shell says so`.
 *
 * The creator shell says so by providing `CREATOR_LOOK` (`useCreatorTheme`, called by the
 * creator, onboarding and auth layouts and nowhere else). The default is `false`, so a
 * component rendered anywhere else (the agency, the admin, a bare page) behaves exactly as
 * it did before: a shared component gated on this composable is byte-for-byte unchanged
 * outside the creator shell. The behaviour gates stay on `isNativePlatform()`.
 *
 * The injected value is a ref so `AuthLayout` can switch it per route name (the shared auth
 * screens keep the old look, AH-115 ruling 1).
 */

import { computed, inject, type ComputedRef, type InjectionKey, type Ref } from 'vue'

import { isNativePlatform } from '@/core/platform/isNativePlatform'

/** Provided by the creator-side layouts: is the creator look on for this subtree? */
export const CREATOR_LOOK: InjectionKey<Readonly<Ref<boolean>>> = Symbol('creator-look')

export function useNativeLook(): ComputedRef<boolean> {
  const native = isNativePlatform()
  const creator = inject(CREATOR_LOOK, null)
  return computed(() => native || creator?.value === true)
}
