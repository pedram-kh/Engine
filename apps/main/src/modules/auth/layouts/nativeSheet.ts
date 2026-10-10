import { isNativePlatform } from '@/core/platform/isNativePlatform'

/**
 * AH-101 U2 — whether the sign-in landing is arranged as the creator app's
 * bottom sheet (Figma frame 02): the brand mark above a full-bleed sheet pinned
 * to the bottom edge, a language pill top-right, and no hero headline.
 *
 * Only the hero arrangement (sign-in) takes the sheet; every other auth page
 * keeps the centred card in the app too. Web is unchanged. Kept out of
 * `AuthLayout.vue` so that file stays a structural shell
 * (`tests/unit/architecture/auth-layout-shape.spec.ts`).
 */
export function usesNativeSheet(isHero: boolean, isNative: boolean = isNativePlatform()): boolean {
  return isHero && isNative
}
