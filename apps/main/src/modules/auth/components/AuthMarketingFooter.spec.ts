/**
 * Component tests for the marketing footer on the rebrand sign-in
 * landing.
 *
 * The behaviour worth pinning is the split between real links and inert
 * text. Destinations come from catalyst-growth.com's own footer, so the
 * entries it has no page for (Resources, Blog, X) must stay inert — a
 * dead `<a href="#">` is worse than plain text — and every real one must
 * be absolute, since a root-relative href would hit this app's router
 * instead of the marketing site.
 */

import { afterEach, describe, expect, it } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import AuthMarketingFooter from './AuthMarketingFooter.vue'

describe('AuthMarketingFooter', () => {
  let teardown: (() => void) | null = null

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('renders the eight navigation labels from the i18n bundle', async () => {
    const h = await mountAuthPage(AuthMarketingFooter)
    teardown = h.unmount

    const nav = h.wrapper.find('[data-test="auth-footer-nav"]')
    expect(nav.findAll('.auth-footer__link')).toHaveLength(8)
    expect(nav.text()).toContain('Home')
    expect(nav.text()).toContain('Case studies')
    expect(nav.text()).toContain('International')
  })

  it('links the published pages out to the marketing site in a new tab', async () => {
    const h = await mountAuthPage(AuthMarketingFooter)
    teardown = h.unmount

    const about = h.wrapper
      .find('[data-test="auth-footer-nav"]')
      .findAll('a')
      .find((a) => a.text() === 'About us')
    expect(about?.attributes('href')).toBe('https://www.catalyst-growth.com/about')
    expect(about?.attributes('target')).toBe('_blank')
    expect(about?.attributes('rel')).toBe('noopener noreferrer')
  })

  it('renders entries with no published page as inert text carrying no link attributes', async () => {
    const h = await mountAuthPage(AuthMarketingFooter)
    teardown = h.unmount

    const inert = h.wrapper
      .findAll('.auth-footer__link')
      .filter((entry) => ['Resources', 'Blog', 'X'].includes(entry.text()))
    expect(inert).toHaveLength(3)
    for (const entry of inert) {
      expect(entry.element.tagName).toBe('SPAN')
      // A <span> must not inherit target/rel from the link branch.
      expect(entry.attributes('href')).toBeUndefined()
      expect(entry.attributes('target')).toBeUndefined()
      expect(entry.attributes('rel')).toBeUndefined()
    }
  })

  it('links the social accounts the marketing site links, and only those', async () => {
    const h = await mountAuthPage(AuthMarketingFooter)
    teardown = h.unmount

    const social = h.wrapper.find('[data-test="auth-footer-social"]')
    expect(social.findAll('a').map((a) => a.attributes('href'))).toEqual([
      'https://www.instagram.com/catalystugc',
      'https://www.linkedin.com/company/catalystgrowthx',
    ])
    expect(social.text()).toContain('X')
  })

  it('links "Privacy policy" to the published policy', async () => {
    const h = await mountAuthPage(AuthMarketingFooter)
    teardown = h.unmount

    const privacy = h.wrapper.find('[data-test="auth-footer-privacy"]')
    expect(privacy.text()).toBe('Privacy policy')
    expect(privacy.element.tagName).toBe('A')
    expect(privacy.attributes('href')).toBe('https://www.catalyst-growth.com/legal/privacy')
  })

  it('ships no dead or app-relative anchors anywhere in the footer', async () => {
    const h = await mountAuthPage(AuthMarketingFooter)
    teardown = h.unmount

    // Every anchor must leave this app: absolute https, or the mailto.
    // A bare `/about` would resolve against our own router and 404.
    // 6 nav + 2 social + privacy + mailto.
    const hrefs = h.wrapper.findAll('a').map((a) => a.attributes('href'))
    expect(hrefs).toHaveLength(10)
    for (const href of hrefs) {
      expect(href).toMatch(/^(https:\/\/|mailto:)/)
    }
    expect(hrefs).toContain('mailto:info@catalyst-growth.com')
  })

  it('renders the contact block and the registration details', async () => {
    const h = await mountAuthPage(AuthMarketingFooter)
    teardown = h.unmount

    const text = h.wrapper.find('[data-test="auth-marketing-footer"]').text()
    expect(text).toContain('info@catalyst-growth.com')
    expect(text).toContain('151 Walworth Rd, London. SE17 1RS')
    expect(text).toContain('©2026 Catalyst')
    expect(text).toContain('13632394')
    expect(text).toContain('VAT GB445140812')
  })

  it('hides the decorative monogram from assistive technology', async () => {
    const h = await mountAuthPage(AuthMarketingFooter)
    teardown = h.unmount

    const svg = h.wrapper.find('[data-test="auth-footer-monogram"] svg')
    expect(svg.attributes('aria-hidden')).toBe('true')
    expect(svg.attributes('role')).toBe('presentation')
  })

  it('places the monogram in its own row above the content, not behind it', async () => {
    const h = await mountAuthPage(AuthMarketingFooter)
    teardown = h.unmount

    // The reported bug: the monogram was an absolutely-positioned
    // backdrop pinned to the bottom edge, so it sat behind the link
    // columns. Both references (the Figma frame and the live site) give
    // it a leading flow row instead. jsdom applies no scoped CSS, so the
    // guard is the DOM contract that makes the layout possible: the
    // monogram is the first child of the content stack, ahead of the
    // wordmark, rather than a sibling of that stack.
    const rows = Array.from(h.wrapper.find('.auth-footer__content').element.children)
    expect(rows[0]?.classList.contains('auth-footer__visual')).toBe(true)
    expect(rows[0]?.querySelector('[data-test="auth-footer-monogram"]')).not.toBeNull()
    expect(rows[1]?.classList.contains('auth-footer__logo')).toBe(true)
  })

  it('renders translated navigation when the locale changes', async () => {
    const h = await mountAuthPage(AuthMarketingFooter, { locale: 'pt' })
    teardown = h.unmount

    const nav = h.wrapper.find('[data-test="auth-footer-nav"]')
    expect(nav.text()).toContain('Início')
    expect(nav.text()).toContain('Casos de estudo')
    expect(h.wrapper.find('[data-test="auth-footer-privacy"]').text()).toBe(
      'Política de privacidade',
    )
  })
})
