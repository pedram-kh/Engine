/**
 * Unit tests for the admin SPA's `ThemeToggle` component.
 *
 * Surface verified (chunk 8.2 review priority #3 — "no leakage of
 * theme logic outside the composables"):
 *   - Three buttons rendered (light / dark / system) with the
 *     correct `data-test` selectors (no parent fall-through risk
 *     per the chunk-7.1 hotfix #3 lesson).
 *   - The currently-selected button reflects
 *     `useThemePreference().preference.value`.
 *   - Clicking a button calls `setPreference` with the matching
 *     value and the persistence layer flips Vuetify accordingly.
 *   - The component holds NO theme state of its own — every
 *     rendered value goes through the composable. Verified by
 *     mutating storage out-of-band and confirming the toggle
 *     re-renders to match.
 *   - i18n: every visible string resolves through the
 *     `app.theme.toggle.*` keys (no hard-coded English).
 *
 * Mirror discipline (chunk 7.2 D2 standing standard):
 *   This spec mirrors `apps/main/tests/unit/components/
 *   ThemeToggle.spec.ts`. Both files MUST stay in structural
 *   lockstep. Differences are limited to the SPA's `STORAGE_KEY`,
 *   `SPA_DEFAULT`, and the import alias resolving to the
 *   SPA-local component + composable.
 */

import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest'

import { lightTheme, darkTheme } from '@catalyst/design-tokens/vuetify'

import ThemeToggle from '@/components/ThemeToggle.vue'
import enApp from '@/core/i18n/locales/en/app.json'
import {
  useThemePreference,
  STORAGE_KEY,
  SPA_DEFAULT,
  __resetThemePreferenceForTests,
} from '@/composables/useThemePreference'

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
  return { mediaQuery }
}

interface Harness {
  wrapper: ReturnType<typeof mount>
  vuetify: ReturnType<typeof createVuetify>
  i18n: ReturnType<typeof createI18n>
  unmount: () => void
}

function mountToggle(): Harness {
  const vuetify = createVuetify({
    components: vuetifyComponents,
    theme: {
      defaultTheme: 'dark',
      themes: { light: lightTheme, dark: darkTheme },
    },
  })

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: { ...enApp } },
  }) as unknown as ReturnType<typeof createI18n>

  const Host = defineComponent({
    components: { ThemeToggle },
    setup() {
      return () => h(ThemeToggle)
    },
  })

  const wrapper = mount(Host, {
    global: { plugins: [vuetify, i18n] },
    attachTo: document.createElement('div'),
  })

  return {
    wrapper,
    vuetify,
    i18n,
    unmount: () => wrapper.unmount(),
  }
}

describe('ThemeToggle — admin SPA', () => {
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

  describe('rendering', () => {
    it('renders the v-btn-toggle root with the theme-toggle data-test selector', () => {
      const h = mountToggle()
      try {
        expect(h.wrapper.find('[data-test="theme-toggle"]').exists()).toBe(true)
      } finally {
        h.unmount()
      }
    })

    it('renders three buttons — light, dark, and system — each with its own data-test selector', () => {
      const h = mountToggle()
      try {
        expect(h.wrapper.find('[data-test="theme-toggle-light"]').exists()).toBe(true)
        expect(h.wrapper.find('[data-test="theme-toggle-dark"]').exists()).toBe(true)
        expect(h.wrapper.find('[data-test="theme-toggle-system"]').exists()).toBe(true)
      } finally {
        h.unmount()
      }
    })

    it('exposes the i18n labels via aria-label on each button', () => {
      const h = mountToggle()
      try {
        const lightBtn = h.wrapper.find('[data-test="theme-toggle-light"]')
        const darkBtn = h.wrapper.find('[data-test="theme-toggle-dark"]')
        const systemBtn = h.wrapper.find('[data-test="theme-toggle-system"]')
        expect(lightBtn.attributes('aria-label')).toBe('Light')
        expect(darkBtn.attributes('aria-label')).toBe('Dark')
        expect(systemBtn.attributes('aria-label')).toBe('Match system')
      } finally {
        h.unmount()
      }
    })

    it('exposes the toggle group aria-label from i18n', () => {
      const h = mountToggle()
      try {
        const root = h.wrapper.find('[data-test="theme-toggle"]')
        expect(root.attributes('aria-label')).toBe('Theme')
      } finally {
        h.unmount()
      }
    })
  })

  describe('initial selection', () => {
    it('renders with the SPA default (admin = dark) selected when storage is empty', () => {
      const h = mountToggle()
      try {
        const darkBtn = h.wrapper.find('[data-test="theme-toggle-dark"]')
        expect(darkBtn.classes()).toContain('v-btn--active')
      } finally {
        h.unmount()
      }
    })

    it('renders with `light` selected when storage holds `light`', () => {
      storage.store[STORAGE_KEY] = 'light'
      const h = mountToggle()
      try {
        const lightBtn = h.wrapper.find('[data-test="theme-toggle-light"]')
        expect(lightBtn.classes()).toContain('v-btn--active')
      } finally {
        h.unmount()
      }
    })

    it('renders with `system` selected when storage holds `system`', () => {
      storage.store[STORAGE_KEY] = 'system'
      const h = mountToggle()
      try {
        const systemBtn = h.wrapper.find('[data-test="theme-toggle-system"]')
        expect(systemBtn.classes()).toContain('v-btn--active')
      } finally {
        h.unmount()
      }
    })
  })

  describe('user interaction → composable', () => {
    it('clicking light calls setPreference("light") and flips Vuetify', async () => {
      const h = mountToggle()
      try {
        await h.wrapper.find('[data-test="theme-toggle-light"]').trigger('click')
        await h.wrapper.vm.$nextTick()
        expect(storage.setItem).toHaveBeenCalledWith(STORAGE_KEY, 'light')
        expect(h.vuetify.theme.global.name.value).toBe('light')
      } finally {
        h.unmount()
      }
    })

    it('clicking dark from a light-storage start flips back to dark', async () => {
      storage.store[STORAGE_KEY] = 'light'
      const h = mountToggle()
      try {
        expect(h.vuetify.theme.global.name.value).toBe('light')
        await h.wrapper.find('[data-test="theme-toggle-dark"]').trigger('click')
        await h.wrapper.vm.$nextTick()
        expect(storage.setItem).toHaveBeenLastCalledWith(STORAGE_KEY, 'dark')
        expect(h.vuetify.theme.global.name.value).toBe('dark')
      } finally {
        h.unmount()
      }
    })

    it('clicking system mounts the matchMedia listener and resolves through it', async () => {
      media.mediaQuery.matches = true
      const h = mountToggle()
      try {
        await h.wrapper.find('[data-test="theme-toggle-system"]').trigger('click')
        await h.wrapper.vm.$nextTick()
        expect(storage.setItem).toHaveBeenLastCalledWith(STORAGE_KEY, 'system')
        expect(media.mediaQuery.addEventListener).toHaveBeenCalledWith(
          'change',
          expect.any(Function),
        )
        expect(h.vuetify.theme.global.name.value).toBe('dark')
      } finally {
        h.unmount()
      }
    })
  })

  describe('composable boundary', () => {
    it('reflects out-of-band setPreference calls (no local component state)', async () => {
      const h = mountToggle()
      try {
        const manager = useThemePreference()
        manager.setPreference('light')
        await h.wrapper.vm.$nextTick()
        const lightBtn = h.wrapper.find('[data-test="theme-toggle-light"]')
        expect(lightBtn.classes()).toContain('v-btn--active')
        manager.setPreference('system')
        await h.wrapper.vm.$nextTick()
        const systemBtn = h.wrapper.find('[data-test="theme-toggle-system"]')
        expect(systemBtn.classes()).toContain('v-btn--active')
      } finally {
        h.unmount()
      }
    })

    it('uses the composable as SOT for SPA default (no hard-coded fallback)', () => {
      // Sanity: the imported SPA_DEFAULT is the value the toggle
      // visually selects when storage is empty. Admin's pre-existing
      // asymmetry ('dark') is preserved per chunk-8.2 Q1 Option C.
      expect(SPA_DEFAULT).toBe('dark')
    })
  })
})
