/**
 * Unit tests for the admin SPA's `useTheme` composable.
 *
 * Surface verified:
 *   - `availableThemes` is the readonly tuple `['light', 'dark']`.
 *   - `currentTheme.value` reflects whatever Vuetify's `defaultTheme`
 *     was set to at composable mount time. Admin SPA defaults to `dark`.
 *   - `setTheme('light')` flips the active theme to light.
 *   - `setTheme('dark')` flips the active theme back to dark.
 *   - TypeScript blocks invalid theme names at compile time. The
 *     `// @ts-expect-error` directive below MUST trigger a typecheck
 *     error; if a future refactor relaxes the signature, the directive
 *     becomes unused and `vue-tsc --noEmit` (the SPA's `typecheck`
 *     script) fails this file. The runtime call sites are gated behind
 *     `if (false)` so the test does not actually corrupt Vuetify state
 *     — this is a typecheck assertion, not a runtime assertion.
 *
 * Mirror discipline (chunk 7.2 D2 standing standard):
 *   This spec mirrors `apps/main/tests/unit/composables/useTheme.spec.ts`.
 *   Both files MUST stay in structural lockstep. Differences are limited
 *   to (a) the SPA's `defaultTheme` (admin = dark, main = light) and (b)
 *   the import alias `@/composables/useTheme` resolving to the SPA-local
 *   composable.
 */

import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import { createVuetify } from 'vuetify'
import { describe, expect, it } from 'vitest'

import { lightTheme, darkTheme } from '@catalyst/design-tokens/vuetify'

import { useTheme, availableThemes, type ThemeManager } from '@/composables/useTheme'

function harnessWithDefault(defaultTheme: 'light' | 'dark'): ThemeManager {
  const vuetify = createVuetify({
    theme: {
      defaultTheme,
      themes: {
        light: lightTheme,
        dark: darkTheme,
      },
    },
  })

  let captured!: ThemeManager
  const TestComponent = defineComponent({
    setup() {
      captured = useTheme()
      return () => h('div')
    },
  })

  mount(TestComponent, {
    global: {
      plugins: [vuetify],
    },
  })

  return captured
}

describe('useTheme — admin SPA', () => {
  it('exports the available theme names as a readonly tuple', () => {
    expect(availableThemes).toEqual(['light', 'dark'])
  })

  it('exposes availableThemes on the composable return value', () => {
    const theme = harnessWithDefault('dark')
    expect(theme.availableThemes).toBe(availableThemes)
  })

  it('reports the initial theme matching the SPA defaultTheme (dark)', () => {
    const theme = harnessWithDefault('dark')
    expect(theme.currentTheme.value).toBe('dark')
  })

  it('switches to light via setTheme("light")', () => {
    const theme = harnessWithDefault('dark')
    theme.setTheme('light')
    expect(theme.currentTheme.value).toBe('light')
  })

  it('switches back to dark via setTheme("dark")', () => {
    const theme = harnessWithDefault('dark')
    theme.setTheme('light')
    theme.setTheme('dark')
    expect(theme.currentTheme.value).toBe('dark')
  })

  it('reflects an alternate SPA defaultTheme passthrough (light) without forcing dark', () => {
    // Defensive: the composable is a thin reflector of Vuetify's state.
    // It MUST NOT impose its own default on mount — the per-SPA
    // defaultTheme decision in plugins/vuetify.ts is authoritative.
    // (Admin SPA happens to default to dark; this test pins the
    // contract so a refactor that adds a forced reset is loud.)
    const theme = harnessWithDefault('light')
    expect(theme.currentTheme.value).toBe('light')
  })

  it('blocks invalid theme names at compile time (typecheck assertion)', () => {
    const theme = harnessWithDefault('dark')
    // `false as boolean` widens the literal so TS does NOT prune the
    // branch as unreachable; the `@ts-expect-error` directive then
    // gets to inspect the call.
    const guarded = false as boolean
    /* c8 ignore start -- typecheck-only block; never reached at runtime. */
    if (guarded) {
      // @ts-expect-error — 'pink' is not assignable to ThemeName.
      theme.setTheme('pink')
    }
    /* c8 ignore stop */
    expect(theme.availableThemes).toEqual(['light', 'dark'])
  })
})
