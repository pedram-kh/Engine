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
 * Destinations were taken from the footer of catalyst-growth.com, which
 * is the surface this one mirrors. They are absolute rather than
 * root-relative: a bare `/about` would resolve against this SPA's own
 * router and 404, so each entry points at the marketing host and opens
 * in a new tab, as the BrandLogoWall tiles already do.
 *
 * Three entries have no destination and stay `null`: the marketing site
 * publishes no Resources or Blog page and links no X account, so those
 * render as inert text rather than a dead `<a href="#">`.
 * {@link footerLinkTag} is the single place that decision is made.
 */

/** Marketing site the footer links out to. */
const SITE = 'https://www.catalyst-growth.com'

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
 * two, top to bottom). "Case studies" maps to `/work`, which is what
 * the marketing site calls that page.
 */
export const FOOTER_NAV_LINKS: ReadonlyArray<FooterNavLink> = [
  { labelKey: 'home', href: `${SITE}/` },
  { labelKey: 'about', href: `${SITE}/about` },
  { labelKey: 'services', href: `${SITE}/services` },
  { labelKey: 'case_studies', href: `${SITE}/work` },
  { labelKey: 'contact', href: `${SITE}/contact` },
  { labelKey: 'resources', href: null },
  { labelKey: 'blog', href: null },
  { labelKey: 'international', href: `${SITE}/international` },
]

/**
 * Social networks. Proper nouns, so they carry a literal label rather
 * than an i18n key.
 */
export const FOOTER_SOCIAL_LINKS: ReadonlyArray<FooterSocialLink> = [
  { label: 'Instagram', href: 'https://www.instagram.com/catalystugc' },
  { label: 'LinkedIn', href: 'https://www.linkedin.com/company/catalystgrowthx' },
  { label: 'X', href: null },
]

/** Privacy policy, hosted on the marketing site alongside the terms. */
export const FOOTER_PRIVACY_HREF = `${SITE}/legal/privacy`

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

/** Attributes for a footer entry: empty for inert text, else a link. */
export interface FooterLinkAttrs {
  readonly href?: string
  readonly target?: '_blank'
  readonly rel?: string
}

/**
 * The attribute set a footer entry renders with.
 *
 * Pairs with {@link footerLinkTag}: an inert entry is a `<span>` and
 * must NOT carry `target` / `rel`, which are meaningless there. Keeping
 * that in one tested function avoids a per-attribute ternary in the
 * template, where the never-taken arm would be invisible to the auth
 * module's 100% branch-coverage gate.
 *
 * Destinations are on the marketing site, so links open in a new tab
 * and carry the anti-tabnabbing rel.
 */
export function footerLinkAttrs(href: Href): FooterLinkAttrs {
  if (href === null) {
    return {}
  }
  return { href, target: '_blank', rel: 'noopener noreferrer' }
}
