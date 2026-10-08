import { isNativePlatform } from '@/core/platform/isNativePlatform'

/**
 * AH-100b D2 — whether the sign-in landing renders the web marketing tail
 * (case-study logo wall, creator-guide CTA, site footer) under the card.
 *
 * Only the hero arrangement (sign-in) has a tail, and the creator app never
 * does: a native sign-in that scrolls into a website footer — nav links,
 * socials, address, VAT — is the "website in a box" tell, and the app has no
 * sign-up funnel for the logo wall and guide to sell. Web is unchanged: the
 * tail shows exactly when the page is the hero.
 *
 * Kept out of `AuthLayout.vue` so that file stays a pure structural shell
 * (`tests/unit/architecture/auth-layout-shape.spec.ts`).
 */
export function showsMarketingTail(
  isHero: boolean,
  isNative: boolean = isNativePlatform(),
): boolean {
  return isHero && !isNative
}
