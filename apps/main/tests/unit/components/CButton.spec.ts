/**
 * Unit tests for the shared `CButton` primitive (`@catalyst/ui`).
 *
 * Cross-package test location (Sprint 3.5 Chunk 2, Decision R2):
 *   `packages/ui` has no Vitest harness today (its `package.json` test
 *   script is a placeholder). Per the "tests live in the consuming SPA"
 *   convention adopted this chunk, the shared-component specs are
 *   co-located here in `apps/main/tests/unit/`. The standing
 *   shared-package-test-harness gap is tracked in `docs/tech-debt.md`
 *   ("packages/ui has no test harness").
 *
 * What this pins (Decision D-fork-a — defaults block is the styling SOT):
 *   - CButton encodes VARIANT SEMANTICS only: each `variant` prop maps to
 *     the right Vuetify `variant` + `color` (primary→flat/primary,
 *     secondary→tonal/secondary, ghost→text/undefined, danger→flat/error).
 *   - CButton no longer hard-codes a `border-radius` inline style. The
 *     radius + text-transform now live once in the Vuetify `defaults.VBtn`
 *     block; CButton's `<v-btn>` inherits them. This spec mounts a Vuetify
 *     instance WITHOUT that defaults block, so a rendered border-radius
 *     here would mean the wrapper is re-applying primitive styling (the
 *     drift D-fork-a closed).
 */

import { mount } from '@vue/test-utils'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import { VBtn } from 'vuetify/components'
import { describe, expect, it } from 'vitest'

import { CButton } from '@catalyst/ui'

import { lightTheme, darkTheme } from '@catalyst/design-tokens/vuetify'

type CButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger'

function mountButton(props: Record<string, unknown> = {}, slot = 'Click me') {
  // Deliberately NO `defaults` block: this isolates CButton's own output
  // from the SPA-level VBtn defaults so we can assert the wrapper adds no
  // primitive styling of its own.
  const vuetify = createVuetify({
    components: vuetifyComponents,
    theme: {
      defaultTheme: 'dark',
      themes: { light: lightTheme, dark: darkTheme },
    },
  })

  const wrapper = mount(CButton, {
    props,
    slots: { default: slot },
    global: { plugins: [vuetify] },
    attachTo: document.createElement('div'),
  })

  return { wrapper, unmount: () => wrapper.unmount() }
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
