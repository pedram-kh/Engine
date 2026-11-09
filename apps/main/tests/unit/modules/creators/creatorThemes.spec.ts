/**
 * AH-115 (L0.4) — the creator theme module.
 *
 * Pins: both themes register and unregister on a Vuetify instance, registration is
 * idempotent, the Vuetify theme stylesheet is byte-identical before registering and after
 * unregistering (the agency-side guarantee), `creator-dark` IS `nativeDarkTheme`'s colours,
 * both themes carry `variables` (without it `app.use(vuetify)` throws), and the layout hook
 * reference-counts mounted creator-side layouts.
 */

import { mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { nativeDarkTheme } from '@catalyst/design-tokens/native'

import { __resetThemePreferenceForTests } from '@/composables/useThemePreference'
import {
  buildCreatorThemes,
  CREATOR_DARK_THEME,
  CREATOR_LIGHT_THEME,
  creatorThemeFor,
  registerCreatorThemes,
  unregisterCreatorThemes,
} from '@/modules/creators/theme/creatorThemes'
import { useCreatorTheme } from '@/modules/creators/theme/useCreatorTheme'
import { buildVuetifyOptions } from '@/plugins/vuetify'

function makeVuetify() {
  return createVuetify({
    ...buildVuetifyOptions(false),
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })
}

describe('creatorThemes', () => {
  it('maps the base theme to the creator theme', () => {
    expect(creatorThemeFor('dark')).toBe('creator-dark')
    expect(creatorThemeFor('light')).toBe('creator-light')
  })

  it('creator-dark is the native dark palette, key for key', () => {
    const built = buildCreatorThemes({ light: {}, dark: {} })
    expect(built[CREATOR_DARK_THEME].dark).toBe(true)
    expect(built[CREATOR_DARK_THEME].colors).toEqual(nativeDarkTheme.colors)
    expect(built[CREATOR_LIGHT_THEME].dark).toBe(false)
  })

  it('both themes carry `variables` (a runtime theme without it breaks app.use)', () => {
    const built = buildCreatorThemes({ light: {}, dark: {} })
    expect(built[CREATOR_DARK_THEME].variables).toBeDefined()
    expect(built[CREATOR_LIGHT_THEME].variables).toBeDefined()
  })

  it('takes the emphasis opacities from the live base themes', () => {
    const vuetify = makeVuetify()
    const themes = vuetify.theme.themes.value
    registerCreatorThemes(vuetify)
    expect(themes[CREATOR_DARK_THEME]?.variables).toEqual(themes.dark?.variables)
    expect(themes[CREATOR_LIGHT_THEME]?.variables).toEqual(themes.light?.variables)
  })

  it('registers and unregisters both themes, and registering twice is a no-op', () => {
    const vuetify = makeVuetify()
    const themes = vuetify.theme.themes.value
    expect(themes[CREATOR_DARK_THEME]).toBeUndefined()

    registerCreatorThemes(vuetify)
    const first = themes[CREATOR_DARK_THEME]
    registerCreatorThemes(vuetify)
    expect(themes[CREATOR_DARK_THEME]).toBe(first)
    expect(themes[CREATOR_LIGHT_THEME]).toBeDefined()

    unregisterCreatorThemes(vuetify)
    expect(themes[CREATOR_DARK_THEME]).toBeUndefined()
    expect(themes[CREATOR_LIGHT_THEME]).toBeUndefined()
    unregisterCreatorThemes(vuetify)
  })

  it('leaves the theme stylesheet byte-identical after register then unregister', () => {
    const vuetify = makeVuetify()
    const before = vuetify.theme.styles.value
    registerCreatorThemes(vuetify)
    const during = vuetify.theme.styles.value
    expect(during).not.toBe(before)
    expect(during).toContain('creator-dark')
    expect(during).toContain('creator-light')
    unregisterCreatorThemes(vuetify)
    expect(vuetify.theme.styles.value).toBe(before)
    expect(before).not.toMatch(/creator-(dark|light)/)
  })
})

describe('useCreatorTheme', () => {
  let wrappers: VueWrapper[] = []

  afterEach(() => {
    wrappers.forEach((w) => w.unmount())
    wrappers = []
    window.localStorage.clear()
    __resetThemePreferenceForTests()
  })

  function mountLayout(vuetify: ReturnType<typeof makeVuetify>) {
    let themeName: { value: string } | undefined
    const Layout = defineComponent({
      setup() {
        themeName = useCreatorTheme()
        return () => h('div', { class: 'layout' })
      },
    })
    const wrapper = mount(Layout, { global: { plugins: [vuetify] } })
    wrappers.push(wrapper)
    return { wrapper, themeName: () => themeName?.value }
  }

  it('registers on setup and returns the dark creator theme by default', () => {
    const vuetify = makeVuetify()
    expect(vuetify.theme.themes.value[CREATOR_DARK_THEME]).toBeUndefined()
    const { themeName } = mountLayout(vuetify)
    expect(vuetify.theme.themes.value[CREATOR_DARK_THEME]).toBeDefined()
    expect(themeName()).toBe('creator-dark')
  })

  it('keeps the themes while any creator-side layout is mounted, removes them with the last', async () => {
    const vuetify = makeVuetify()
    const before = vuetify.theme.styles.value
    const a = mountLayout(vuetify)
    const b = mountLayout(vuetify)

    a.wrapper.unmount()
    wrappers = wrappers.filter((w) => w !== a.wrapper)
    await nextTick()
    expect(vuetify.theme.themes.value[CREATOR_DARK_THEME]).toBeDefined()

    b.wrapper.unmount()
    wrappers = []
    await nextTick()
    expect(vuetify.theme.themes.value[CREATOR_DARK_THEME]).toBeUndefined()
    expect(vuetify.theme.styles.value).toBe(before)
  })
})
