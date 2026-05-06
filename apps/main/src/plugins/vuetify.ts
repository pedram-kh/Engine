import 'vuetify/styles'
import { createVuetify, type ThemeDefinition } from 'vuetify'
import { catalystLightTheme, catalystDarkTheme } from '@catalyst/design-tokens/vuetify'

const lightTheme: ThemeDefinition = catalystLightTheme
const darkTheme: ThemeDefinition = catalystDarkTheme

export const vuetify = createVuetify({
  theme: {
    defaultTheme: 'catalystLight',
    themes: {
      catalystLight: lightTheme,
      catalystDark: darkTheme,
    },
  },
  defaults: {
    VBtn: {
      variant: 'flat',
      rounded: 0,
      style: 'border-radius: 6px; text-transform: none;',
    },
    VTextField: {
      variant: 'outlined',
      density: 'compact',
    },
    VSelect: {
      variant: 'outlined',
      density: 'compact',
    },
  },
})
