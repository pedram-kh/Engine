/**
 * ClaimImpersonationPage unit tests (Sprint 13, D-9 / D-10).
 *
 * Focus: the hand-off seam — a valid token claims, pins the banner state,
 * re-bootstraps the auth store as the impersonated user, and lands on the
 * dashboard; a missing / invalid token renders an inline error instead of
 * bouncing.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

const clearUser = vi.fn()
const bootstrap = vi.fn().mockResolvedValue(undefined)
vi.mock('@/modules/auth/stores/useAuthStore', () => ({
  useAuthStore: () => ({ clearUser, bootstrap }),
}))

vi.mock('../api/impersonation.api', async () => {
  const actual = await vi.importActual<typeof import('../api/impersonation.api')>(
    '../api/impersonation.api',
  )
  return {
    ...actual,
    impersonationApi: { claim: vi.fn(), status: vi.fn(), end: vi.fn() },
  }
})

import enImpersonation from '@/core/i18n/locales/en/impersonation.json'

import { impersonationApi } from '../api/impersonation.api'
import { useImpersonationStore } from '../stores/useImpersonationStore'
import ClaimImpersonationPage from './ClaimImpersonationPage.vue'

function setHash(hash: string): void {
  window.location.hash = hash
}

async function mountPage(): Promise<{ router: Router; wrapper: ReturnType<typeof mount> }> {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en: { ...enImpersonation } },
  }) as unknown as ReturnType<typeof createI18n>
  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/impersonation/claim',
        name: 'impersonation.claim',
        component: ClaimImpersonationPage,
      },
      { path: '/', name: 'app.dashboard', component: { template: '<div/>' } },
    ],
  })
  await router.push('/impersonation/claim')
  await router.isReady()

  const wrapper = mount(ClaimImpersonationPage, {
    global: { plugins: [createPinia(), router, i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
  return { router, wrapper }
}

describe('ClaimImpersonationPage (Sprint 13, D-9/D-10)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    setHash('')
  })

  afterEach(() => {
    vi.restoreAllMocks()
    document.body.innerHTML = ''
    setHash('')
  })

  it('claims a valid token: pins the banner, re-bootstraps, and lands on the dashboard', async () => {
    setHash('#token=tok_abc')
    vi.mocked(impersonationApi.claim).mockResolvedValue({
      data: {
        id: '01HQUSER',
        type: 'users',
        attributes: {
          name: 'Dana Creator',
          email: 'dana@example.com',
          user_type: 'creator',
          impersonated: true,
          expires_at: '2026-06-07T05:00:00Z',
        },
      },
    })

    const { router } = await mountPage()
    const replace = vi.spyOn(router, 'replace')
    await flushPromises()

    expect(impersonationApi.claim).toHaveBeenCalledWith('tok_abc')
    expect(clearUser).toHaveBeenCalledOnce()
    expect(bootstrap).toHaveBeenCalledOnce()
    expect(useImpersonationStore().active).toBe(true)
    expect(replace).toHaveBeenCalledWith({ name: 'app.dashboard' })
  })

  it('renders an inline error when no token is present', async () => {
    setHash('')
    const { wrapper } = await mountPage()
    await flushPromises()

    expect(wrapper.find('[data-testid="impersonation-claim-error"]').exists()).toBe(true)
    expect(impersonationApi.claim).not.toHaveBeenCalled()
  })

  it('renders an inline error when the hand-off is invalid', async () => {
    setHash('#token=stale')
    vi.mocked(impersonationApi.claim).mockRejectedValue(
      new ApiError({ status: 403, code: 'admin.impersonation.invalid_handoff', message: 'no' }),
    )

    const { wrapper } = await mountPage()
    await flushPromises()

    expect(wrapper.find('[data-testid="impersonation-claim-error"]').exists()).toBe(true)
  })
})
