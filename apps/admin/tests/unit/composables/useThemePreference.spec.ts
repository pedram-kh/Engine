/**
 * Unit tests for the admin SPA's `useThemePreference` composable.
 *
 * Binary model (Sprint 3.5 Chunk 1 — `'system'` dropped):
 *   - Storage shape: `STORAGE_KEY === 'catalyst.admin.theme'`,
 *     `SPA_DEFAULT === 'dark'`, `themePreferences === ['light', 'dark']`.
 *   - Empty storage → `preference` resolves to the SPA default,
 *     `isExplicit === false`, `effectiveTheme === 'dark'`.
 *   - Storage `'light'` → effective `'light'`, Vuetify flips from a
 *     dark bootstrap.
 *   - Storage `'dark'` → effective `'dark'`.
 *   - Legacy storage `'system'` (written by chunk 8.2) → treated as
 *     unset → SPA default, `isExplicit === false`. Migration is
 *     passive-on-read: the composable reads storage but does NOT
 *     rewrite it (no setItem / removeItem during initialisation).
 *   - `setPreference('light' | 'dark')` writes storage + flips Vuetify.
 *   - `clearPreference()` removes storage, reverts to SPA default.
 *   - Invalid storage value → treated as unset (defensive).
 *   - Storage read / write / remove throws → in-memory state still
 *     correct; persistence is the only thing lost.
 *   - `localStorage` undefined → composable still works in-memory.
 *   - Idempotent initialisation: a second `useThemePreference()` call
 *     returns the same shared state without re-reading storage.
 *
 * No matchMedia: the composable no longer consults
 * `prefers-color-scheme`. The forbidden-pattern ratchet for it lives in
 * `tests/unit/architecture/use-theme-is-sot.spec.ts`.
 *
 * Mirror discipline (chunk 7.2 D2 standing standard):
 *   This spec mirrors `apps/main/tests/unit/composables/
 *   useThemePreference.spec.ts`. Both files MUST stay in structural
 *   lockstep. Differences are limited to (a) the SPA's `STORAGE_KEY`
 *   (main = `catalyst.main.theme`, admin = `catalyst.admin.theme`) and
 *   (b) the import alias resolving to the SPA-local composable. Both
 *   SPAs default to `dark` as of Sprint 3.5 Chunk 1.
 */

import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import { createVuetify } from 'vuetify'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { lightTheme, darkTheme } from '@catalyst/design-tokens/vuetify'

// Two `defineComponent` stubs follow: one inside `mountHarness` (per
// invocation) and one inside the "idempotent initialisation" test
// (TwoConsumers). Both are scaffolding for the composable test, not
// reusable Vue components — `vue/one-component-per-file` does not
// apply to a test file whose purpose is exactly to drive the composable
// from inside multiple synthetic component instances.
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

describe('useThemePreference — admin SPA', () => {
  let storage: ReturnType<typeof createStorageStub>
  let originalLocalStorage: PropertyDescriptor | undefined

  beforeEach(() => {
    storage = createStorageStub()
    originalLocalStorage = Object.getOwnPropertyDescriptor(window, 'localStorage')
    Object.defineProperty(window, 'localStorage', {
      configurable: true,
      get: () => storage,
    })
  })

  afterEach(() => {
    __resetThemePreferenceForTests()
    if (originalLocalStorage !== undefined) {
      Object.defineProperty(window, 'localStorage', originalLocalStorage)
    }
  })

  describe('module-level exports', () => {
    it('exports the storage key for the admin SPA', () => {
      expect(STORAGE_KEY).toBe('catalyst.admin.theme')
    })

    it('exports the SPA default theme (admin = dark)', () => {
      expect(SPA_DEFAULT).toBe('dark')
    })

    it('exports the available preferences as a binary readonly tuple', () => {
      expect(themePreferences).toEqual(['light', 'dark'])
    })

    it('exposes availablePreferences on the composable return value', () => {
      const h = mountHarness('dark')
      try {
        expect(h.manager.availablePreferences).toBe(themePreferences)
      } finally {
        h.unmount()
      }
    })
  })

  describe('empty storage (unset preference)', () => {
    it('resolves preference to the SPA default and reports isExplicit=false', () => {
      const h = mountHarness('dark')
      try {
        expect(h.manager.preference.value).toBe('dark')
        expect(h.manager.isExplicit.value).toBe(false)
      } finally {
        h.unmount()
      }
    })

    it('resolves effectiveTheme to the SPA default', () => {
      const h = mountHarness('dark')
      try {
        expect(h.manager.effectiveTheme.value).toBe('dark')
      } finally {
        h.unmount()
      }
    })

    it('does NOT touch Vuetify on bootstrap when current === SPA default', () => {
      const h = mountHarness('dark')
      try {
        expect(h.vuetify.theme.global.name.value).toBe('dark')
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
  })

  describe('legacy "system" value (passive-on-read migration)', () => {
    it('treats a stored "system" value as unset, falling back to the SPA default', () => {
      storage.store[STORAGE_KEY] = 'system'
      const h = mountHarness('dark')
      try {
        expect(h.manager.preference.value).toBe(SPA_DEFAULT)
        expect(h.manager.isExplicit.value).toBe(false)
      } finally {
        h.unmount()
      }
    })

    it('does NOT rewrite storage on read — migration is passive', () => {
      storage.store[STORAGE_KEY] = 'system'
      const h = mountHarness('dark')
      try {
        expect(storage.getItem).toHaveBeenCalledWith(STORAGE_KEY)
        // Passive: the stale value is read but neither overwritten nor
        // removed until the user explicitly toggles.
        expect(storage.setItem).not.toHaveBeenCalled()
        expect(storage.removeItem).not.toHaveBeenCalled()
        expect(storage.store[STORAGE_KEY]).toBe('system')
      } finally {
        h.unmount()
      }
    })

    it('overwrites the stale "system" value when the user next toggles', () => {
      storage.store[STORAGE_KEY] = 'system'
      const h = mountHarness('dark')
      try {
        h.manager.setPreference('light')
        expect(storage.setItem).toHaveBeenCalledWith(STORAGE_KEY, 'light')
        expect(storage.store[STORAGE_KEY]).toBe('light')
      } finally {
        h.unmount()
      }
    })
  })

  describe('setPreference', () => {
    it("setPreference('light') persists and flips Vuetify", () => {
      const h = mountHarness('dark')
      try {
        h.manager.setPreference('light')
        expect(storage.setItem).toHaveBeenCalledWith(STORAGE_KEY, 'light')
        expect(h.manager.preference.value).toBe('light')
        expect(h.manager.effectiveTheme.value).toBe('light')
        expect(h.vuetify.theme.global.name.value).toBe('light')
      } finally {
        h.unmount()
      }
    })

    it("setPreference('dark') persists and flips Vuetify", () => {
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

    it('setPreference is idempotent — repeating the same value re-writes storage but is otherwise a no-op', () => {
      const h = mountHarness('dark')
      try {
        h.manager.setPreference('light')
        h.manager.setPreference('light')
        expect(storage.setItem).toHaveBeenCalledTimes(2)
        expect(h.vuetify.theme.global.name.value).toBe('light')
      } finally {
        h.unmount()
      }
    })
  })

  describe('clearPreference', () => {
    it('removes storage and reverts to the SPA default', () => {
      storage.store[STORAGE_KEY] = 'light'
      const h = mountHarness('dark')
      try {
        // Bootstrapped to light (explicit storage).
        expect(h.vuetify.theme.global.name.value).toBe('light')
        h.manager.clearPreference()
        expect(storage.removeItem).toHaveBeenCalledWith(STORAGE_KEY)
        expect(h.manager.preference.value).toBe('dark') // SPA default
        expect(h.manager.isExplicit.value).toBe(false)
        expect(h.vuetify.theme.global.name.value).toBe('dark')
      } finally {
        h.unmount()
      }
    })
  })

  describe('defensive: invalid / unavailable storage', () => {
    it('treats an invalid storage value as unset', () => {
      storage.store[STORAGE_KEY] = 'fuchsia'
      const h = mountHarness('dark')
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
      const h = mountHarness('dark')
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
      const h = mountHarness('dark')
      try {
        h.manager.setPreference('light')
        expect(h.manager.preference.value).toBe('light')
        expect(h.vuetify.theme.global.name.value).toBe('light')
      } finally {
        h.unmount()
      }
    })

    it('handles a storage remove throw — in-memory preference still clears', () => {
      storage.removeItem.mockImplementation(() => {
        throw new Error('storage disabled mid-session')
      })
      storage.store[STORAGE_KEY] = 'light'
      const h = mountHarness('dark')
      try {
        expect(h.manager.preference.value).toBe('light')
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
      const h = mountHarness('dark')
      try {
        expect(h.manager.preference.value).toBe(SPA_DEFAULT)
        h.manager.setPreference('light')
        expect(h.manager.preference.value).toBe('light')
        h.manager.clearPreference()
        expect(h.manager.preference.value).toBe(SPA_DEFAULT)
      } finally {
        h.unmount()
      }
    })
  })

  describe('idempotent initialisation + module-singleton behaviour', () => {
    it('reads storage exactly once across multiple useThemePreference() calls', () => {
      storage.store[STORAGE_KEY] = 'light'
      const vuetify = createVuetify({
        theme: { defaultTheme: 'dark', themes: { light: lightTheme, dark: darkTheme } },
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
        expect(firstManager.preference.value).toBe('light')
        expect(secondManager.preference.value).toBe('light')
        // Both consumers see the SAME reactive state — flipping
        // through one is observed by the other.
        secondManager.setPreference('dark')
        expect(firstManager.preference.value).toBe('dark')
      } finally {
        wrapper.unmount()
      }
    })
  })
})
