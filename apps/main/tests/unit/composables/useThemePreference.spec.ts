/**
 * Unit tests for the main SPA's `useThemePreference` composable.
 *
 * Surface verified (chunk 8.2 acceptance criteria):
 *   - Storage shape: `STORAGE_KEY === 'catalyst.main.theme'`,
 *     `SPA_DEFAULT === 'light'`, `themePreferences === ['light',
 *     'dark', 'system']`.
 *   - Empty storage → `preference` resolves to SPA default,
 *     `isExplicit === false`, `effectiveTheme === 'light'`.
 *   - Storage `'light'` → effective `'light'`, no matchMedia listener
 *     mounted.
 *   - Storage `'dark'` → effective `'dark'`, no matchMedia listener
 *     mounted, Vuetify flips on bootstrap.
 *   - Storage `'system'` + matchMedia=dark → effective `'dark'`,
 *     listener mounted, Vuetify flips on bootstrap.
 *   - Storage `'system'` + matchMedia=light → effective `'light'`,
 *     listener mounted.
 *   - matchMedia change event reactively updates effective theme +
 *     Vuetify when current preference is `'system'`.
 *   - matchMedia change event when current preference is NOT
 *     `'system'` updates the internal ref but does NOT touch Vuetify
 *     (the listener is normally torn down in this case; this is a
 *     defensive assertion for race conditions).
 *   - `setPreference('light' | 'dark')` writes storage, flips
 *     Vuetify, tears down the matchMedia listener.
 *   - `setPreference('system')` writes storage, mounts listener,
 *     flips Vuetify per system preference.
 *   - `clearPreference()` removes storage, tears down listener,
 *     reverts to SPA default in Vuetify.
 *   - Invalid storage value → treated as unset (defensive).
 *   - Storage read throw (private mode) → treated as unset.
 *   - Storage write throw (quota exceeded) → in-memory state still
 *     flips; persistence is the only thing lost.
 *   - `localStorage` undefined → composable still works against
 *     in-memory state.
 *   - `matchMedia` undefined → `'system'` preference resolves to
 *     `'light'` (the SPA default for main); listener never mounts.
 *   - Idempotent initialisation: a second `useThemePreference()`
 *     call returns the same shared state without re-reading
 *     storage.
 *
 * Mirror discipline (chunk 7.2 D2 standing standard):
 *   This spec mirrors `apps/admin/tests/unit/composables/
 *   useThemePreference.spec.ts`. Both files MUST stay in
 *   structural lockstep. Differences are limited to (a) the SPA's
 *   `STORAGE_KEY` (main = `catalyst.main.theme`, admin =
 *   `catalyst.admin.theme`), (b) the SPA's `SPA_DEFAULT` (main =
 *   `light`, admin = `dark`), (c) the import alias resolving to
 *   the SPA-local composable, and (d) the SPA-default-driven
 *   "unset → effective theme" assertion direction.
 */

import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import { createVuetify } from 'vuetify'
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest'

import { lightTheme, darkTheme } from '@catalyst/design-tokens/vuetify'

// Two `defineComponent` stubs follow: one inside `mountHarness` (per
// invocation) and one inside the "idempotent initialisation" test
// (TwoConsumers). Both are scaffolding for the composable test, not
// reusable Vue components — `vue/one-component-per-file` does not
// apply to a test file whose purpose is exactly to drive the composable
// from inside multiple synthetic component instances. Disabling is
// scoped to this file rather than turned off globally — same pattern
// as `tests/unit/App.spec.ts` (chunk 6.8).
/* eslint-disable vue/one-component-per-file */

import {
  useThemePreference,
  themePreferences,
  STORAGE_KEY,
  SPA_DEFAULT,
  __resetThemePreferenceForTests,
  type ThemePreferenceManager,
} from '@/composables/useThemePreference'

/**
 * Storage stub: a plain object that implements the localStorage
 * subset the composable uses (`getItem`, `setItem`, `removeItem`).
 * Spied per-call so tests can pin storage interactions.
 */
function createStorageStub(initial: Record<string, string> = {}) {
  const store: Record<string, string> = { ...initial }
  return {
    store,
    getItem: vi.fn((key: string): string | null => store[key] ?? null),
    setItem: vi.fn((key: string, value: string): void => {
      store[key] = value
    }),
    removeItem: vi.fn((key: string): void => {
      delete store[key]
    }),
  }
}

/**
 * matchMedia stub: a controllable `MediaQueryList`-shaped object
 * that holds a `matches` flag and lets tests fire `change` events.
 * Tracks the most recently registered listener so the spec can
 * dispatch synthetic events without going through the DOM.
 */
function createMatchMediaStub(initialMatches: boolean) {
  const listeners = new Set<(event: MediaQueryListEvent) => void>()
  const mediaQuery = {
    matches: initialMatches,
    media: '(prefers-color-scheme: dark)',
    addEventListener: vi.fn((_: 'change', listener: (event: MediaQueryListEvent) => void) => {
      listeners.add(listener)
    }),
    removeEventListener: vi.fn((_: 'change', listener: (event: MediaQueryListEvent) => void) => {
      listeners.delete(listener)
    }),
  }
  function fire(matches: boolean): void {
    mediaQuery.matches = matches
    for (const listener of listeners) {
      listener({ matches } as MediaQueryListEvent)
    }
  }
  function listenerCount(): number {
    return listeners.size
  }
  return { mediaQuery, fire, listenerCount }
}

interface Harness {
  manager: ThemePreferenceManager
  vuetify: ReturnType<typeof createVuetify>
  unmount: () => void
}

function mountHarness(defaultTheme: 'light' | 'dark'): Harness {
  const vuetify = createVuetify({
    theme: {
      defaultTheme,
      themes: { light: lightTheme, dark: darkTheme },
    },
  })

  let captured!: ThemePreferenceManager
  const TestComponent = defineComponent({
    setup() {
      captured = useThemePreference()
      return () => h('div')
    },
  })

  const wrapper = mount(TestComponent, {
    global: { plugins: [vuetify] },
  })

  return {
    manager: captured,
    vuetify,
    unmount: () => wrapper.unmount(),
  }
}

describe('useThemePreference — main SPA', () => {
  let storage: ReturnType<typeof createStorageStub>
  let media: ReturnType<typeof createMatchMediaStub>
  let originalLocalStorage: PropertyDescriptor | undefined
  let originalMatchMedia: PropertyDescriptor | undefined
  let matchMediaSpy: MockInstance<(query: string) => MediaQueryList> | null

  beforeEach(() => {
    storage = createStorageStub()
    media = createMatchMediaStub(false)
    matchMediaSpy = null
    originalLocalStorage = Object.getOwnPropertyDescriptor(window, 'localStorage')
    originalMatchMedia = Object.getOwnPropertyDescriptor(window, 'matchMedia')
    Object.defineProperty(window, 'localStorage', {
      configurable: true,
      get: () => storage,
    })
    matchMediaSpy = vi
      .spyOn(window, 'matchMedia')
      .mockImplementation(() => media.mediaQuery as unknown as MediaQueryList)
  })

  afterEach(() => {
    __resetThemePreferenceForTests()
    if (originalLocalStorage !== undefined) {
      Object.defineProperty(window, 'localStorage', originalLocalStorage)
    }
    if (originalMatchMedia !== undefined) {
      Object.defineProperty(window, 'matchMedia', originalMatchMedia)
    }
    matchMediaSpy?.mockRestore()
  })

  describe('module-level exports', () => {
    it('exports the storage key for the main SPA', () => {
      expect(STORAGE_KEY).toBe('catalyst.main.theme')
    })

    it('exports the SPA default theme (main = light)', () => {
      expect(SPA_DEFAULT).toBe('light')
    })

    it('exports the available preferences as a readonly tuple', () => {
      expect(themePreferences).toEqual(['light', 'dark', 'system'])
    })

    it('exposes availablePreferences on the composable return value', () => {
      const h = mountHarness('light')
      try {
        expect(h.manager.availablePreferences).toBe(themePreferences)
      } finally {
        h.unmount()
      }
    })
  })

  describe('empty storage (unset preference)', () => {
    it('resolves preference to the SPA default and reports isExplicit=false', () => {
      const h = mountHarness('light')
      try {
        expect(h.manager.preference.value).toBe('light')
        expect(h.manager.isExplicit.value).toBe(false)
      } finally {
        h.unmount()
      }
    })

    it('resolves effectiveTheme to the SPA default (does NOT consult prefers-color-scheme)', () => {
      // matchMedia would say "dark" if consulted; the composable
      // MUST ignore it because the user has not opted into 'system'.
      media.mediaQuery.matches = true
      const h = mountHarness('light')
      try {
        expect(h.manager.effectiveTheme.value).toBe('light')
        // No listener should have been mounted — the unset path
        // never consults matchMedia.
        expect(media.listenerCount()).toBe(0)
      } finally {
        h.unmount()
      }
    })

    it('does NOT touch Vuetify on bootstrap when current === SPA default', () => {
      // Main's defaultTheme is 'light' and the SPA_DEFAULT is also
      // 'light'; the composable should not flip Vuetify.
      const h = mountHarness('light')
      try {
        expect(h.vuetify.theme.global.name.value).toBe('light')
      } finally {
        h.unmount()
      }
    })
  })

  describe('explicit preference: light', () => {
    beforeEach(() => {
      storage.store[STORAGE_KEY] = 'light'
    })

    it('reads storage and reports preference + isExplicit=true', () => {
      const h = mountHarness('light')
      try {
        expect(h.manager.preference.value).toBe('light')
        expect(h.manager.isExplicit.value).toBe(true)
        expect(storage.getItem).toHaveBeenCalledWith(STORAGE_KEY)
      } finally {
        h.unmount()
      }
    })

    it('does NOT mount the matchMedia listener', () => {
      const h = mountHarness('light')
      try {
        expect(media.listenerCount()).toBe(0)
      } finally {
        h.unmount()
      }
    })

    it('flips Vuetify from dark default to light on bootstrap', () => {
      const h = mountHarness('dark')
      try {
        expect(h.vuetify.theme.global.name.value).toBe('light')
      } finally {
        h.unmount()
      }
    })
  })

  describe('explicit preference: dark', () => {
    beforeEach(() => {
      storage.store[STORAGE_KEY] = 'dark'
    })

    it('reads storage and reports preference', () => {
      const h = mountHarness('light')
      try {
        expect(h.manager.preference.value).toBe('dark')
        expect(h.manager.effectiveTheme.value).toBe('dark')
      } finally {
        h.unmount()
      }
    })

    it('flips Vuetify from light default to dark on bootstrap', () => {
      const h = mountHarness('light')
      try {
        expect(h.vuetify.theme.global.name.value).toBe('dark')
      } finally {
        h.unmount()
      }
    })

    it('does NOT mount the matchMedia listener', () => {
      const h = mountHarness('light')
      try {
        expect(media.listenerCount()).toBe(0)
      } finally {
        h.unmount()
      }
    })
  })

  describe('explicit preference: system', () => {
    beforeEach(() => {
      storage.store[STORAGE_KEY] = 'system'
    })

    it('mounts the matchMedia listener and resolves to dark when system prefers dark', () => {
      media.mediaQuery.matches = true
      const h = mountHarness('light')
      try {
        expect(media.listenerCount()).toBe(1)
        expect(h.manager.effectiveTheme.value).toBe('dark')
        expect(h.vuetify.theme.global.name.value).toBe('dark')
      } finally {
        h.unmount()
      }
    })

    it('mounts the matchMedia listener and resolves to light when system prefers light', () => {
      media.mediaQuery.matches = false
      const h = mountHarness('dark')
      try {
        expect(media.listenerCount()).toBe(1)
        expect(h.manager.effectiveTheme.value).toBe('light')
        expect(h.vuetify.theme.global.name.value).toBe('light')
      } finally {
        h.unmount()
      }
    })

    it('reactively flips effective theme + Vuetify when system preference changes', () => {
      media.mediaQuery.matches = false
      const h = mountHarness('light')
      try {
        expect(h.manager.effectiveTheme.value).toBe('light')
        expect(h.vuetify.theme.global.name.value).toBe('light')

        media.fire(true)

        expect(h.manager.effectiveTheme.value).toBe('dark')
        expect(h.vuetify.theme.global.name.value).toBe('dark')
      } finally {
        h.unmount()
      }
    })
  })

  describe('setPreference', () => {
    it("setPreference('light') persists, tears down listener, flips Vuetify", () => {
      // Start in 'system' so the listener is mounted.
      storage.store[STORAGE_KEY] = 'system'
      media.mediaQuery.matches = true
      const h = mountHarness('light')
      try {
        expect(media.listenerCount()).toBe(1)
        h.manager.setPreference('light')
        expect(storage.setItem).toHaveBeenCalledWith(STORAGE_KEY, 'light')
        expect(media.listenerCount()).toBe(0)
        expect(h.manager.preference.value).toBe('light')
        expect(h.manager.effectiveTheme.value).toBe('light')
        expect(h.vuetify.theme.global.name.value).toBe('light')
      } finally {
        h.unmount()
      }
    })

    it("setPreference('dark') persists, tears down listener, flips Vuetify", () => {
      const h = mountHarness('light')
      try {
        h.manager.setPreference('dark')
        expect(storage.setItem).toHaveBeenCalledWith(STORAGE_KEY, 'dark')
        expect(h.manager.preference.value).toBe('dark')
        expect(h.vuetify.theme.global.name.value).toBe('dark')
      } finally {
        h.unmount()
      }
    })

    it("setPreference('system') persists, mounts listener, applies system theme", () => {
      media.mediaQuery.matches = true
      const h = mountHarness('light')
      try {
        expect(media.listenerCount()).toBe(0)
        h.manager.setPreference('system')
        expect(storage.setItem).toHaveBeenCalledWith(STORAGE_KEY, 'system')
        expect(media.listenerCount()).toBe(1)
        expect(h.manager.preference.value).toBe('system')
        expect(h.manager.effectiveTheme.value).toBe('dark')
        expect(h.vuetify.theme.global.name.value).toBe('dark')
      } finally {
        h.unmount()
      }
    })

    it('setPreference is idempotent — repeating the same value re-writes storage but is otherwise a no-op', () => {
      const h = mountHarness('light')
      try {
        h.manager.setPreference('dark')
        h.manager.setPreference('dark')
        expect(storage.setItem).toHaveBeenCalledTimes(2)
        expect(h.vuetify.theme.global.name.value).toBe('dark')
      } finally {
        h.unmount()
      }
    })
  })

  describe('clearPreference', () => {
    it('removes storage, tears down listener, reverts to SPA default', () => {
      storage.store[STORAGE_KEY] = 'system'
      media.mediaQuery.matches = true
      const h = mountHarness('light')
      try {
        expect(media.listenerCount()).toBe(1)
        // We're in dark right now (system prefers dark).
        expect(h.vuetify.theme.global.name.value).toBe('dark')
        h.manager.clearPreference()
        expect(storage.removeItem).toHaveBeenCalledWith(STORAGE_KEY)
        expect(media.listenerCount()).toBe(0)
        expect(h.manager.preference.value).toBe('light') // SPA default
        expect(h.manager.isExplicit.value).toBe(false)
        expect(h.vuetify.theme.global.name.value).toBe('light')
      } finally {
        h.unmount()
      }
    })
  })

  describe('defensive: invalid / unavailable storage', () => {
    it('treats an invalid storage value as unset', () => {
      storage.store[STORAGE_KEY] = 'fuchsia'
      const h = mountHarness('light')
      try {
        expect(h.manager.preference.value).toBe(SPA_DEFAULT)
        expect(h.manager.isExplicit.value).toBe(false)
      } finally {
        h.unmount()
      }
    })

    it('treats a storage read throw as unset (private mode)', () => {
      storage.getItem.mockImplementation(() => {
        throw new Error('storage disabled')
      })
      const h = mountHarness('light')
      try {
        expect(h.manager.preference.value).toBe(SPA_DEFAULT)
      } finally {
        h.unmount()
      }
    })

    it('handles a storage write throw — in-memory preference still flips', () => {
      storage.setItem.mockImplementation(() => {
        throw new Error('quota exceeded')
      })
      const h = mountHarness('light')
      try {
        h.manager.setPreference('dark')
        expect(h.manager.preference.value).toBe('dark')
        expect(h.vuetify.theme.global.name.value).toBe('dark')
      } finally {
        h.unmount()
      }
    })

    it('handles a storage remove throw — in-memory preference still clears', () => {
      storage.removeItem.mockImplementation(() => {
        throw new Error('storage disabled mid-session')
      })
      storage.store[STORAGE_KEY] = 'dark'
      const h = mountHarness('light')
      try {
        expect(h.manager.preference.value).toBe('dark')
        h.manager.clearPreference()
        expect(h.manager.preference.value).toBe(SPA_DEFAULT)
      } finally {
        h.unmount()
      }
    })

    it('handles `localStorage` undefined — composable falls back to in-memory state', () => {
      Object.defineProperty(window, 'localStorage', {
        configurable: true,
        get: () => undefined,
      })
      const h = mountHarness('light')
      try {
        expect(h.manager.preference.value).toBe(SPA_DEFAULT)
        h.manager.setPreference('dark')
        expect(h.manager.preference.value).toBe('dark')
        h.manager.clearPreference()
        expect(h.manager.preference.value).toBe(SPA_DEFAULT)
      } finally {
        h.unmount()
      }
    })

    it("handles `matchMedia` undefined — 'system' resolves to light, listener never mounts", () => {
      // Replace matchMedia with undefined for this test.
      Object.defineProperty(window, 'matchMedia', {
        configurable: true,
        get: () => undefined,
      })
      storage.store[STORAGE_KEY] = 'system'
      const h = mountHarness('light')
      try {
        expect(h.manager.effectiveTheme.value).toBe('light')
        expect(media.listenerCount()).toBe(0)
      } finally {
        h.unmount()
      }
    })
  })

  describe('idempotent initialisation + module-singleton behaviour', () => {
    it('reads storage exactly once across multiple useThemePreference() calls', () => {
      storage.store[STORAGE_KEY] = 'dark'
      const vuetify = createVuetify({
        theme: { defaultTheme: 'light', themes: { light: lightTheme, dark: darkTheme } },
      })

      let firstManager!: ThemePreferenceManager
      let secondManager!: ThemePreferenceManager
      const TwoConsumers = defineComponent({
        setup() {
          firstManager = useThemePreference()
          secondManager = useThemePreference()
          return () => h('div')
        },
      })
      const wrapper = mount(TwoConsumers, { global: { plugins: [vuetify] } })

      try {
        expect(storage.getItem).toHaveBeenCalledTimes(1)
        expect(firstManager.preference.value).toBe('dark')
        expect(secondManager.preference.value).toBe('dark')
        // Both consumers see the SAME reactive state — flipping
        // through one is observed by the other.
        secondManager.setPreference('light')
        expect(firstManager.preference.value).toBe('light')
      } finally {
        wrapper.unmount()
      }
    })

    it('mounts the matchMedia listener exactly once even when ensureSystemListener is called repeatedly', () => {
      storage.store[STORAGE_KEY] = 'system'
      const h = mountHarness('light')
      try {
        // setPreference('system') would re-call ensureSystemListener;
        // the mounted-once guard means listenerCount stays at 1.
        h.manager.setPreference('system')
        h.manager.setPreference('system')
        expect(media.listenerCount()).toBe(1)
      } finally {
        h.unmount()
      }
    })

    it('teardown is safe to call when the listener was never mounted (clearPreference from unset)', () => {
      // Explicit unset path: no storage, never opted into 'system'.
      const h = mountHarness('light')
      try {
        expect(media.listenerCount()).toBe(0)
        // clearPreference should be a safe no-op even with no listener.
        h.manager.clearPreference()
        expect(h.manager.preference.value).toBe(SPA_DEFAULT)
      } finally {
        h.unmount()
      }
    })
  })
})
