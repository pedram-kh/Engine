/**
 * AH-115 (L1.1) — inside the Capacitor app the creator theme hook does nothing: no
 * registration, no theme name. The app already renders `nativeDarkTheme` as its global `dark`
 * theme, and its render is meant to stay exactly as it was.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

vi.mock('@/core/platform/isNativePlatform', () => ({ isNativePlatform: () => true }))

import { __resetThemePreferenceForTests, STORAGE_KEY } from '@/composables/useThemePreference'
import { useCreatorTheme } from '@/modules/creators/theme/useCreatorTheme'
import { buildVuetifyOptions } from '@/plugins/vuetify'

describe('useCreatorTheme inside the native app', () => {
  it('registers nothing, returns no theme, and ignores a stored light preference', () => {
    window.localStorage.setItem(STORAGE_KEY, 'light')
    __resetThemePreferenceForTests()
    const vuetify = createVuetify({
      ...buildVuetifyOptions(true),
      components: vuetifyComponents,
      directives: vuetifyDirectives,
    })
    const before = vuetify.theme.styles.value
    let theme: { value: string | undefined } | undefined
    const Layout = defineComponent({
      setup() {
        theme = useCreatorTheme().theme
        return () => h('div')
      },
    })
    const wrapper = mount(Layout, { global: { plugins: [vuetify] } })
    expect(theme?.value).toBeUndefined()
    expect(vuetify.theme.themes.value['creator-dark']).toBeUndefined()
    expect(vuetify.theme.styles.value).toBe(before)
    expect(vuetify.theme.global.name.value).toBe('dark')
    wrapper.unmount()
    window.localStorage.clear()
    __resetThemePreferenceForTests()
  })
})
