/**
 * AH-100 D7 pin (a) — the web Vuetify config is exactly the pre-AH-100
 * literal (as at d1ab5820). The native config differs only in the `dark`
 * theme's values and the button radius.
 */

import { describe, expect, it } from 'vitest'

import { darkTheme, lightTheme } from '@catalyst/design-tokens/vuetify'
import { nativeDarkTheme } from '@catalyst/design-tokens/native'

import { buildVuetifyOptions } from '@/plugins/vuetify'

const WEB_CONFIG_AT_D1AB5820 = {
  theme: {
    defaultTheme: 'dark',
    themes: {
      light: lightTheme,
      dark: darkTheme,
    },
  },
  defaults: {
    VBtn: {
      variant: 'flat',
      rounded: 0,
      style: 'border-radius: var(--radius-md); text-transform: none;',
    },
    VTextField: {
      variant: 'outlined',
      density: 'compact',
    },
    VSelect: {
      variant: 'outlined',
      density: 'compact',
    },
    VAutocomplete: {
      variant: 'outlined',
      density: 'compact',
    },
  },
}

describe('buildVuetifyOptions', () => {
  it('web: deep-equals the pre-AH-100 literal config', () => {
    expect(buildVuetifyOptions(false)).toStrictEqual(WEB_CONFIG_AT_D1AB5820)
  })

  it('native: swaps only the dark theme values and the button radius', () => {
    const native = buildVuetifyOptions(true)

    expect(native.theme).toStrictEqual({
      defaultTheme: 'dark',
      themes: { light: lightTheme, dark: nativeDarkTheme },
    })
    expect(native.defaults).toStrictEqual({
      ...WEB_CONFIG_AT_D1AB5820.defaults,
      VBtn: {
        variant: 'flat',
        rounded: 0,
        style: 'border-radius: var(--native-radius-lg); text-transform: none;',
      },
    })
  })
})
