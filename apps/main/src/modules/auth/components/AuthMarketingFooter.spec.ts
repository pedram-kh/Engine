/**
 * Component tests for the marketing footer on the rebrand sign-in
 * landing.
 *
 * The behaviour worth pinning is the outstanding-URL contract: until
 * Pedram supplies the real destinations, no navigation or social entry
 * may render as an anchor, because a dead `<a href="#">` is worse than
 * inert text. The one live link is the `mailto:` — that address is
 * known.
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

  it('renders navigation entries as inert text while the URLs are outstanding', async () => {
    const h = await mountAuthPage(AuthMarketingFooter)
    teardown = h.unmount

    const entries = h.wrapper.find('[data-test="auth-footer-nav"]').findAll('.auth-footer__link')
    for (const entry of entries) {
      expect(entry.element.tagName).toBe('SPAN')
      expect(entry.attributes('href')).toBeUndefined()
    }
  })

  it('renders the social entries as inert proper nouns', async () => {
    const h = await mountAuthPage(AuthMarketingFooter)
    teardown = h.unmount

    const social = h.wrapper.find('[data-test="auth-footer-social"]')
    expect(social.text()).toContain('Instagram')
    expect(social.text()).toContain('LinkedIn')
    for (const entry of social.findAll('.auth-footer__link')) {
      expect(entry.element.tagName).toBe('SPAN')
    }
  })

  it('renders "Privacy policy" as inert text too', async () => {
    const h = await mountAuthPage(AuthMarketingFooter)
    teardown = h.unmount

    const privacy = h.wrapper.find('[data-test="auth-footer-privacy"]')
    expect(privacy.text()).toBe('Privacy policy')
    expect(privacy.element.tagName).toBe('SPAN')
  })

  it('ships no dead anchors anywhere in the footer', async () => {
    const h = await mountAuthPage(AuthMarketingFooter)
    teardown = h.unmount

    // Disjoint-and-complete: the ONLY anchor the footer may render
    // right now is the mailto. Anything else means a placeholder href
    // leaked in.
    const anchors = h.wrapper.findAll('a')
    expect(anchors).toHaveLength(1)
    expect(anchors[0]?.attributes('href')).toBe('mailto:info@catalyst-growth.com')
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

    const monogram = h.wrapper.find('.auth-footer__monogram')
    expect(monogram.attributes('alt')).toBe('')
    expect(monogram.attributes('aria-hidden')).toBe('true')
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
