/**
 * AH-100 D7 pin (c) — the boot step adds no class, leaves the viewport
 * alone, loads no native stylesheet and leaves the system bars alone on the
 * web; inside the app it scopes `<html>`, covers the viewport, loads the
 * layer and sets light bar content.
 */

import { describe, expect, it, vi } from 'vitest'

import { bootNativeShell } from '@/core/platform/bootNativeShell'

const WEB_VIEWPORT = 'width=device-width, initial-scale=1.0'

function makeDocument(): Document {
  const doc = document.implementation.createHTMLDocument('t')
  const meta = doc.createElement('meta')
  meta.name = 'viewport'
  meta.content = WEB_VIEWPORT
  doc.head.appendChild(meta)
  return doc
}

function viewportOf(doc: Document): string {
  return doc.querySelector<HTMLMetaElement>('meta[name="viewport"]')?.content ?? ''
}

const noop = async () => undefined

describe('bootNativeShell', () => {
  it.each([
    ['explicit null platform', null],
    ['default platform probe under jsdom', undefined],
  ])('web (%s): touches nothing', async (_label, platform) => {
    const doc = makeDocument()
    const loadNativeCss = vi.fn(noop)
    const setBarsStyle = vi.fn(noop)

    await bootNativeShell(doc, platform, loadNativeCss, setBarsStyle)

    expect(doc.documentElement.className).toBe('')
    expect(viewportOf(doc)).toBe(WEB_VIEWPORT)
    expect(loadNativeCss).not.toHaveBeenCalled()
    expect(setBarsStyle).not.toHaveBeenCalled()
  })

  it.each(['ios', 'android'] as const)(
    '%s: scopes <html>, covers the viewport, loads the layer and darkens the bars',
    async (platform) => {
      const doc = makeDocument()
      const loadNativeCss = vi.fn(noop)
      const setBarsStyle = vi.fn(noop)

      await bootNativeShell(doc, platform, loadNativeCss, setBarsStyle)

      expect([...doc.documentElement.classList]).toEqual([
        'catalyst-native',
        `platform-${platform}`,
      ])
      expect(viewportOf(doc)).toBe(`${WEB_VIEWPORT}, viewport-fit=cover`)
      expect(loadNativeCss).toHaveBeenCalledTimes(1)
      expect(setBarsStyle).toHaveBeenCalledTimes(1)
    },
  )

  it('a failing system-bars call does not block the boot', async () => {
    const doc = makeDocument()
    const loadNativeCss = vi.fn(noop)

    await expect(
      bootNativeShell(doc, 'ios', loadNativeCss, () => Promise.reject(new Error('no bridge'))),
    ).resolves.toBeUndefined()
    expect(loadNativeCss).toHaveBeenCalledTimes(1)
  })

  it('an unanswered system-bars call does not block the boot', async () => {
    const doc = makeDocument()
    const never = () => new Promise<void>(() => undefined)

    await expect(bootNativeShell(doc, 'ios', noop, never)).resolves.toBeUndefined()
  })

  it('does not add viewport-fit twice, and tolerates a missing viewport meta', async () => {
    const doc = makeDocument()
    await bootNativeShell(doc, 'ios', noop, noop)
    await bootNativeShell(doc, 'ios', noop, noop)
    expect(viewportOf(doc)).toBe(`${WEB_VIEWPORT}, viewport-fit=cover`)

    const bare = document.implementation.createHTMLDocument('t')
    await expect(bootNativeShell(bare, 'android', noop, noop)).resolves.toBeUndefined()
  })
})
