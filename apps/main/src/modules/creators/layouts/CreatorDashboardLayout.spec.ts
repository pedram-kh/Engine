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
import enCreator from '@/core/i18n/locales/en/creator.json'
import enNotifications from '@/core/i18n/locales/en/notifications.json'
import frAvailability from '@/core/i18n/locales/fr/availability.json'
import itAvailability from '@/core/i18n/locales/it/availability.json'
import ptAvailability from '@/core/i18n/locales/pt/availability.json'
import { SHELL_TAB_ROUTE_NAMES } from '@/composables/useShellBack'
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
import { notificationsApi } from '@/modules/notifications/api/notifications.api'

// AH-100 — the Capacitor platform probe. Web unless a test opts in, so every
// pre-AH-100 test below runs the web shell exactly as before.
const platform = vi.hoisted(() => ({ native: false }))
vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
}))

// AH-108 (4b) — the shell writes the bell total to the home-screen badge. The module is
// faked here so the spec asserts what the LAYOUT hands it (the badge's own platform gate
// and writes are pinned in `core/platform/badge`).
const iconBadgeFake = vi.hoisted(() => ({
  enable: vi.fn(),
  set: vi.fn().mockResolvedValue(undefined),
  clear: vi.fn().mockResolvedValue(undefined),
}))
vi.mock('@/core/platform/badge/iconBadge', () => ({
  iconBadge: iconBadgeFake,
  clearIconBadge: () => iconBadgeFake.clear(),
}))

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
    locale?: 'en' | 'pt' | 'it' | 'fr'
    route?: string
    dark?: boolean
    applicationStatus?: string
    mobile?: boolean
    native?: boolean
  } = {},
) {
  setViewportWidth(options.mobile === true ? 390 : 1280)
  platform.native = options.native === true

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
    availableLocales: ['en', 'pt', 'it', 'fr'],
    messages: {
      en: { ...enApp, ...enAvailability, ...enCreator, ...enNotifications },
      pt: { ...enApp, ...ptAvailability, ...enNotifications },
      it: { ...enApp, ...itAvailability, ...enNotifications },
      fr: { ...enApp, ...frAvailability, ...enNotifications },
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

/**
 * AH-100 D4 — inside the Capacitor app the bottom tabs are there because the
 * shell is native, not because the screen is narrow; each native difference
 * is pinned next to its web polarity.
 */
describe('CreatorDashboardLayout — creator app shell (AH-100)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    if (cleanup !== null) {
      cleanup()
      cleanup = null
    }
    platform.native = false
    setViewportWidth(1280)
  })

  it('native at a desktop width: bottom tabs, no topbar nav', async () => {
    const mounted = await mountLayout({ native: true, applicationStatus: 'approved' })
    cleanup = mounted.cleanup

    expect(mounted.wrapper.find('[data-test="creator-bottom-nav"]').exists()).toBe(true)
    expect(mounted.wrapper.find('[data-test="creator-nav"]').exists()).toBe(false)
  })

  it('native: the Figma modifiers are bound; web: none of them are', async () => {
    const native = await mountLayout({ native: true, applicationStatus: 'approved' })
    for (const modifier of [
      'creator-layout--native',
      'creator-topbar--native',
      'creator-main--native',
      'creator-bottom-nav--native',
    ]) {
      expect(native.wrapper.find(`.${modifier}`).exists(), modifier).toBe(true)
    }
    native.cleanup()

    const web = await mountLayout({ mobile: true, applicationStatus: 'approved' })
    cleanup = web.cleanup
    expect(web.wrapper.html()).not.toContain('--native')
  })

  it('native: no theme toggle; web: the toggle stays', async () => {
    const native = await mountLayout({ native: true })
    expect(native.wrapper.findComponent({ name: 'ThemeToggle' }).exists()).toBe(false)
    native.cleanup()

    const web = await mountLayout()
    cleanup = web.cleanup
    expect(web.wrapper.findComponent({ name: 'ThemeToggle' }).exists()).toBe(true)
  })

  it('native: More carries subtitles and a Settings entry to notification preferences', async () => {
    const mounted = await mountLayout({ native: true, applicationStatus: 'approved' })
    cleanup = mounted.cleanup
    const w = mounted.wrapper

    expect(w.find('[data-test="creator-bottom-nav-more-profile"]').text()).toContain(
      'Photo, bio and socials',
    )
    expect(w.find('[data-test="creator-bottom-nav-more-availability"]').text()).toContain(
      'Block out dates',
    )
    const settings = w.find('[data-test="creator-bottom-nav-more-settings"]')
    expect(settings.text()).toContain('Settings')
    expect(settings.text()).toContain('Notification preferences')
    expect(settings.attributes('href')).toBe('/creator/notifications/preferences')
  })

  it('web mobile: More keeps its pre-AH-100 items — no Settings, no subtitles', async () => {
    const mounted = await mountLayout({ mobile: true, applicationStatus: 'approved' })
    cleanup = mounted.cleanup
    const w = mounted.wrapper

    expect(w.find('[data-test="creator-bottom-nav-more-settings"]').exists()).toBe(false)
    expect(
      w.find('[data-test="creator-bottom-nav-more-list"] .v-list-item-subtitle').exists(),
    ).toBe(false)
  })
})

/**
 * AH-100d — the French tab bar collided: "Tableau de bord" and "Offres de
 * mission" overlap or clip in a fifth of a 390px phone. The app's bar reads its
 * own short labels (`availability.creatorNav.tab.*`); the web's bottom bar and
 * topbar keep the full ones, so a browser's labels are unchanged.
 */
describe('CreatorDashboardLayout — tab bar labels (AH-100d)', () => {
  let cleanup: (() => void) | null = null
  afterEach(() => cleanup?.())

  const labels = (w: Awaited<ReturnType<typeof mountLayout>>['wrapper']) =>
    ['dashboard', 'jobs', 'assignments', 'messages', 'more'].map((k) =>
      w.find(`[data-test="creator-bottom-nav-${k}"]`).text(),
    )

  it('the app bar uses the short French labels', async () => {
    const m = await mountLayout({
      native: true,
      mobile: true,
      locale: 'fr',
      applicationStatus: 'approved',
    })
    cleanup = m.cleanup

    expect(labels(m.wrapper)).toEqual(['Accueil', 'Offres', 'Invitations', 'Messages', 'Plus'])
  })

  it('the web bottom bar keeps the full French labels', async () => {
    const m = await mountLayout({ mobile: true, locale: 'fr', applicationStatus: 'approved' })
    cleanup = m.cleanup

    expect(labels(m.wrapper)).toEqual([
      'Tableau de bord',
      'Offres de mission',
      'Invitations',
      'Messages',
      'Plus',
    ])
  })
})

/**
 * AH-106 (item 1) — the shell's back arrow. The rule itself (which pages, where
 * it goes) is `useShellBack.spec.ts`; this pins that the LAYOUT renders it, on
 * both shells, with a name a screen reader can use.
 */
describe('CreatorDashboardLayout — shell back arrow (AH-106)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    if (cleanup !== null) {
      cleanup()
      cleanup = null
    }
    platform.native = false
    setViewportWidth(1280)
  })

  const BACK = '[data-test="creator-shell-back"]'

  it.each([
    ['web', { route: '/creator/notifications' }],
    ['web, preferences', { route: '/creator/notifications/preferences' }],
    ['native', { route: '/creator/notifications', native: true }],
    ['native, preferences', { route: '/creator/notifications/preferences', native: true }],
    ['native, a More-sheet page', { route: '/creator/availability', native: true }],
  ])('shows on a non-tab page (%s), named "Back"', async (_label, options) => {
    const m = await mountLayout({ applicationStatus: 'approved', ...options })
    cleanup = m.cleanup

    const arrow = m.wrapper.find(BACK)
    expect(arrow.exists()).toBe(true)
    expect(arrow.attributes('aria-label')).toBe('Back')
  })

  it.each(SHELL_TAB_ROUTE_NAMES.map((name) => [name]))('is absent on the tab %s', async (name) => {
    const path = {
      'creator.dashboard': '/creator/dashboard',
      'creator.jobs': '/creator/jobs',
      'creator.assignments': '/creator/assignments',
      'creator.messages': '/creator/messages',
    }[name as string]
    const m = await mountLayout({ applicationStatus: 'approved', native: true, route: path })
    cleanup = m.cleanup

    expect(m.wrapper.find(BACK).exists()).toBe(false)
  })

  it('the tab list it skips is exactly the four primary slots of the bottom bar', async () => {
    const m = await mountLayout({ mobile: true, applicationStatus: 'approved' })
    cleanup = m.cleanup

    const router = m.wrapper.vm.$router
    const primaryRouteNames = ['dashboard', 'jobs', 'assignments', 'messages'].map(
      (key) =>
        router.resolve(
          m.wrapper.find(`[data-test="creator-bottom-nav-${key}"]`).attributes('href') ?? '',
        ).name,
    )

    expect([...primaryRouteNames].sort()).toEqual([...SHELL_TAB_ROUTE_NAMES].sort())
  })

  it('goes to the dashboard when there is nothing to go back to', async () => {
    const m = await mountLayout({ route: '/creator/notifications' })
    cleanup = m.cleanup
    const router = m.wrapper.vm.$router

    await m.wrapper.find(BACK).trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('creator.dashboard')
  })
})

/**
 * AH-108 — the Messages tab's unread badge.
 *
 * One shell poll feeds both the bell (total) and the Messages item (message
 * split). The badge is on the Messages item ONLY, hides at zero, caps at 99+,
 * and keeps the real count in the button's accessible name.
 */
describe('CreatorDashboardLayout — Messages unread badge (AH-108)', () => {
  let cleanup: (() => void) | null = null

  function counts(total: number, messages: number | undefined): void {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue({
      data: {
        type: 'notification_unread_count',
        attributes: {
          unread_count: total,
          ...(messages === undefined ? {} : { message_unread_count: messages }),
        },
      },
    } as never)
    // The stubbed VMenu renders the bell's dropdown inline, and an open dropdown
    // reconciles `meta.unread_count` back into the poll — keep that in step.
    vi.mocked(notificationsApi.list).mockResolvedValue({
      data: [],
      meta: { total: 0, page: 1, per_page: 8, last_page: 1, unread_count: total },
    } as never)
  }

  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    if (cleanup !== null) {
      cleanup()
      cleanup = null
    }
    // Leave the file-level default (zero) for any later block.
    counts(0, undefined)
  })

  it.each([
    [1, '1'],
    [12, '12'],
    [99, '99'],
    [100, '99+'],
  ])('draws %i unread messages as "%s" on the native bottom bar', async (count, glyph) => {
    counts(count, count)
    const mounted = await mountLayout({ mobile: true, native: true, applicationStatus: 'approved' })
    cleanup = mounted.cleanup

    const badge = mounted.wrapper.find('[data-test="creator-messages-unread-badge-bottom"]')
    expect(badge.find('.v-badge__badge').text()).toBe(glyph)
  })

  it('draws the badge on the web desktop nav too', async () => {
    counts(12, 12)
    const mounted = await mountLayout({ applicationStatus: 'approved' })
    cleanup = mounted.cleanup

    const badge = mounted.wrapper.find('[data-test="creator-messages-unread-badge-top"]')
    expect(badge.find('.v-badge__badge').text()).toBe('12')
  })

  it('draws the badge on the web mobile bottom bar too', async () => {
    counts(3, 3)
    const mounted = await mountLayout({ mobile: true, applicationStatus: 'approved' })
    cleanup = mounted.cleanup

    const badge = mounted.wrapper.find('[data-test="creator-messages-unread-badge-bottom"]')
    expect(badge.find('.v-badge__badge').text()).toBe('3')
  })

  it('hides the badge at zero message notifications — even with other unread ones', async () => {
    counts(5, 0)
    const mounted = await mountLayout({ mobile: true, native: true, applicationStatus: 'approved' })
    cleanup = mounted.cleanup

    const badge = mounted.wrapper.find('[data-test="creator-messages-unread-badge-bottom"]')
    expect(badge.exists()).toBe(true)
    // Vuetify hides a zero badge by `display: none` (model-value), it does not remove it.
    expect(badge.find('.v-badge__badge').isVisible()).toBe(false)
  })

  it('reads an older backend (no split) as zero', async () => {
    counts(5, undefined)
    const mounted = await mountLayout({ mobile: true, native: true, applicationStatus: 'approved' })
    cleanup = mounted.cleanup

    const badge = mounted.wrapper.find('[data-test="creator-messages-unread-badge-bottom"]')
    expect(badge.find('.v-badge__badge').isVisible()).toBe(false)
  })

  it('puts the badge on Messages only — no other nav item carries one', async () => {
    counts(9, 9)
    const mounted = await mountLayout({ mobile: true, native: true, applicationStatus: 'approved' })
    cleanup = mounted.cleanup

    const bar = mounted.wrapper.find('[data-test="creator-bottom-nav"]')
    expect(bar.findAll('.v-badge__badge').filter((el) => el.isVisible())).toHaveLength(1)
    for (const key of ['dashboard', 'jobs', 'assignments']) {
      expect(bar.find(`[data-test="creator-bottom-nav-${key}"]`).find('.v-badge').exists()).toBe(
        false,
      )
    }
  })

  it('names the Messages button with the REAL count, not the capped glyph', async () => {
    counts(140, 140)
    const mounted = await mountLayout({ mobile: true, native: true, applicationStatus: 'approved' })
    cleanup = mounted.cleanup

    const button = mounted.wrapper.find('[data-test="creator-bottom-nav-messages"]')
    expect(button.attributes('aria-label')).toBe('Messages, unread: 140')
    expect(
      mounted.wrapper
        .find('[data-test="creator-messages-unread-badge-bottom"] .v-badge__badge')
        .text(),
    ).toBe('99+')
  })

  it('leaves the visible label as the name at zero, and on every other item', async () => {
    counts(0, 0)
    const mounted = await mountLayout({ mobile: true, native: true, applicationStatus: 'approved' })
    cleanup = mounted.cleanup

    expect(
      mounted.wrapper.find('[data-test="creator-bottom-nav-messages"]').attributes('aria-label'),
    ).toBeUndefined()
    expect(
      mounted.wrapper.find('[data-test="creator-bottom-nav-jobs"]').attributes('aria-label'),
    ).toBeUndefined()
  })

  it('writes the BELL total (not the message count) to the icon badge once it has loaded', async () => {
    counts(9, 4)
    const mounted = await mountLayout({ mobile: true, native: true, applicationStatus: 'approved' })
    cleanup = mounted.cleanup

    expect(iconBadgeFake.enable).toHaveBeenCalled()
    expect(iconBadgeFake.set).toHaveBeenCalledWith(9)
    expect(iconBadgeFake.set).not.toHaveBeenCalledWith(4)
  })

  it('runs ONE unread-count request for the bell and the Messages tab together', async () => {
    counts(9, 4)
    const mounted = await mountLayout({ mobile: true, native: true, applicationStatus: 'approved' })
    cleanup = mounted.cleanup

    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(1)
    // The bell shows the TOTAL, the Messages tab the message split, from that one response.
    expect(mounted.wrapper.find('[data-test="notification-badge"] .v-badge__badge').text()).toBe(
      '9',
    )
    expect(
      mounted.wrapper
        .find('[data-test="creator-messages-unread-badge-bottom"] .v-badge__badge')
        .text(),
    ).toBe('4')
  })
})
