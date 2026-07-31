/**
 * Unit tests for the marketing footer's data + tag helper.
 *
 * `footerLinkTag` / `footerLinkAttrs` exist so BOTH the anchor and the
 * inert-text arms are exercised regardless of which entries currently
 * have destinations — the auth module is held at 100% branch coverage,
 * which an in-template ternary could not reach from the live data alone.
 */

import { describe, expect, it } from 'vitest'

import {
  FOOTER_CONTACT,
  FOOTER_LEGAL,
  FOOTER_NAV_LINKS,
  FOOTER_PRIVACY_HREF,
  FOOTER_SOCIAL_LINKS,
  footerLinkAttrs,
  footerLinkTag,
} from './footerLinks'

describe('footerLinkTag', () => {
  it('renders inert text while a destination is still outstanding', () => {
    expect(footerLinkTag(null)).toBe('span')
  })

  it('renders a real anchor once a destination exists', () => {
    expect(footerLinkTag('https://www.catalyst-growth.com/about')).toBe('a')
  })

  // §5.34 negative set: each case is a link-shaped value that is
  // nonetheless NOT a usable destination, so only `null` may downgrade
  // the element. An empty string is a truthy-check trap — `href === null`
  // is the contract, not `href` being falsy.
  it.each([
    ['empty string', ''],
    ['whitespace', ' '],
    ['fragment only', '#'],
    ['relative path', '/about'],
  ])('treats a non-null href (%s) as a link', (_label, href) => {
    expect(footerLinkTag(href)).toBe('a')
  })
})

describe('footerLinkAttrs', () => {
  it('gives an inert entry no attributes at all', () => {
    // A <span> must not carry target/rel; asserting the exact object
    // catches an `undefined`-valued key sneaking back in.
    expect(footerLinkAttrs(null)).toEqual({})
  })

  it('opens a real destination in a new tab with the anti-tabnabbing rel', () => {
    expect(footerLinkAttrs('https://www.catalyst-growth.com/about')).toEqual({
      href: 'https://www.catalyst-growth.com/about',
      target: '_blank',
      rel: 'noopener noreferrer',
    })
  })
})

describe('footer link data', () => {
  const allHrefs = [...FOOTER_NAV_LINKS, ...FOOTER_SOCIAL_LINKS].map((link) => link.href)

  it('ships the eight navigation entries in the Figma column order', () => {
    expect(FOOTER_NAV_LINKS.map((link) => link.labelKey)).toEqual([
      'home',
      'about',
      'services',
      'case_studies',
      'contact',
      'resources',
      'blog',
      'international',
    ])
  })

  it('ships the three social entries as literal proper nouns', () => {
    expect(FOOTER_SOCIAL_LINKS.map((link) => link.label)).toEqual(['Instagram', 'LinkedIn', 'X'])
  })

  it('points the six published pages at the marketing site', () => {
    const byKey = new Map(FOOTER_NAV_LINKS.map((link) => [link.labelKey, link.href]))
    expect(byKey.get('home')).toBe('https://www.catalyst-growth.com/')
    expect(byKey.get('about')).toBe('https://www.catalyst-growth.com/about')
    expect(byKey.get('services')).toBe('https://www.catalyst-growth.com/services')
    // The marketing site calls its case-studies page /work.
    expect(byKey.get('case_studies')).toBe('https://www.catalyst-growth.com/work')
    expect(byKey.get('contact')).toBe('https://www.catalyst-growth.com/contact')
    expect(byKey.get('international')).toBe('https://www.catalyst-growth.com/international')
    expect(FOOTER_PRIVACY_HREF).toBe('https://www.catalyst-growth.com/legal/privacy')
  })

  it('leaves the three entries with no published destination inert', () => {
    // The marketing site publishes no Resources or Blog page and links
    // no X account. Inert text is deliberate — filling these in with a
    // guess would ship a 404.
    const inert = [...FOOTER_NAV_LINKS, ...FOOTER_SOCIAL_LINKS]
      .filter((link) => link.href === null)
      .map((link) => ('labelKey' in link ? link.labelKey : link.label))
    expect(inert).toEqual(['resources', 'blog', 'X'])
  })

  it('links to the social accounts the marketing site links', () => {
    const byLabel = new Map(FOOTER_SOCIAL_LINKS.map((link) => [link.label, link.href]))
    expect(byLabel.get('Instagram')).toBe('https://www.instagram.com/catalystugc')
    expect(byLabel.get('LinkedIn')).toBe('https://www.linkedin.com/company/catalystgrowthx')
  })

  it('every destination is absolute, never root-relative or a placeholder', () => {
    // §5.34: a root-relative `/about` is the trap here — it would
    // resolve against THIS app's router and 404 rather than reaching the
    // marketing site. `#` and `''` are the dead-placeholder traps.
    const present = [...allHrefs, FOOTER_PRIVACY_HREF].filter(
      (href): href is string => href !== null,
    )
    expect(present).toHaveLength(9)
    for (const href of present) {
      expect(href).toMatch(/^https:\/\//)
    }
  })

  it('exposes the contact and registration details the footer renders', () => {
    expect(FOOTER_CONTACT.email).toBe('info@catalyst-growth.com')
    expect(FOOTER_CONTACT.address).toContain('Walworth Rd')
    expect(FOOTER_LEGAL.companyNumber).toBe('13632394')
    expect(FOOTER_LEGAL.vatNumber).toBe('VAT GB445140812')
  })
})
