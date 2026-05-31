/**
 * Theme-aware mount helper for `@catalyst/ui` specs
 * (Sprint 4 Chunk 1 sub-step 1a).
 *
 * Generalizes the Vuetify wiring `CButton.spec.ts` previously did inline so
 * every shared-component spec mounts under the REAL Catalyst themes (zinc
 * neutrals, registered container/variant tokens, aurora-free `theme.colors`)
 * rather than Vuetify's stock `light` default. This is the package half of
 * closing the "component-test harness renders under stock theme" tech-debt
 * entry.
 *
 * Mode is parameterized (`'dark'` default — the SPA's dark-default contract)
 * so a spec can mount the same component in either theme and assert the
 * rendered surface re-themes. The themes are imported from
 * `@catalyst/design-tokens/vuetify` (a workspace dependency), the single
 * source of truth both SPAs' Vuetify plugins consume.
 *
 * Import-form note (kickoff trigger): the component-under-test is imported
 * RELATIVELY by each spec (`../../src/components/<C>.vue`). A package
 * self-reference (`@catalyst/ui`) resolves via the `exports` field in
 * principle, but the relative form is unambiguous under the Vite/Vitest
 * resolver and avoids a barrel round-trip — so specs use relative imports.
 * The cross-package `@catalyst/design-tokens/vuetify` import here is a
 * genuine workspace dependency and resolves normally.
 *
 * Deliberately NO Vuetify `defaults` block (mirrors apps/main's
 * `mountAuthPage` + the previous inline CButton wiring): the SPA-level
 * `defaults.VBtn` styling is not part of the component contract under test,
 * so the `CButton` "no inline border-radius" assertion (D-fork-a) stays
 * meaningful.
 */

import { mount, type VueWrapper } from '@vue/test-utils'
import type { Component } from 'vue'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import { lightTheme, darkTheme } from '@catalyst/design-tokens/vuetify'

export type ThemeMode = 'light' | 'dark'

export interface MountThemedOptions {
  /** Theme to mount under. Defaults to `'dark'` (the SPA dark-default). */
  mode?: ThemeMode
  props?: Record<string, unknown>
  slots?: Record<string, unknown>
}

export interface MountThemedResult<T> {
  wrapper: VueWrapper<T>
  vuetify: ReturnType<typeof createVuetify>
  /** The mode the component was mounted under (for assertion convenience). */
  mode: ThemeMode
  unmount: () => void
}

export function mountThemed<T = unknown>(
  component: Component,
  options: MountThemedOptions = {},
): MountThemedResult<T> {
  const mode: ThemeMode = options.mode ?? 'dark'

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
    theme: {
      defaultTheme: mode,
      themes: {
        // Vuetify-standard theme keys (Sprint 3.5 Chunk 1 — R1); the
        // Catalyst brand identity lives in the VALUES, not the key names.
        light: lightTheme,
        dark: darkTheme,
      },
    },
  })

  const wrapper = mount(component, {
    props: options.props ?? {},
    slots: options.slots ?? {},
    global: { plugins: [vuetify] },
    attachTo: document.createElement('div'),
  }) as unknown as VueWrapper<T>

  return {
    wrapper,
    vuetify,
    mode,
    unmount: () => {
      wrapper.unmount()
    },
  }
}
