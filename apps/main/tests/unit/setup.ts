/**
 * Vitest global setup. Polyfills browser APIs that jsdom does not provide
 * but Vuetify (and many other UI libraries) depend on.
 */

import { beforeEach, vi } from 'vitest'

import { INSTALL_MARKER_KEY, resetInstallReconcileForTests } from '@/core/auth/installMarker'

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

// Sprint 3 Chunk 4 sub-step 6 — Vuetify's VOverlay (used by `v-dialog`,
// `v-menu`, `v-snackbar`, etc.) reads `visualViewport` to drive its
// position strategy. jsdom does not implement it. The dialog body
// gets teleported to `document.body` via `<v-teleport>`, so the
// surface tests need a working viewport stub to render the modal.
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

// AH-103d — the reinstall marker. In production the marker is written by the first
// launch; here every test starts as an install that has already run, so no spec
// outside the marker's own is silently signed out (or has its token read an extra
// time) by the reconcile gate in the auth store. The marker's own specs clear it
// explicitly. The memoised run is forgotten so one test's reconcile never leaks.
beforeEach(() => {
  if (typeof window !== 'undefined' && window.localStorage !== undefined) {
    window.localStorage.setItem(INSTALL_MARKER_KEY, 'test-setup')
  }
  resetInstallReconcileForTests()
})
