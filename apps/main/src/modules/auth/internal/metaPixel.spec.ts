/**
 * Unit tests for the Meta Pixel loader.
 *
 * The load-bearing assertions here are the two that protect users rather
 * than the two that prove it works: that Automatic Advanced Matching is
 * disabled BEFORE `init` (afterwards is too late, and it is what would
 * otherwise send the email being typed into the login form to Meta), and
 * that re-mounting the page cannot inject a second vendor script.
 *
 * A fresh fake window/document is passed in per test rather than leaning
 * on the ambient jsdom globals, so no test can be polluted by a pixel
 * another one installed.
 */

import { beforeEach, describe, expect, it } from 'vitest'

import { loadMetaPixel } from './metaPixel'

const PIXEL_ID = '1372598514719791'
const SDK_SRC = 'https://connect.facebook.net/en_US/fbevents.js'

let doc: Document
let win: Window

beforeEach(() => {
  // A real detached document, so createElement/head.append behave as they
  // do in the browser, paired with a bare window stand-in.
  doc = document.implementation.createHTMLDocument('pixel')
  win = {} as Window
})

/** Every `fbq(...)` call recorded so far, in order. */
function calls(): unknown[][] {
  return (win.fbq?.queue ?? []) as unknown[][]
}

function injectedScripts(): HTMLScriptElement[] {
  return Array.from(doc.querySelectorAll('script'))
}

describe('loadMetaPixel', () => {
  it('requests the vendor bundle asynchronously', () => {
    loadMetaPixel(win, doc)

    const scripts = injectedScripts()
    expect(scripts).toHaveLength(1)
    expect(scripts[0]?.src).toBe(SDK_SRC)
    // Synchronous loading would block the sign-in form's first paint.
    expect(scripts[0]?.async).toBe(true)
  })

  it('disables automatic advanced matching BEFORE init', () => {
    loadMetaPixel(win, doc)

    const recorded = calls()
    const autoConfigAt = recorded.findIndex((c) => c[0] === 'set' && c[1] === 'autoConfig')
    const initAt = recorded.findIndex((c) => c[0] === 'init')

    expect(autoConfigAt).toBeGreaterThanOrEqual(0)
    expect(initAt).toBeGreaterThanOrEqual(0)
    // Ordering is the whole point: `set` after `init` is a no-op, and the
    // pixel would then scrape the login form's email field.
    expect(autoConfigAt).toBeLessThan(initAt)
    expect(recorded[autoConfigAt]).toEqual(['set', 'autoConfig', false, PIXEL_ID])
  })

  it('initialises the configured pixel and reports one PageView', () => {
    loadMetaPixel(win, doc)

    expect(calls()).toEqual([
      ['set', 'autoConfig', false, PIXEL_ID],
      ['init', PIXEL_ID],
      ['track', 'PageView'],
    ])
  })

  it('never injects a second script when the page is mounted again', () => {
    loadMetaPixel(win, doc)
    loadMetaPixel(win, doc)
    loadMetaPixel(win, doc)

    expect(injectedScripts()).toHaveLength(1)
  })

  it('records a further PageView on each remount, reusing the loaded pixel', () => {
    loadMetaPixel(win, doc)
    const first = win.fbq
    loadMetaPixel(win, doc)

    // Same callable, three more queued calls.
    expect(win.fbq).toBe(first)
    expect(calls()).toHaveLength(6)
    expect(calls().filter((c) => c[0] === 'track' && c[1] === 'PageView')).toHaveLength(2)
  })

  it('exposes the stub on both globals the vendor bundle looks for', () => {
    loadMetaPixel(win, doc)

    expect(typeof win.fbq).toBe('function')
    expect(win._fbq).toBe(win.fbq)
    expect(win.fbq?.version).toBe('2.0')
    expect(win.fbq?.loaded).toBe(true)
    // The vendor snippet's `n.push = n`; SDK integrations reach for it.
    expect(win.fbq?.push).toBe(win.fbq)
  })

  it('hands calls straight to the bundle once it has loaded', () => {
    loadMetaPixel(win, doc)

    // Simulate the vendor bundle arriving and taking over: from then on
    // calls must be forwarded, not buffered.
    const forwarded: unknown[][] = []
    const fbq = win.fbq
    expect(fbq).toBeDefined()
    const queuedBefore = calls().length
    if (fbq) {
      fbq.callMethod = (...args: readonly unknown[]) => {
        forwarded.push([...args])
      }
    }

    loadMetaPixel(win, doc)

    expect(forwarded).toEqual([
      ['set', 'autoConfig', false, PIXEL_ID],
      ['init', PIXEL_ID],
      ['track', 'PageView'],
    ])
    // Nothing new should have been buffered behind the live bundle.
    expect(calls()).toHaveLength(queuedBefore)
  })

  it('adopts a pixel another script already installed', () => {
    // If anything else on the page has already booted the pixel, we must
    // use it rather than clobbering its queue with a fresh stub.
    const seen: unknown[][] = []
    const preexisting = ((...args: readonly unknown[]) => {
      seen.push([...args])
    }) as Window['fbq'] & object
    win.fbq = preexisting

    loadMetaPixel(win, doc)

    expect(win.fbq).toBe(preexisting)
    expect(injectedScripts()).toHaveLength(0)
    expect(seen).toEqual([
      ['set', 'autoConfig', false, PIXEL_ID],
      ['init', PIXEL_ID],
      ['track', 'PageView'],
    ])
  })
})
