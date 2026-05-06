/**
 * Vuetify theme objects derived from the Catalyst design tokens.
 * Imported by both apps/main and apps/admin Vuetify plugins.
 *
 * The Vuetify ThemeDefinition shape is replicated as a structural type so
 * this package does not pull in vuetify as a runtime dependency.
 */

import { brand, neutral, semantic as palette } from './tokens'
import { semanticLight, semanticDark } from './semantic'

export type CatalystThemeDefinition = {
  dark: boolean
  colors: Record<string, string>
}

export const catalystLightTheme: CatalystThemeDefinition = {
  dark: false,
  colors: {
    background: semanticLight.bg.app,
    surface: semanticLight.bg.surface,
    'surface-bright': semanticLight.bg.surfaceRaised,
    'surface-light': semanticLight.bg.surface,
    'surface-variant': semanticLight.bg.surfaceSunken,
    'on-surface': semanticLight.text.primary,
    'on-surface-variant': semanticLight.text.secondary,
    primary: semanticLight.action.primary,
    'primary-darken-1': semanticLight.action.primaryHover,
    'on-primary': semanticLight.action.primaryFg,
    secondary: semanticLight.action.secondary,
    'on-secondary': semanticLight.action.secondaryFg,
    error: semanticLight.action.danger,
    'on-error': semanticLight.action.dangerFg,
    info: palette.info[500],
    success: palette.success[500],
    warning: palette.warning[500],
    'border-color': semanticLight.border.default,
    accent: brand.violet[500],
  },
}

export const catalystDarkTheme: CatalystThemeDefinition = {
  dark: true,
  colors: {
    background: semanticDark.bg.app,
    surface: semanticDark.bg.surface,
    'surface-bright': semanticDark.bg.surfaceRaised,
    'surface-light': semanticDark.bg.surface,
    'surface-variant': semanticDark.bg.surfaceSunken,
    'on-surface': semanticDark.text.primary,
    'on-surface-variant': semanticDark.text.secondary,
    primary: semanticDark.action.primary,
    'primary-darken-1': semanticDark.action.primaryActive,
    'on-primary': semanticDark.action.primaryFg,
    secondary: semanticDark.action.secondary,
    'on-secondary': semanticDark.action.secondaryFg,
    error: semanticDark.action.danger,
    'on-error': semanticDark.action.dangerFg,
    info: palette.info[500],
    success: palette.success[500],
    warning: palette.warning[500],
    'border-color': semanticDark.border.default,
    accent: brand.violet[400],
  },
}

void neutral
