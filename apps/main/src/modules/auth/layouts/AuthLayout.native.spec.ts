/**
 * AH-100b D2 — the native sign-in sheds the website. Inside the Capacitor
 * creator app the sign-in landing renders the hero heading, the sign-in
 * card (the slot) — and nothing below it: no case-study logo wall, no
 * creator-guide CTA, no web footer. On the web the same route renders all
 * of it, so both directions are pinned here; the web layout specs
 * (`AuthLayout.spec.ts`, `SignInPage.spec.ts`) are untouched.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import AuthLayout from './AuthLayout.vue'
import { showsMarketingTail } from './marketingTail'
import { usesNativeSheet } from './nativeSheet'

const platform = vi.hoisted(() => ({ native: true }))

vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
  nativePlatform: () => (platform.native ? 'ios' : null),
}))

const TAIL = [
  '[data-test="auth-brand-wall"]',
  '[data-test="auth-guide-cta"]',
  '[data-test="auth-marketing-footer"]',
  '[data-test="auth-footer-nav"]',
  '[data-test="auth-footer-social"]',
]

async function mountSignInLanding() {
  return mountAuthPage(
    {
      components: { AuthLayout },
      template: `<AuthLayout><div data-test="slot-child">card ok</div></AuthLayout>`,
    },
    { initialRoute: { name: 'auth.sign-in' } },
  )
}

describe('AuthLayout — the creator app sign-in (native)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    platform.native = true
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  // AH-101 U2 — Figma frame 02: the landing is a bottom sheet under the brand
  // mark. The hero headline belongs to the web landing and is not drawn.
  it('arranges the landing as the sheet: native modifier, the card, and no hero headline', async () => {
    const h = await mountSignInLanding()
    teardown = h.unmount

    const layout = h.wrapper.find('.auth-layout')
    expect(layout.classes()).toContain('auth-layout--hero')
    expect(layout.classes()).toContain('auth-layout--native')
    expect(h.wrapper.find('[data-test="auth-hero"]').exists()).toBe(false)
    expect(h.wrapper.text()).not.toContain("Create UGC ads for the world's best brands.")
    expect(h.wrapper.find('[data-test="auth-card"] [data-test="slot-child"]').exists()).toBe(true)
  })

  it('keeps the brand heading and the language control (the pill) for assistive tech and use', async () => {
    const h = await mountSignInLanding()
    teardown = h.unmount

    expect(h.wrapper.find('[data-test="auth-brand"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="auth-locale-switcher"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="auth-locale-switcher"] .mdi-web').exists()).toBe(true)
  })

  it.each(TAIL)('does not render %s', async (selector) => {
    const h = await mountSignInLanding()
    teardown = h.unmount

    expect(h.wrapper.find(selector).exists()).toBe(false)
  })

  it('carries no website-footer content at all (nav links, privacy, social)', async () => {
    const h = await mountSignInLanding()
    teardown = h.unmount

    expect(h.wrapper.find('footer').exists()).toBe(false)
    expect(h.wrapper.find('[data-test="auth-footer-privacy"]').exists()).toBe(false)
  })

  it('the sheet carries no hero copy at all: no body, no "case studies below" footnote', async () => {
    const h = await mountSignInLanding()
    teardown = h.unmount

    expect(h.wrapper.find('.auth-hero__body').exists()).toBe(false)
    expect(h.wrapper.text()).not.toContain('case studies')
    expect(h.wrapper.text()).not.toContain('Sign up to work with')
  })

  it('other auth pages (centred card, no hero) are unaffected', async () => {
    const h = await mountAuthPage(
      {
        components: { AuthLayout },
        template: `<AuthLayout><div data-test="slot-child">card ok</div></AuthLayout>`,
      },
      { initialRoute: { name: 'auth.forgot-password' } },
    )
    teardown = h.unmount

    expect(h.wrapper.find('[data-test="auth-hero"]').exists()).toBe(false)
    expect(h.wrapper.find('.auth-layout').classes()).not.toContain('auth-layout--native')
    expect(h.wrapper.find('[data-test="slot-child"]').exists()).toBe(true)
  })
})

describe('AuthLayout — the web sign-in keeps the full landing (polarity)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    platform.native = false
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it.each(TAIL)('renders %s', async (selector) => {
    const h = await mountSignInLanding()
    teardown = h.unmount

    expect(h.wrapper.find(selector).exists()).toBe(true)
  })

  it('has no sheet modifier, keeps the hero headline, and no globe on the language control', async () => {
    const h = await mountSignInLanding()
    teardown = h.unmount

    expect(h.wrapper.find('.auth-layout').classes()).not.toContain('auth-layout--native')
    expect(h.wrapper.find('.auth-hero__headline').text()).toBe(
      "Create UGC ads for the world's best brands.",
    )
    expect(h.wrapper.find('[data-test="auth-locale-switcher"] .mdi-web').exists()).toBe(false)
  })

  it('renders the hero body and the case-studies footnote', async () => {
    const h = await mountSignInLanding()
    teardown = h.unmount

    expect(h.wrapper.findAll('.auth-hero__body')).toHaveLength(2)
    expect(h.wrapper.text()).toContain('case studies')
  })

  it('a non-hero page (forgot-password) has no tail on web either', async () => {
    const h = await mountAuthPage(
      { components: { AuthLayout }, template: `<AuthLayout><div /></AuthLayout>` },
      { initialRoute: { name: 'auth.forgot-password' } },
    )
    teardown = h.unmount

    for (const selector of TAIL) {
      expect(h.wrapper.find(selector).exists()).toBe(false)
    }
  })

  it('the agency sign-in variant keeps the tail on web too', async () => {
    const h = await mountAuthPage(
      {
        components: { AuthLayout },
        template: `<AuthLayout><div /></AuthLayout>`,
      },
      { initialRoute: { name: 'auth.agency-sign-in' } },
    )
    teardown = h.unmount

    expect(h.wrapper.find('[data-test="auth-marketing-footer"]').exists()).toBe(true)
  })
})

describe('usesNativeSheet', () => {
  it.each([
    [true, true, true],
    [true, false, false],
    [false, true, false],
    [false, false, false],
  ])('hero=%s native=%s → %s', (isHero, isNative, expected) => {
    expect(usesNativeSheet(isHero, isNative)).toBe(expected)
  })

  it('defaults the platform to the live probe', () => {
    platform.native = true
    expect(usesNativeSheet(true)).toBe(true)
    platform.native = false
    expect(usesNativeSheet(true)).toBe(false)
  })
})

describe('showsMarketingTail', () => {
  it.each([
    [true, false, true],
    [true, true, false],
    [false, false, false],
    [false, true, false],
  ])('hero=%s native=%s → %s', (isHero, isNative, expected) => {
    expect(showsMarketingTail(isHero, isNative)).toBe(expected)
  })

  it('defaults the platform to the live probe', () => {
    platform.native = true
    expect(showsMarketingTail(true)).toBe(false)
    platform.native = false
    expect(showsMarketingTail(true)).toBe(true)
  })
})
