/**
 * Vitest global setup. Polyfills browser APIs that jsdom does not provide
 * but Vuetify (and many other UI libraries) depend on.
 */

import { vi } from 'vitest'

class ResizeObserverStub {
  observe = vi.fn()
  unobserve = vi.fn()
  disconnect = vi.fn()
}

class IntersectionObserverStub {
  root = null
  rootMargin = ''
  thresholds: ReadonlyArray<number> = []
  observe = vi.fn()
  unobserve = vi.fn()
  disconnect = vi.fn()
  takeRecords = vi.fn(() => [] as IntersectionObserverEntry[])
}

if (typeof globalThis.ResizeObserver === 'undefined') {
  globalThis.ResizeObserver = ResizeObserverStub as unknown as typeof ResizeObserver
}

if (typeof globalThis.IntersectionObserver === 'undefined') {
  globalThis.IntersectionObserver =
    IntersectionObserverStub as unknown as typeof IntersectionObserver
}

if (!('matchMedia' in globalThis)) {
  Object.defineProperty(globalThis, 'matchMedia', {
    writable: true,
    value: vi.fn().mockImplementation((query: string) => ({
      matches: false,
      media: query,
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  })
}

if (typeof CSS === 'undefined' || typeof CSS.supports !== 'function') {
  Object.defineProperty(globalThis, 'CSS', {
    writable: true,
    value: { supports: () => false },
  })
}

/**
 * `visualViewport` polyfill — Vuetify's `VOverlay` (used by `v-dialog`
 * and `v-snackbar`) reads `window.visualViewport.scale` / `offsetTop`
 * etc. to position itself. JSDOM does not provide this API and Vuetify
 * throws a `ReferenceError: visualViewport is not defined` on overlay
 * activation without this stub. The shape mirrors the polyfill in
 * `apps/main/tests/unit/setup.ts`.
 */
if (typeof (globalThis as { visualViewport?: unknown }).visualViewport === 'undefined') {
  const stub = {
    width: 1024,
    height: 768,
    offsetLeft: 0,
    offsetTop: 0,
    pageLeft: 0,
    pageTop: 0,
    scale: 1,
    onresize: null,
    onscroll: null,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  }
  Object.defineProperty(globalThis, 'visualViewport', {
    writable: true,
    configurable: true,
    value: stub,
  })
  Object.defineProperty(globalThis.window ?? globalThis, 'visualViewport', {
    writable: true,
    configurable: true,
    value: stub,
  })
}
