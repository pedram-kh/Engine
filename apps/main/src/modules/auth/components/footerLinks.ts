/**
 * Data + pure helpers for {@link AuthMarketingFooter.vue} (Figma
 * "Rebrand" node 359-1253 desktop / 359-1663 mobile footer).
 *
 * Two kinds of value live here, and the split is deliberate:
 *
 *   - **Copy** (nav labels, "Privacy policy") stays in the i18n bundle —
 *     only the KEY lives here, so the en-SOT parity gate
 *     (`tests/unit/architecture/i18n-locale-parity.spec.ts`) still owns
 *     the strings across all 24 rendered locales.
 *   - **Fixed business data** (email, postal address, company number,
 *     VAT number, and the social networks' proper nouns) is hardcoded,
 *     matching the precedent in `BrandLogoWall.vue`, which likewise
 *     hardcodes `'Perplexity'` / `'Huel'` as data rather than routing
 *     brand names through a translator.
 *
 * The `href` of every navigation and social entry is `null` until the
 * real destinations are supplied — see {@link FOOTER_NAV_LINKS}. A null
 * href renders as inert text rather than a dead `<a href="#">`, and
 * {@link footerLinkTag} is the single place that decision is made, so
 * the anchor path is unit-testable before any URL exists.
 */

/** Destination of a footer entry, `null` while the URL is outstanding. */
type Href = string | null

/** A navigation entry, labelled from the i18n bundle. */
export interface FooterNavLink {
  /** Key under `auth.ui.footer.nav`. */
  readonly labelKey: string
  readonly href: Href
}

/** A social entry, labelled by a proper noun rather than a translation. */
export interface FooterSocialLink {
  readonly label: string
  readonly href: Href
}

/**
 * Site navigation, in the Figma column order (column one then column
 * two, top to bottom).
 *
 * ┌─────────────────────────────────────────────────────────────────┐
 * │ TODO(pedram): paste the real destinations here. Set each `href` │
 * │ to its absolute URL; the entry becomes a real link with no      │
 * │ other change anywhere (see `footerLinkTag`).                    │
 * └─────────────────────────────────────────────────────────────────┘
 */
export const FOOTER_NAV_LINKS: ReadonlyArray<FooterNavLink> = [
  { labelKey: 'home', href: null },
  { labelKey: 'about', href: null },
  { labelKey: 'services', href: null },
  { labelKey: 'case_studies', href: null },
  { labelKey: 'contact', href: null },
  { labelKey: 'resources', href: null },
  { labelKey: 'blog', href: null },
  { labelKey: 'international', href: null },
]

/**
 * Social networks. Proper nouns, so they carry a literal label rather
 * than an i18n key. Same outstanding-URL treatment as the nav links.
 */
export const FOOTER_SOCIAL_LINKS: ReadonlyArray<FooterSocialLink> = [
  { label: 'Instagram', href: null },
  { label: 'LinkedIn', href: null },
  { label: 'X', href: null },
]

/** Contact block. The email is a live `mailto:` — the URL is known. */
export const FOOTER_CONTACT = {
  email: 'info@catalyst-growth.com',
  address: '151 Walworth Rd, London. SE17 1RS',
} as const

/** Registration details rendered in the copyright row. */
export const FOOTER_LEGAL = {
  copyright: '©2026 Catalyst',
  companyNumber: '13632394',
  vatNumber: 'VAT GB445140812',
} as const

/**
 * The element a footer entry renders as: a real anchor once a
 * destination exists, otherwise inert text.
 *
 * Extracted from the SFC so BOTH arms are covered by
 * `footerLinks.spec.ts` while every shipped `href` is still `null` —
 * the auth module is held at 100% branch coverage
 * (`vitest.config.ts`), which an in-template ternary could not satisfy
 * from the live data alone.
 */
export function footerLinkTag(href: Href): 'a' | 'span' {
  return href === null ? 'span' : 'a'
}
