/**
 * AH-115 (L1.1) — which auth routes take the creator theme, and what a layout holding the
 * hook does to the Vuetify theme stylesheet as the route changes.
 *
 * Ruling 1 of the plan review: creator-reachable auth routes (sign-in, sign-up, verify
 * pending) take the creator look; `/agency/sign-in` and the screens an agency user can also
 * reach (2FA, forgot and reset password, accept invitation) do not, and the theme sheet is
 * the one it was before this chunk while they are open.
 */

import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import {
  CREATOR_LOOK_AUTH_ROUTES,
  hasCreatorLook,
  useAuthCreatorTheme,
} from '@/modules/auth/layouts/creatorLook'
import { buildVuetifyOptions } from '@/plugins/vuetify'

const NAMES = [
  'auth.sign-in',
  'auth.agency-sign-in',
  'auth.sign-up',
  'auth.verify-email.pending',
  'auth.2fa.verify',
  'auth.2fa.enable',
  'auth.forgot-password',
  'auth.reset-password',
  'accept-invitation',
]

describe('creator look by auth route name', () => {
  it('is exactly sign-in, sign-up and verify-pending', () => {
    expect([...CREATOR_LOOK_AUTH_ROUTES].sort()).toEqual([
      'auth.sign-in',
      'auth.sign-up',
      'auth.verify-email.pending',
    ])
    expect(hasCreatorLook(undefined)).toBe(false)
    expect(hasCreatorLook('auth.agency-sign-in')).toBe(false)
  })
})

describe('AuthLayout theme hook across route changes', () => {
  let wrapper: VueWrapper | undefined
  afterEach(() => {
    wrapper?.unmount()
    wrapper = undefined
  })

  async function setup() {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: NAMES.map((name) => ({
        path: `/${name}`,
        name,
        component: { template: '<div />' },
      })),
    })
    await router.push('/auth.agency-sign-in')
    await router.isReady()
    const vuetify = createVuetify({
      ...buildVuetifyOptions(false),
      components: vuetifyComponents,
      directives: vuetifyDirectives,
    })
    let theme: { value: string | undefined } | undefined
    const Layout = defineComponent({
      setup() {
        theme = useAuthCreatorTheme().theme
        return () => h(RouterView)
      },
    })
    wrapper = mount(Layout, { global: { plugins: [router, vuetify] } })
    await flushPromises()
    return { router, vuetify, theme: () => theme?.value }
  }

  it.each(NAMES)('%s', async (name) => {
    const { router, vuetify, theme } = await setup()
    const untouched = vuetify.theme.styles.value
    await router.push({ name })
    await flushPromises()
    if (hasCreatorLook(name)) {
      expect(theme()).toBe('creator-dark')
      expect(vuetify.theme.styles.value).toContain('creator-dark')
    } else {
      expect(theme()).toBeUndefined()
      expect(vuetify.theme.styles.value).toBe(untouched)
    }
  })

  it('lets the themes go when the route leaves the creator look', async () => {
    const { router, vuetify, theme } = await setup()
    const untouched = vuetify.theme.styles.value

    await router.push({ name: 'auth.sign-in' })
    await flushPromises()
    expect(theme()).toBe('creator-dark')

    await router.push({ name: 'auth.agency-sign-in' })
    await flushPromises()
    expect(theme()).toBeUndefined()
    expect(vuetify.theme.styles.value).toBe(untouched)
  })
})
