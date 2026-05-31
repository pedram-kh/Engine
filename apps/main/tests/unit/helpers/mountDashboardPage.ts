/**
 * Theme-aware mount helper for the agency dashboard surface
 * (Sprint 4 Chunk 1, 1b).
 *
 * The "new dashboard helper is theme-aware" half of closing the
 * stock-theme harness tech-debt entry (the other half is
 * `packages/ui/tests/helpers/mountThemed.ts`). Unlike the established
 * `mountAuthPage.ts` — which is DELIBERATELY left stock-theme this chunk
 * (D-c1-11, destabilization risk) — this helper builds Vuetify with the
 * REAL Catalyst `light`/`dark` themes, dark-default, so dashboard specs
 * exercise the product's actual theme.
 *
 * Seeds Pinia (auth + agency stores), a memory-history router carrying the
 * real route table, and the full i18n bundle (incl. the new `dashboard.*`
 * namespace). API mocking is left to each spec via `vi.mock(...)`.
 */

import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import type { Component } from 'vue'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import { lightTheme, darkTheme } from '@catalyst/design-tokens/vuetify'

import enApp from '@/core/i18n/locales/en/app.json'
import enAuth from '@/core/i18n/locales/en/auth.json'
import enCreator from '@/core/i18n/locales/en/creator.json'
import enDashboard from '@/core/i18n/locales/en/dashboard.json'
import itApp from '@/core/i18n/locales/it/app.json'
import itAuth from '@/core/i18n/locales/it/auth.json'
import itCreator from '@/core/i18n/locales/it/creator.json'
import itDashboard from '@/core/i18n/locales/it/dashboard.json'
import ptApp from '@/core/i18n/locales/pt/app.json'
import ptAuth from '@/core/i18n/locales/pt/auth.json'
import ptCreator from '@/core/i18n/locales/pt/creator.json'
import ptDashboard from '@/core/i18n/locales/pt/dashboard.json'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { routes } from '@/modules/auth/routes'

export type ThemeMode = 'light' | 'dark'

export interface MountDashboardOptions {
  /** Theme to mount under. Defaults to `'dark'` (the SPA dark-default). */
  mode?: ThemeMode
  /** Initial locale. Defaults to `'en'`. */
  locale?: 'en' | 'pt' | 'it'
  /** Authenticated user's display name (seeded onto the auth store). */
  userName?: string | null
  /**
   * Agency the workspace is operating in. Defaults to a single membership
   * with this ULID; pass `null` to seed NO membership (currentAgencyId
   * stays null — the "no agency yet" path).
   */
  agencyId?: string | null
}

export interface MountDashboardResult<T> {
  wrapper: VueWrapper<T>
  router: Router
  pinia: Pinia
  unmount: () => void
}

export async function mountDashboardPage<T = unknown>(
  component: Component,
  options: MountDashboardOptions = {},
): Promise<MountDashboardResult<T>> {
  const pinia = createPinia()
  setActivePinia(pinia)

  const agencyId = options.agencyId === undefined ? 'agency-ulid' : options.agencyId

  const agencyStore = useAgencyStore()
  if (agencyId !== null) {
    agencyStore.initFromUser([
      { agency_id: agencyId, agency_name: 'Test Agency', role: 'agency_admin' },
    ])
  }

  const authStore = useAuthStore()
  authStore.user = {
    id: 'user-ulid',
    type: 'users',
    attributes: {
      name: options.userName === undefined ? 'Ada Lovelace' : (options.userName ?? ''),
      email: 'ada@example.com',
    },
  } as unknown as typeof authStore.user

  const router = createRouter({ history: createMemoryHistory(), routes })
  await router.push('/')
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: options.locale ?? 'en',
    fallbackLocale: 'en',
    availableLocales: ['en', 'pt', 'it'],
    messages: {
      en: { ...enApp, ...enAuth, ...enCreator, ...enDashboard },
      pt: { ...ptApp, ...ptAuth, ...ptCreator, ...ptDashboard },
      it: { ...itApp, ...itAuth, ...itCreator, ...itDashboard },
    },
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
    theme: {
      defaultTheme: options.mode ?? 'dark',
      themes: { light: lightTheme, dark: darkTheme },
    },
  })

  const wrapper = mount(component, {
    global: { plugins: [pinia, router, i18n, vuetify] },
    attachTo: document.createElement('div'),
  }) as unknown as VueWrapper<T>

  return {
    wrapper,
    router,
    pinia,
    unmount: () => {
      wrapper.unmount()
    },
  }
}
