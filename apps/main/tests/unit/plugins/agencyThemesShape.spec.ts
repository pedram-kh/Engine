/**
 * AH-114 shape spec — runtime-registered agency themes against a REAL Vuetify.
 *
 * The S0 spike found that a runtime-registered theme without a `variables`
 * object makes `app.use(vuetify)` throw (`Cannot convert undefined or null to
 * object`) and the app never mounts. `registerAgencyThemes` builds the full
 * shape through one helper; this spec proves it against a fresh
 * `createVuetify(buildVuetifyOptions(false))` in both orders (before and after
 * `app.use`), and that the generated stylesheet carries both theme classes.
 */

import { createApp, nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import { createVuetify } from 'vuetify'

import { buildVuetifyOptions } from '@/plugins/vuetify'
import {
  AGENCY_DARK_THEME,
  AGENCY_LIGHT_THEME,
  agencyThemeFor,
  buildAgencyThemes,
  registerAgencyThemes,
} from '@/modules/agency/theme/agencyThemes'

const STYLE_ID = 'vuetify-theme-stylesheet'

function themesOf(instance: ReturnType<typeof freshVuetify>) {
  const { light, dark } = instance.theme.themes.value
  return { light, dark }
}

function freshVuetify() {
  return createVuetify(buildVuetifyOptions(false))
}

function themeSheet(): string {
  return document.getElementById(STYLE_ID)?.innerHTML ?? ''
}

function installOn(instance: ReturnType<typeof freshVuetify>) {
  const app = createApp({ render: () => null })
  app.use(instance)
  return app
}

describe('agency themes: shape', () => {
  it('maps the base theme to the agency theme', () => {
    expect(agencyThemeFor('dark')).toBe(AGENCY_DARK_THEME)
    expect(agencyThemeFor('light')).toBe(AGENCY_LIGHT_THEME)
  })

  it('every definition has `dark`, `colors` and a `variables` object', () => {
    const vuetify = freshVuetify()
    const built = buildAgencyThemes(themesOf(vuetify))
    for (const name of [AGENCY_DARK_THEME, AGENCY_LIGHT_THEME] as const) {
      expect(typeof built[name].dark).toBe('boolean')
      expect(built[name].colors).toBeTypeOf('object')
      expect(built[name].variables).toBeTypeOf('object')
    }
    expect(built[AGENCY_DARK_THEME].dark).toBe(true)
    expect(built[AGENCY_LIGHT_THEME].dark).toBe(false)
  })

  it('carries every Vuetify emphasis/state variable of its base theme, plus the overlay shadow', () => {
    const vuetify = freshVuetify()
    const { light, dark } = themesOf(vuetify)
    const built = buildAgencyThemes({ light, dark })
    expect(Object.keys(dark?.variables ?? {}).length).toBeGreaterThan(0)
    for (const key of Object.keys(dark?.variables ?? {})) {
      expect(built[AGENCY_DARK_THEME].variables).toHaveProperty(key)
    }
    for (const key of Object.keys(light?.variables ?? {})) {
      expect(built[AGENCY_LIGHT_THEME].variables).toHaveProperty(key)
    }
    expect(built[AGENCY_DARK_THEME].variables).toHaveProperty('shadow-overlay')
    expect(built[AGENCY_LIGHT_THEME].variables).toHaveProperty('shadow-overlay')
  })

  it('registers both themes, writes nothing else, and is idempotent', () => {
    const vuetify = freshVuetify()
    const before = Object.keys(vuetify.theme.themes.value).sort()
    const globalBefore = vuetify.theme.global.name.value

    registerAgencyThemes(vuetify)
    const dark = vuetify.theme.themes.value[AGENCY_DARK_THEME]
    registerAgencyThemes(vuetify)

    expect(Object.keys(vuetify.theme.themes.value).sort()).toStrictEqual(
      [...before, AGENCY_DARK_THEME, AGENCY_LIGHT_THEME].sort(),
    )
    expect(vuetify.theme.themes.value[AGENCY_DARK_THEME]).toBe(dark)
    expect(vuetify.theme.global.name.value).toBe(globalBefore)
  })

  it('does not register anything until it is called (the creator stylesheet stays unchanged)', () => {
    const vuetify = freshVuetify()
    expect(vuetify.theme.themes.value[AGENCY_DARK_THEME]).toBeUndefined()
    expect(vuetify.theme.themes.value[AGENCY_LIGHT_THEME]).toBeUndefined()

    installOn(vuetify)
    expect(themeSheet()).not.toContain('agency')
  })

  it('registering BEFORE app.use does not throw and generates both theme classes', async () => {
    const vuetify = freshVuetify()
    registerAgencyThemes(vuetify)
    expect(() => installOn(vuetify)).not.toThrow()
    await nextTick()

    const sheet = themeSheet()
    expect(sheet).toContain('.v-theme--agency-dark')
    expect(sheet).toContain('.v-theme--agency-light')
    expect(sheet).toContain('--v-theme-sidebar')
    expect(sheet).toContain('--v-shadow-overlay')
  })

  it('registering AFTER app.use (the real order: AgencyLayout setup) generates both theme classes', async () => {
    const vuetify = freshVuetify()
    installOn(vuetify)
    expect(themeSheet()).not.toContain('agency')

    expect(() => registerAgencyThemes(vuetify)).not.toThrow()
    await nextTick()
    await nextTick()

    const sheet = themeSheet()
    expect(sheet).toContain('.v-theme--agency-dark')
    expect(sheet).toContain('.v-theme--agency-light')
    expect(sheet).toContain('--v-shadow-overlay')
  })

  it('a theme registered without `variables` makes app.use throw (the spike trap, kept as a canary)', () => {
    const vuetify = freshVuetify()
    const { dark } = vuetify.theme.themes.value
    vuetify.theme.themes.value['agency-bad'] = { dark: true, colors: { ...dark?.colors } } as never
    expect(() => installOn(vuetify)).toThrow()
  })
})
