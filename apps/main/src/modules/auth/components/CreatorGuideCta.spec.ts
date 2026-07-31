/**
 * Component tests for the creator-guide CTA on the rebrand sign-in
 * landing. The load-bearing behaviour is the download link: it must
 * point at the committed PDF, open in a new tab so the sign-in form is
 * never navigated away from, and carry the anti-tabnabbing rel.
 */

import { afterEach, describe, expect, it } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import CreatorGuideCta from './CreatorGuideCta.vue'

describe('CreatorGuideCta', () => {
  let teardown: (() => void) | null = null

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('renders the heading, body and CTA from the i18n bundle', async () => {
    const h = await mountAuthPage(CreatorGuideCta)
    teardown = h.unmount

    const text = h.wrapper.find('[data-test="auth-guide-cta"]').text()
    expect(text).toContain("Still not sure if it's right for you?")
    expect(text).toContain('comprehensive guide to shooting content')
    expect(text).toContain('Download creator guide')
  })

  it('points the download at the committed PDF at the web root', async () => {
    const h = await mountAuthPage(CreatorGuideCta)
    teardown = h.unmount

    const link = h.wrapper.find('[data-test="auth-guide-download"]')
    expect(link.element.tagName).toBe('A')
    expect(link.attributes('href')).toBe('/creator-guide.pdf')
  })

  it('opens the PDF in a new tab so sign-in is not navigated away from', async () => {
    const h = await mountAuthPage(CreatorGuideCta)
    teardown = h.unmount

    const link = h.wrapper.find('[data-test="auth-guide-download"]')
    expect(link.attributes('target')).toBe('_blank')
    // noopener is the load-bearing half (tabnabbing); noreferrer is
    // belt-and-braces for older engines.
    expect(link.attributes('rel')).toContain('noopener')
  })

  it('does NOT force a download — the button opens the guide', async () => {
    const h = await mountAuthPage(CreatorGuideCta)
    teardown = h.unmount

    // A `download` attribute would save the file instead of opening it
    // in the browser's viewer, which is the opposite of the ask.
    expect(
      h.wrapper.find('[data-test="auth-guide-download"]').attributes('download'),
    ).toBeUndefined()
  })

  it('renders the three guide photographs as decorative images', async () => {
    const h = await mountAuthPage(CreatorGuideCta)
    teardown = h.unmount

    const images = h.wrapper.findAll('.guide-cta__card')
    expect(images).toHaveLength(3)
    for (const image of images) {
      // Decorative: the copy beside them already carries the meaning,
      // so an empty alt keeps them out of the accessibility tree.
      expect(image.attributes('alt')).toBe('')
      expect(image.attributes('src')).toBeTruthy()
      expect(image.attributes('loading')).toBe('lazy')
    }
  })

  it('renders the translated copy when the locale changes', async () => {
    const h = await mountAuthPage(CreatorGuideCta, { locale: 'it' })
    teardown = h.unmount

    const text = h.wrapper.find('[data-test="auth-guide-cta"]').text()
    expect(text).toContain('Non sei ancora sicuro che sia adatto a te?')
    expect(text).toContain('Scarica la guida per creator')
  })
})
