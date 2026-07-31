/**
 * Unit tests for the marketing footer's data + tag helper.
 *
 * `footerLinkTag` exists so BOTH the anchor and the inert-text arms are
 * exercised while every shipped `href` is still `null` — the auth module
 * is held at 100% branch coverage, which an in-template ternary could
 * not reach from the live data alone. When Pedram supplies the real
 * URLs, the anchor path is already under test.
 */

import { describe, expect, it } from 'vitest'

import {
  FOOTER_CONTACT,
  FOOTER_LEGAL,
  FOOTER_NAV_LINKS,
  FOOTER_SOCIAL_LINKS,
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

  it('no destination is a dead placeholder — every href is still null', () => {
    // Guards against someone filling these with `'#'` or `''` rather
    // than a real URL; the inert-text path is deliberate until the real
    // links land.
    expect(allHrefs).toEqual(allHrefs.map(() => null))
    expect(allHrefs).toHaveLength(11)
  })

  it('exposes the contact and registration details the footer renders', () => {
    expect(FOOTER_CONTACT.email).toBe('info@catalyst-growth.com')
    expect(FOOTER_CONTACT.address).toContain('Walworth Rd')
    expect(FOOTER_LEGAL.companyNumber).toBe('13632394')
    expect(FOOTER_LEGAL.vatNumber).toBe('VAT GB445140812')
  })
})
