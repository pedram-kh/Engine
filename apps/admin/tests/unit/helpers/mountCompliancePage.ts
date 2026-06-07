/**
 * Test harness for the admin SPA's compliance pages (Sprint 13, D-11).
 *
 * Mirror of `mountOperationsPage.ts` tuned for the compliance route table.
 * The i18n bundle is built with the SAME `deepMergeLocale` the real app
 * uses so the shared `admin.*` namespace across every module locale file
 * survives (a shallow spread would clobber siblings).
 */

import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router, type RouteLocationRaw } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import { deepMergeLocale } from '@/core/i18n/deepMerge'
import enAgencies from '@/core/i18n/locales/en/agencies.json'
import enApp from '@/core/i18n/locales/en/app.json'
import enAudit from '@/core/i18n/locales/en/audit.json'
import enAuth from '@/core/i18n/locales/en/auth.json'
import enCompliance from '@/core/i18n/locales/en/compliance.json'
import enCreators from '@/core/i18n/locales/en/creators.json'
import enDashboard from '@/core/i18n/locales/en/dashboard.json'
import enFeatureFlags from '@/core/i18n/locales/en/feature-flags.json'
import enOperations from '@/core/i18n/locales/en/operations.json'
import itAgencies from '@/core/i18n/locales/it/agencies.json'
import itApp from '@/core/i18n/locales/it/app.json'
import itAudit from '@/core/i18n/locales/it/audit.json'
import itAuth from '@/core/i18n/locales/it/auth.json'
import itCompliance from '@/core/i18n/locales/it/compliance.json'
import itCreators from '@/core/i18n/locales/it/creators.json'
import itDashboard from '@/core/i18n/locales/it/dashboard.json'
import itFeatureFlags from '@/core/i18n/locales/it/feature-flags.json'
import itOperations from '@/core/i18n/locales/it/operations.json'
import ptAgencies from '@/core/i18n/locales/pt/agencies.json'
import ptApp from '@/core/i18n/locales/pt/app.json'
import ptAudit from '@/core/i18n/locales/pt/audit.json'
import ptAuth from '@/core/i18n/locales/pt/auth.json'
import ptCompliance from '@/core/i18n/locales/pt/compliance.json'
import ptCreators from '@/core/i18n/locales/pt/creators.json'
import ptDashboard from '@/core/i18n/locales/pt/dashboard.json'
import ptFeatureFlags from '@/core/i18n/locales/pt/feature-flags.json'
import ptOperations from '@/core/i18n/locales/pt/operations.json'

import { complianceRoutes } from '@/modules/compliance/routes'

import type { Component } from 'vue'

export interface MountCompliancePageOptions {
  initialRoute?: RouteLocationRaw
  locale?: 'en' | 'pt' | 'it'
  props?: Record<string, unknown>
  stubs?: Record<string, Component | boolean>
}

export interface MountCompliancePageResult<T> {
  wrapper: VueWrapper<T>
  router: Router
  pinia: Pinia
  i18n: ReturnType<typeof createI18n>
  vuetify: ReturnType<typeof createVuetify>
  unmount: () => void
}

export async function mountCompliancePage<T = unknown>(
  component: Component,
  options: MountCompliancePageOptions = {},
): Promise<MountCompliancePageResult<T>> {
  const pinia = createPinia()
  setActivePinia(pinia)

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [...complianceRoutes, { path: '/', name: 'home', component: { template: '<div/>' } }],
  })

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })

  const messages = {
    en: deepMergeLocale(
      enApp,
      enAuth,
      enCreators,
      enAgencies,
      enAudit,
      enFeatureFlags,
      enDashboard,
      enOperations,
      enCompliance,
    ),
    pt: deepMergeLocale(
      ptApp,
      ptAuth,
      ptCreators,
      ptAgencies,
      ptAudit,
      ptFeatureFlags,
      ptDashboard,
      ptOperations,
      ptCompliance,
    ),
    it: deepMergeLocale(
      itApp,
      itAuth,
      itCreators,
      itAgencies,
      itAudit,
      itFeatureFlags,
      itDashboard,
      itOperations,
      itCompliance,
    ),
  } as unknown as Record<'en' | 'pt' | 'it', Record<string, unknown>>

  const i18n = createI18n({
    legacy: false,
    locale: options.locale ?? 'en',
    fallbackLocale: 'en',
    availableLocales: ['en', 'pt', 'it'],
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    messages: messages as any,
  }) as unknown as ReturnType<typeof createI18n>

  const initial = options.initialRoute ?? '/compliance/export-requests'
  try {
    await router.push(initial)
  } catch {
    /* duplicated-navigation warning swallowed */
  }
  await router.isReady()

  const wrapper = mount(component, {
    props: options.props ?? {},
    global: {
      plugins: [pinia, router, i18n, vuetify],
      stubs: {
        RouterLink: true,
        ...(options.stubs ?? {}),
      },
    },
    attachTo: document.createElement('div'),
  }) as unknown as VueWrapper<T>

  return {
    wrapper,
    router,
    pinia,
    i18n,
    vuetify,
    unmount: () => {
      wrapper.unmount()
    },
  }
}
