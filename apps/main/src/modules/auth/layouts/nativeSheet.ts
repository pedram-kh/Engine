import { isNativePlatform } from '@/core/platform/isNativePlatform'

/**
 * The auth routes the creator app arranges as the bottom sheet (Figma frame 02):
 * the brand mark above a full-bleed sheet pinned to the bottom edge, a language
 * pill top-right, and no hero headline.
 *
 * AH-101 U2 made the two sign-in routes sheets. AH-104d adds the sign-up page
 * and the verification-pending page, so a new account walks sheet to sheet and
 * never drops into a centred web card between them. This is deliberately NOT
 * `isHero`: the hero arrangement also draws the hero panel and the marketing
 * tail, and putting sign-up in it would change the web page.
 *
 * Every other auth page (forgot password, reset, the confirm page) keeps the
 * centred card in the app too. Web is unchanged. Kept out of `AuthLayout.vue`
 * so that file stays a structural shell
 * (`tests/unit/architecture/auth-layout-shape.spec.ts`).
 */
export const NATIVE_SHEET_ROUTES: readonly string[] = [
  'auth.sign-in',
  'auth.agency-sign-in',
  'auth.sign-up',
  'auth.verify-email.pending',
]

/** Whether a route name is one of the sheet routes (platform-independent). */
export function isSheetRoute(name: unknown): boolean {
  return typeof name === 'string' && NATIVE_SHEET_ROUTES.includes(name)
}

export function usesNativeSheet(isSheet: boolean, isNative: boolean = isNativePlatform()): boolean {
  return isSheet && isNative
}
