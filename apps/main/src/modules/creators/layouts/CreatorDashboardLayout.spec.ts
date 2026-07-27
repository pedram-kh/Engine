/**
 * Component tests for the creator topbar nav (Sprint 5 Chunk B, D-b13).
 *
 * Pins: the topbar carries Dashboard + Availability router links; the link
 * matching the current route gets the active class; the labels localize
 * across en/pt/it; and the bar renders under the dark theme.
 *
 * Heavy bits irrelevant to the nav (the user-menu VMenu/VSelect, ThemeToggle)
 * are stubbed to keep the mount lean under jsdom.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import { lightTheme, darkTheme } from '@catalyst/design-tokens/vuetify'
import enApp from '@/core/i18n/locales/en/app.json'
import enAvailability from '@/core/i18n/locales/en/availability.json'
import enNotifications from '@/core/i18n/locales/en/notifications.json'
import itAvailability from '@/core/i18n/locales/it/availability.json'
import ptAvailability from '@/core/i18n/locales/pt/availability.json'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

// The app-bar NotificationBell (S11.0 Ch3a) mounts a live unread-count poll.
// Stub the API it calls so the poll is INERT under this spec — these layout
// tests assert the topbar nav, not the bell, and must not carry an un-asserted
// network side effect (§5.2-adjacent: a spec shouldn't have a live side effect
// it doesn't assert). Mirrors how every other API-touching child is mocked in
// its host spec.
vi.mock('@/modules/notifications/api/notifications.api', () => ({
  notificationsApi: {
    list: vi.fn().mockResolvedValue({
      data: [],
      meta: { total: 0, page: 1, per_page: 8, last_page: 1, unread_count: 0 },
    }),
    unreadCount: vi.fn().mockResolvedValue({
      data: { type: 'notification_unread_count', attributes: { unread_count: 0 } },
    }),
    markRead: vi.fn().mockResolvedValue({}),
    readAll: vi.fn().mockResolvedValue({}),
  },
}))

// The layout bootstraps the onboarding store on mount so `applicationStatus`
// (and the conditional "Profile" nav item) resolves on any landed page. Stub the
// API so it's inert here — these tests assert the static nav, not the Profile
// branch. Resolve as `incomplete` so Profile stays hidden (the spec router has
// no `creator.profile` record, matching the original nav set).
vi.mock('@/modules/onboarding/api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn().mockResolvedValue({
      data: { type: 'creators', attributes: { application_status: 'incomplete' } },
    }),
  },
}))

import { onboardingApi } from '@/modules/onboarding/api/onboarding.api'

import CreatorDashboardLayout from './CreatorDashboardLayout.vue'

/**
 * `VMenu` teleports and renders nothing under `stubs: { VMenu: true }`, which
 * also swallows its ACTIVATOR — and the AH-057 "More" button lives in that
 * activator slot. This stub keeps both slots inline so the bottom bar's fifth
 * button and the overflow list are both reachable from the wrapper. Same
 * approach the apply-dialog spec takes with `VDialog`.
 */
const VMenuStub = {
  name: 'VMenu',
  template: '<div><slot name="activator" v-bind="{ props: {} }" /><slot /></div>',
}

/**
 * Vuetify's `useDisplay()` reads `window.innerWidth`, which jsdom fixes at 1024
 * — so the mobile chrome never rendered under test and the bottom bar went
 * unpinned (which is how AH-056's sixth nav item reached a real phone before it
 * reached a test). Narrowing the window BEFORE the Vuetify instance is created
 * puts `smAndDown` on, so the bar is testable after all.
 */
function setViewportWidth(width: number): void {
  Object.defineProperty(window, 'innerWidth', { value: width, writable: true, configurable: true })
  window.dispatchEvent(new Event('resize'))
}

async function mountLayout(
  options: {
    locale?: 'en' | 'pt' | 'it'
    route?: string
    dark?: boolean
    applicationStatus?: string
    mobile?: boolean
  } = {},
) {
  setViewportWidth(options.mobile === true ? 390 : 1280)

  // Always re-stated (not only when overridden): `vi.clearAllMocks()` clears
  // calls but keeps implementations, so a test that mounts as `approved` would
  // otherwise leak that status into every test after it.
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue({
    data: {
      type: 'creators',
      attributes: { application_status: options.applicationStatus ?? 'incomplete' },
    },
  } as never)

  const pinia = createPinia()
  setActivePinia(pinia)

  const auth = useAuthStore()
  auth.user = {
    id: '01USERULIDXXXXXXXXXXXXXXXXX',
    type: 'users',
    attributes: {
      email: 'creator@example.com',
      email_verified_at: null,
      name: 'Test Creator',
      user_type: 'creator',
      preferred_language: 'en',
      preferred_currency: null,
      timezone: null,
    },
  } as never

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/creator/dashboard', name: 'creator.dashboard', component: { template: '<div />' } },
      {
        path: '/creator/availability',
        name: 'creator.availability',
        component: { template: '<div />' },
      },
      {
        path: '/creator/assignments',
        name: 'creator.assignments',
        component: { template: '<div />' },
      },
      { path: '/creator/messages', name: 'creator.messages', component: { template: '<div />' } },
      { path: '/creator/jobs', name: 'creator.jobs', component: { template: '<div />' } },
      { path: '/creator/profile', name: 'creator.profile', component: { template: '<div />' } },
      // Reachable only once VMenu renders inline (the AH-057 stub): the
      // notification bell's "view all" and the avatar menu's preferences link.
      {
        path: '/creator/notifications',
        name: 'creator.notifications',
        component: { template: '<div />' },
      },
      {
        path: '/creator/notifications/preferences',
        name: 'creator.notifications.preferences',
        component: { template: '<div />' },
      },
      { path: '/sign-in', name: 'auth.sign-in', component: { template: '<div />' } },
    ],
  })
  await router.push(options.route ?? '/creator/dashboard')
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: options.locale ?? 'en',
    fallbackLocale: 'en',
    availableLocales: ['en', 'pt', 'it'],
    messages: {
      en: { ...enApp, ...enAvailability, ...enNotifications },
      pt: { ...enApp, ...ptAvailability, ...enNotifications },
      it: { ...enApp, ...itAvailability, ...enNotifications },
    } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
    theme: {
      defaultTheme: options.dark === true ? 'dark' : 'light',
      themes: { light: lightTheme, dark: darkTheme },
    },
  })

  const wrapper = mount(CreatorDashboardLayout, {
    global: {
      plugins: [pinia, router, i18n, vuetify],
      // `VSelect: true` warns under jsdom now that the avatar menu renders
      // inline (auto-stubs try to set `prefix` on a bare element); an empty
      // component is the same inertness without the noise.
      stubs: { VMenu: VMenuStub, VSelect: { template: '<div />' }, ThemeToggle: true },
    },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return { wrapper, cleanup: () => wrapper.unmount() }
}

describe('CreatorDashboardLayout — topbar nav (D-b13)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    if (cleanup !== null) {
      cleanup()
      cleanup = null
    }
  })

  it('renders the Dashboard + Availability nav items', async () => {
    const mounted = await mountLayout()
    cleanup = mounted.cleanup
    expect(mounted.wrapper.find('[data-test="creator-nav-dashboard"]').exists()).toBe(true)
    expect(mounted.wrapper.find('[data-test="creator-nav-availability"]').text()).toBe(
      'Availability',
    )
  })

  // Sprint 8 Chunk 2 (D-10): the campaign-invitation surface gets its own
  // topbar entry between Dashboard and Availability.
  it('renders the Invitations nav item (and localizes it)', async () => {
    const en = await mountLayout()
    expect(en.wrapper.find('[data-test="creator-nav-assignments"]').text()).toBe('Invitations')
    en.cleanup()

    const pt = await mountLayout({ locale: 'pt' })
    expect(pt.wrapper.find('[data-test="creator-nav-assignments"]').text()).toBe('Convites')
    pt.cleanup()

    const it = await mountLayout({ locale: 'it' })
    cleanup = it.cleanup
    expect(it.wrapper.find('[data-test="creator-nav-assignments"]').text()).toBe('Inviti')
  })

  it('marks the Availability item active on its route (and Dashboard inactive)', async () => {
    const mounted = await mountLayout({ route: '/creator/availability' })
    cleanup = mounted.cleanup
    expect(mounted.wrapper.find('[data-test="creator-nav-availability"]').classes()).toContain(
      'v-btn--active',
    )
    expect(mounted.wrapper.find('[data-test="creator-nav-dashboard"]').classes()).not.toContain(
      'v-btn--active',
    )
  })

  it('marks the Dashboard item active on its route', async () => {
    const mounted = await mountLayout({ route: '/creator/dashboard' })
    cleanup = mounted.cleanup
    expect(mounted.wrapper.find('[data-test="creator-nav-dashboard"]').classes()).toContain(
      'v-btn--active',
    )
  })

  it('localizes the Availability label in pt and it', async () => {
    const pt = await mountLayout({ locale: 'pt' })
    expect(pt.wrapper.find('[data-test="creator-nav-availability"]').text()).toBe('Disponibilidade')
    pt.cleanup()

    const it = await mountLayout({ locale: 'it' })
    cleanup = it.cleanup
    expect(it.wrapper.find('[data-test="creator-nav-availability"]').text()).toBe('Disponibilità')
  })

  // AH-056 (D9) — the jobs board is an APPROVED-creator surface. The nav item
  // follows that: an unapproved creator's board would be empty by the server
  // predicate, and an item that always leads nowhere is worse than no item.
  it('shows the Job Posts nav item to an approved creator', async () => {
    const mounted = await mountLayout({ applicationStatus: 'approved' })
    cleanup = mounted.cleanup
    expect(mounted.wrapper.find('[data-test="creator-nav-jobs"]').text()).toBe('Job Posts')
  })

  it('hides the Job Posts nav item from a pending or incomplete creator', async () => {
    const pending = await mountLayout({ applicationStatus: 'pending' })
    expect(pending.wrapper.find('[data-test="creator-nav-jobs"]').exists()).toBe(false)
    pending.cleanup()

    const incomplete = await mountLayout({ applicationStatus: 'incomplete' })
    cleanup = incomplete.cleanup
    expect(incomplete.wrapper.find('[data-test="creator-nav-jobs"]').exists()).toBe(false)
  })

  it('localizes the Job Posts label in pt and it', async () => {
    const pt = await mountLayout({ locale: 'pt', applicationStatus: 'approved' })
    expect(pt.wrapper.find('[data-test="creator-nav-jobs"]').text()).toBe('Ofertas de trabalho')
    pt.cleanup()

    const it = await mountLayout({ locale: 'it', applicationStatus: 'approved' })
    cleanup = it.cleanup
    expect(it.wrapper.find('[data-test="creator-nav-jobs"]').text()).toBe('Offerte di lavoro')
  })

  it('renders the nav under the dark theme', async () => {
    const mounted = await mountLayout({ dark: true })
    cleanup = mounted.cleanup
    expect(mounted.wrapper.find('[data-test="creator-nav"]').exists()).toBe(true)
    expect(mounted.wrapper.find('[data-test="creator-nav-availability"]').exists()).toBe(true)
  })
})

/**
 * AH-057 — the mobile bottom bar's overflow.
 *
 * A bottom bar neither wraps nor scrolls, so its width is a hard budget: past
 * four items the labels clip at the viewport edge. AH-056's "Job Posts" made a
 * sixth item and that is exactly what happened on a real phone. These tests pin
 * the budget itself, so the next section added to the creator shell lands in the
 * "More" sheet instead of breaking the bar again.
 */
describe('CreatorDashboardLayout — mobile bottom bar (AH-057)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    if (cleanup !== null) {
      cleanup()
      cleanup = null
    }
    setViewportWidth(1280)
  })

  it('never renders more than five buttons, however many sections exist', async () => {
    const mounted = await mountLayout({ mobile: true, applicationStatus: 'approved' })
    cleanup = mounted.cleanup

    const bar = mounted.wrapper.find('[data-test="creator-bottom-nav"]')
    expect(bar.exists()).toBe(true)

    // Six sections exist for an approved creator, and all six stay reachable —
    // but the BAR shows five. That upper bound is the whole point: a bar that
    // grows with the section count is a bar that clips.
    expect(bar.findAll('a').length).toBe(6)
    const barButtons = bar.findAll('[data-test^="creator-bottom-nav-"]').filter((el) => {
      const marker = el.attributes('data-test') ?? ''
      return !marker.includes('more-') && marker !== 'creator-bottom-nav-more-wrapper'
    })
    expect(barButtons.length).toBe(5)
  })

  it('gives the working loop the four permanent slots', async () => {
    const mounted = await mountLayout({ mobile: true, applicationStatus: 'approved' })
    cleanup = mounted.cleanup
    const w = mounted.wrapper

    for (const key of ['dashboard', 'jobs', 'assignments', 'messages']) {
      expect(w.find(`[data-test="creator-bottom-nav-${key}"]`).exists()).toBe(true)
    }
    // Settings-shaped destinations do NOT hold a slot.
    expect(w.find('[data-test="creator-bottom-nav-profile"]').exists()).toBe(false)
    expect(w.find('[data-test="creator-bottom-nav-availability"]').exists()).toBe(false)
    expect(w.find('[data-test="creator-bottom-nav-more"]').exists()).toBe(true)
  })

  it('puts every displaced item in the More sheet — nothing is dropped', async () => {
    const mounted = await mountLayout({ mobile: true, applicationStatus: 'approved' })
    cleanup = mounted.cleanup
    const w = mounted.wrapper

    // The union of bar + sheet must be the WHOLE nav. An item that appears in
    // neither is unreachable on a phone, which is a worse bug than clipping.
    expect(w.find('[data-test="creator-bottom-nav-more-profile"]').exists()).toBe(true)
    expect(w.find('[data-test="creator-bottom-nav-more-availability"]').exists()).toBe(true)
    expect(w.findAll('[data-test="creator-bottom-nav-more-list"] .v-list-item').length).toBe(2)
    expect(w.find('[data-test="creator-bottom-nav-more"]').text()).toContain('More')
  })

  it('keeps every primary slot filled — a renamed nav key cannot empty one', async () => {
    const mounted = await mountLayout({ mobile: true, applicationStatus: 'approved' })
    cleanup = mounted.cleanup

    // The policy list names keys as strings, so it can drift from `navItems`
    // silently. Asserting every named slot RESOLVED to a rendered link is what
    // catches a rename that leaves a hole where a destination used to be.
    const links = mounted.wrapper
      .findAll('[data-test="creator-bottom-nav"] a')
      .map((el) => el.attributes('data-test'))

    expect(links).toEqual([
      'creator-bottom-nav-dashboard',
      'creator-bottom-nav-jobs',
      'creator-bottom-nav-assignments',
      'creator-bottom-nav-messages',
      'creator-bottom-nav-more-profile',
      'creator-bottom-nav-more-availability',
    ])
  })

  it('shrinks the bar rather than leaving a gap when a section is hidden', async () => {
    // An incomplete creator has no Job Posts and no Profile, so the bar holds
    // three primaries and More carries Availability alone.
    const mounted = await mountLayout({ mobile: true, applicationStatus: 'incomplete' })
    cleanup = mounted.cleanup
    const w = mounted.wrapper

    expect(w.find('[data-test="creator-bottom-nav-jobs"]').exists()).toBe(false)
    expect(w.find('[data-test="creator-bottom-nav-dashboard"]').exists()).toBe(true)
    expect(w.findAll('[data-test="creator-bottom-nav-more-list"] .v-list-item').length).toBe(1)
    expect(w.find('[data-test="creator-bottom-nav-more-availability"]').exists()).toBe(true)
  })

  it('localizes the More label', async () => {
    const pt = await mountLayout({ mobile: true, locale: 'pt', applicationStatus: 'approved' })
    expect(pt.wrapper.find('[data-test="creator-bottom-nav-more"]').text()).toContain('Mais')
    pt.cleanup()

    const it = await mountLayout({ mobile: true, locale: 'it', applicationStatus: 'approved' })
    cleanup = it.cleanup
    expect(it.wrapper.find('[data-test="creator-bottom-nav-more"]').text()).toContain('Altro')
  })

  it('stays off the desktop shell entirely', async () => {
    const mounted = await mountLayout({ applicationStatus: 'approved' })
    cleanup = mounted.cleanup
    expect(mounted.wrapper.find('[data-test="creator-bottom-nav"]').exists()).toBe(false)
    expect(mounted.wrapper.find('[data-test="creator-nav"]').exists()).toBe(true)
  })
})
