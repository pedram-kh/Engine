/**
 * Vitest global setup for `@catalyst/ui` (Sprint 4 Chunk 1 sub-step 1a).
 *
 * Polyfills the browser APIs jsdom does not provide but Vuetify depends on.
 * Byte-for-byte mirror of `apps/main/tests/unit/setup.ts` — the shared
 * components mount real Vuetify, so the harness needs the same
 * ResizeObserver / IntersectionObserver / matchMedia / CSS.supports /
 * visualViewport stubs the SPA suite already relies on.
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

// Vuetify's VOverlay (v-dialog, v-menu, v-snackbar, …) reads
// `visualViewport` to drive its position strategy; jsdom does not
// implement it. Mirrors the apps/main stub so overlay-bearing shared
// components render under the harness.
if (typeof globalThis.visualViewport === 'undefined') {
  Object.defineProperty(globalThis, 'visualViewport', {
    writable: true,
    value: {
      width: 1024,
      height: 768,
      offsetLeft: 0,
      offsetTop: 0,
      pageLeft: 0,
      pageTop: 0,
      scale: 1,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(() => true),
    },
  })
}
