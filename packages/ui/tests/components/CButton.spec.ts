/**
 * Unit tests for the shared `CButton` primitive (`@catalyst/ui`).
 *
 * Runs under the package's own Vitest harness (Sprint 4 Chunk 1 sub-step
 * 1a) via the theme-aware `mountThemed` helper — components mount under the
 * real Catalyst `light` / `dark` themes, dark-default.
 *
 * What this pins (Decision D-fork-a — defaults block is the styling SOT):
 *   - CButton encodes VARIANT SEMANTICS only: each `variant` prop maps to
 *     the right Vuetify `variant` + `color` (primary→flat/primary,
 *     secondary→tonal/secondary, ghost→text/undefined, danger→flat/error).
 *   - CButton no longer hard-codes a `border-radius` inline style. The
 *     radius + text-transform now live once in the Vuetify `defaults.VBtn`
 *     block; CButton's `<v-btn>` inherits them. `mountThemed` deliberately
 *     installs NO defaults block, so a rendered border-radius here would
 *     mean the wrapper is re-applying primitive styling (the drift
 *     D-fork-a closed).
 *   - Theme-awareness: mounted under the dark theme, the rendered control
 *     carries Vuetify's `v-theme--dark` class; under light, `v-theme--light`
 *     (the first systematic dark-vs-light rendering assertion in the
 *     shared package — closes the stock-theme harness gap).
 */

import { VBtn } from 'vuetify/components'
import { describe, expect, it } from 'vitest'

import CButton from '../../src/components/CButton.vue'

import { mountThemed, type ThemeMode } from '../helpers/mountThemed'

type CButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger'

function mountButton(props: Record<string, unknown> = {}, slot = 'Click me', mode?: ThemeMode) {
  return mountThemed(CButton, { props, slots: { default: slot }, mode })
}

const VARIANT_MAP: ReadonlyArray<{
  variant: CButtonVariant
  vuetifyVariant: string
  color: string | undefined
}> = [
  { variant: 'primary', vuetifyVariant: 'flat', color: 'primary' },
  { variant: 'secondary', vuetifyVariant: 'tonal', color: 'secondary' },
  { variant: 'ghost', vuetifyVariant: 'text', color: undefined },
  { variant: 'danger', vuetifyVariant: 'flat', color: 'error' },
]

describe('CButton — variant semantics (D-fork-a)', () => {
  it.each(VARIANT_MAP)(
    'maps variant "$variant" to Vuetify variant "$vuetifyVariant" + color "$color"',
    ({ variant, vuetifyVariant, color }) => {
      const h = mountButton({ variant })
      try {
        const btn = h.wrapper.findComponent(VBtn)
        expect(btn.props('variant')).toBe(vuetifyVariant)
        expect(btn.props('color')).toBe(color)
      } finally {
        h.unmount()
      }
    },
  )

  it('defaults to the primary variant', () => {
    const h = mountButton()
    try {
      const btn = h.wrapper.findComponent(VBtn)
      expect(btn.props('variant')).toBe('flat')
      expect(btn.props('color')).toBe('primary')
    } finally {
      h.unmount()
    }
  })
})

describe('CButton — styling source-of-truth (defaults block, not the wrapper)', () => {
  it('renders no inline border-radius (radius lives in defaults.VBtn now)', () => {
    const h = mountButton({ variant: 'primary' })
    try {
      const style = h.wrapper.find('button').attributes('style') ?? ''
      expect(style).not.toContain('border-radius')
      expect(style).not.toContain('text-transform')
    } finally {
      h.unmount()
    }
  })
})

describe('CButton — passthrough behaviour', () => {
  it('renders default-slot content', () => {
    const h = mountButton({ variant: 'primary' }, 'Save changes')
    try {
      expect(h.wrapper.text()).toContain('Save changes')
    } finally {
      h.unmount()
    }
  })

  it('forwards loading + disabled to the underlying v-btn', () => {
    const h = mountButton({ variant: 'primary', loading: true, disabled: true })
    try {
      const btn = h.wrapper.findComponent(VBtn)
      expect(btn.props('loading')).toBe(true)
      expect(btn.props('disabled')).toBe(true)
    } finally {
      h.unmount()
    }
  })

  it('emits click when the button is pressed', async () => {
    const h = mountButton({ variant: 'primary' })
    try {
      await h.wrapper.find('button').trigger('click')
      expect(h.wrapper.emitted('click')).toBeTruthy()
      expect(h.wrapper.emitted('click')?.length).toBe(1)
    } finally {
      h.unmount()
    }
  })
})

describe('CButton — theme-aware rendering (1a harness)', () => {
  it('renders under the dark Catalyst theme by default (v-theme--dark)', () => {
    const h = mountButton({ variant: 'primary' }, 'Themed', 'dark')
    try {
      const btn = h.wrapper.findComponent(VBtn)
      expect(btn.classes()).toContain('v-theme--dark')
      expect(btn.classes()).not.toContain('v-theme--light')
    } finally {
      h.unmount()
    }
  })

  it('renders under the light Catalyst theme when mounted in light mode', () => {
    const h = mountButton({ variant: 'primary' }, 'Themed', 'light')
    try {
      const btn = h.wrapper.findComponent(VBtn)
      expect(btn.classes()).toContain('v-theme--light')
      expect(btn.classes()).not.toContain('v-theme--dark')
    } finally {
      h.unmount()
    }
  })
})
