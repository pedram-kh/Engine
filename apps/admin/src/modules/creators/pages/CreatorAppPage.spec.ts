/**
 * CreatorAppPage unit tests (AH-102b, Half B).
 *
 * The backend owns the Active / Signed out / Never derivation
 * (AdminCreatorAppUsageTest.php, with its negatives); this spec asserts the SPA
 * renders the three states, sends exactly the param each control promises,
 * resets paging on any filter change, debounces the search, and keeps the
 * sign-ins dialog honest ("sign-ins", "—" for a missing version, a deep link
 * that carries the creator ULID).
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/creators/api/creatorApp.api', async () => {
  const actual = await vi.importActual<typeof import('@/modules/creators/api/creatorApp.api')>(
    '@/modules/creators/api/creatorApp.api',
  )
  return {
    ...actual,
    adminCreatorAppApi: {
      users: vi.fn(),
      signIns: vi.fn(),
    },
  }
})

import {
  adminCreatorAppApi,
  type AdminCreatorAppSignIn,
  type AdminCreatorAppUser,
  type AdminCreatorAppUsersResponse,
} from '@/modules/creators/api/creatorApp.api'
import { creatorsRoutes } from '@/modules/creators/routes'

import enCreators from '@/core/i18n/locales/en/creators.json'

import { mountCreatorPage } from '../../../../tests/unit/helpers/mountCreatorPage'
import CreatorAppPage from './CreatorAppPage.vue'

function user(
  id: string,
  attrs: Partial<AdminCreatorAppUser['attributes']> = {},
): AdminCreatorAppUser {
  return {
    id,
    type: 'creator_app_users',
    attributes: {
      creator: {
        id,
        display_name: `Creator ${id}`,
        email: `${id.toLowerCase()}@example.test`,
        is_erased: false,
        deletion_pending: false,
      },
      app_status: 'active',
      live_sign_ins: 1,
      platforms: ['ios'],
      last_seen_at: '2026-10-12T09:00:00Z',
      last_app_sign_in_at: '2026-10-10T09:00:00Z',
      app_version: '2.4.1',
      push_state: 'registered',
      push_invalidation_reason: null,
      last_push_registered_at: '2026-10-11T09:00:00Z',
      ...attrs,
    },
  }
}

function usersPage(
  data: AdminCreatorAppUser[],
  total = data.length,
  page = 1,
): AdminCreatorAppUsersResponse {
  // `last_page` follows the total, as the backend's does: the page's Q10 clamp
  // reads it, so a fixed 1 would clamp every page forward.
  return {
    data,
    meta: { total, page, per_page: 25, last_page: Math.max(1, Math.ceil(total / 25)) },
  }
}

function signIn(over: Partial<AdminCreatorAppSignIn> = {}): AdminCreatorAppSignIn {
  return {
    platform: 'ios',
    signed_in_at: '2026-10-10T09:00:00Z',
    last_seen_at: '2026-10-12T09:00:00Z',
    state: 'live',
    expires_at: null,
    app_version: '2.4.1',
    push_state: 'registered',
    push_registered_at: '2026-10-11T09:00:00Z',
    ...over,
  }
}

const BASE = {
  app_status: undefined,
  platform: undefined,
  push: undefined,
  search: undefined,
  page: 1,
  per_page: 25,
}

describe('CreatorAppPage (AH-102b)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(adminCreatorAppApi.users).mockResolvedValue(usersPage([user('01HQACTIVE')]))
    vi.mocked(adminCreatorAppApi.signIns).mockResolvedValue({
      data: [signIn()],
      meta: { total: 1, limit: 50 },
    })
  })

  afterEach(() => {
    vi.useRealTimers()
    teardown?.()
    teardown = null
    document.body.innerHTML = ''
  })

  async function mount(query: Record<string, string> = {}) {
    const h = await mountCreatorPage(CreatorAppPage, {
      initialRoute: { name: 'app.creators.app', query },
    })
    teardown = h.unmount
    await flushPromises()
    return h
  }

  it('loads with no filter params and renders a row', async () => {
    const h = await mount()

    expect(adminCreatorAppApi.users).toHaveBeenCalledWith(BASE)
    expect(h.wrapper.text()).toContain('Creator 01HQACTIVE')
    expect(h.wrapper.text()).toContain('01hqactive@example.test')
    expect(h.wrapper.text()).toContain('2.4.1')
  })

  it('issues ONE request on mount (the table echo does not double-fetch)', async () => {
    await mount()

    expect(adminCreatorAppApi.users).toHaveBeenCalledTimes(1)
  })

  it('renders the three states as chips', async () => {
    vi.mocked(adminCreatorAppApi.users).mockResolvedValue(
      usersPage([
        user('01HQA', { app_status: 'active' }),
        user('01HQS', {
          app_status: 'signed_out',
          live_sign_ins: 0,
          platforms: [],
          last_seen_at: null,
        }),
        user('01HQN', {
          app_status: 'never',
          live_sign_ins: 0,
          platforms: [],
          last_seen_at: null,
          last_app_sign_in_at: null,
          app_version: null,
          push_state: 'none',
        }),
      ]),
    )
    const h = await mount()

    expect(h.wrapper.find('[data-testid="admin-creator-app-status-01HQA"]').text()).toBe('Active')
    expect(h.wrapper.find('[data-testid="admin-creator-app-status-01HQS"]').text()).toBe(
      'Signed out',
    )
    expect(h.wrapper.find('[data-testid="admin-creator-app-status-01HQN"]').text()).toBe(
      'Never signed in',
    )
  })

  it('renders each push state, with the invalidation reason for a dead one', async () => {
    vi.mocked(adminCreatorAppApi.users).mockResolvedValue(
      usersPage([
        user('01HQR', { push_state: 'registered' }),
        user('01HQD', { push_state: 'dead', push_invalidation_reason: 'provider_unregistered' }),
        user('01HQO', { push_state: 'none', app_version: null }),
      ]),
    )
    const h = await mount()

    expect(h.wrapper.find('[data-testid="admin-creator-app-push-01HQR"]').text()).toBe(
      'Push registered',
    )
    expect(h.wrapper.find('[data-testid="admin-creator-app-push-01HQD"]').text()).toBe(
      'Push token invalid',
    )
    expect(h.wrapper.text()).toContain('provider_unregistered')
    expect(h.wrapper.find('[data-testid="admin-creator-app-push-01HQO"]').text()).toBe('No push')
  })

  it.each([
    ['status', 'signed_out', { app_status: 'signed_out' }],
    ['status', 'never', { app_status: 'never' }],
    ['status', 'active', { app_status: 'active' }],
    ['platform', 'android', { platform: 'android' }],
    ['platform', 'ios', { platform: 'ios' }],
    ['push', 'registered', { push: 'registered' }],
    ['push', 'dead', { push: 'dead' }],
    ['push', 'none', { push: 'none' }],
  ])('the %s=%s chip sends exactly its own param', async (group, value, expected) => {
    const h = await mount()
    vi.mocked(adminCreatorAppApi.users).mockClear()

    await h.wrapper
      .find(`[data-testid="admin-creator-app-filter-${group}-${value}"]`)
      .trigger('click')
    await flushPromises()

    expect(adminCreatorAppApi.users).toHaveBeenCalledTimes(1)
    expect(adminCreatorAppApi.users).toHaveBeenCalledWith({ ...BASE, ...expected })
  })

  it('filters AND-compose and a filter change resets paging to page 1', async () => {
    vi.mocked(adminCreatorAppApi.users).mockResolvedValue(usersPage([user('01HQACTIVE')], 80, 2))
    // Page 2 arrives through the URL, as it does on a back from the push deliveries page.
    const h = await mount({ page: '2' })
    expect(adminCreatorAppApi.users).toHaveBeenLastCalledWith({ ...BASE, page: 2 })

    await h.wrapper.find('[data-testid="admin-creator-app-filter-status-active"]').trigger('click')
    await flushPromises()
    await h.wrapper.find('[data-testid="admin-creator-app-filter-platform-ios"]').trigger('click')
    await flushPromises()

    expect(adminCreatorAppApi.users).toHaveBeenLastCalledWith({
      ...BASE,
      app_status: 'active',
      platform: 'ios',
      page: 1,
    })
    // The URL carries both chips and no page.
    await vi.waitFor(() =>
      expect(h.router.currentRoute.value.query).toEqual({ status: 'active', platform: 'ios' }),
    )
  })

  it('the search is debounced: many keystrokes, ONE request after the pause, from page 1', async () => {
    const h = await mount()
    vi.mocked(adminCreatorAppApi.users).mockClear()
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })

    const input = h.wrapper.find('[data-testid="admin-creator-app-search"] input')
    for (const text of ['a', 'ad', 'ada']) {
      await input.setValue(text)
      vi.advanceTimersByTime(100)
    }
    // 300 ms after the FIRST keystroke is not a pause: the last one was 100 ms ago.
    await flushPromises()
    expect(adminCreatorAppApi.users).not.toHaveBeenCalled()
    expect(h.router.currentRoute.value.query).toEqual({})

    vi.advanceTimersByTime(300)
    await flushPromises()

    expect(adminCreatorAppApi.users).toHaveBeenCalledTimes(1)
    expect(adminCreatorAppApi.users).toHaveBeenCalledWith({ ...BASE, search: 'ada' })
    // `q` reaches the URL after the pause, and not before it. Deterministic, no real-time wait:
    // the fake clock STAYS installed. (The old version restored real timers here, which drops
    // every timer the router push had already queued on the fake clock, so the URL write could
    // never land: it passed only when an earlier test had warmed the route, and failed alone.)
    // Advancing the fake clock in small steps lets those timers fire; the loop is bounded and
    // the assertion below is the verdict.
    for (let i = 0; i < 20 && h.router.currentRoute.value.query.q !== 'ada'; i += 1) {
      await vi.advanceTimersByTimeAsync(50)
      await flushPromises()
    }
    expect(h.router.currentRoute.value.query).toEqual({ q: 'ada' })
  })

  it('shows the empty state and the error surface', async () => {
    vi.mocked(adminCreatorAppApi.users).mockResolvedValue(usersPage([]))
    const empty = await mount()
    expect(empty.wrapper.text()).toContain('No creators match these filters.')
    empty.unmount()

    vi.mocked(adminCreatorAppApi.users).mockRejectedValue(new Error('boom'))
    const failing = await mount()
    expect(failing.wrapper.find('[data-testid="admin-creator-app-error"]').text()).toContain(
      "We couldn't load creator app usage",
    )
  })

  it('an unknown/transport error code shows the page message, and no false "no creators" claim', async () => {
    vi.mocked(adminCreatorAppApi.users).mockRejectedValue(
      new ApiError({ status: 500, code: 'http.unknown_error', message: 'x' }),
    )
    const h = await mount()

    const error = h.wrapper.find('[data-testid="admin-creator-app-error"]').text()
    expect(error).toContain("We couldn't load creator app usage")
    expect(error).not.toContain('http.unknown_error')
    expect(h.wrapper.text()).not.toContain('No creators match these filters.')
  })

  describe('URL-backed browse context (AH-117)', () => {
    it('seeds the chips, the page and the text field from the URL, with one request; a junk URL reads as the defaults', async () => {
      vi.mocked(adminCreatorAppApi.users).mockResolvedValue(usersPage([user('01HQACTIVE')], 120, 3))

      const seeded = await mount({
        status: 'signed_out',
        platform: 'android',
        push: 'dead',
        q: 'ada',
        page: '3',
        per_page: '10',
      })

      expect(adminCreatorAppApi.users).toHaveBeenCalledTimes(1)
      expect(adminCreatorAppApi.users).toHaveBeenCalledWith({
        app_status: 'signed_out',
        platform: 'android',
        push: 'dead',
        search: 'ada',
        page: 3,
        per_page: 10,
      })
      const input = seeded.wrapper.find('[data-testid="admin-creator-app-search"] input')
      expect((input.element as HTMLInputElement).value).toBe('ada')
      // Nothing was rewritten: the URL the operator arrived on is the URL they keep.
      await new Promise((resolve) => setTimeout(resolve, 400))
      expect(seeded.router.currentRoute.value.query).toEqual({
        status: 'signed_out',
        platform: 'android',
        push: 'dead',
        q: 'ada',
        page: '3',
        per_page: '10',
      })
      expect(adminCreatorAppApi.users).toHaveBeenCalledTimes(1)
      seeded.unmount()
      teardown = null
      vi.mocked(adminCreatorAppApi.users).mockClear()

      await mount({
        status: 'bogus',
        platform: 'windows',
        push: 'maybe',
        page: '-2',
        per_page: '100000',
      })

      expect(adminCreatorAppApi.users).toHaveBeenCalledTimes(1)
      expect(adminCreatorAppApi.users).toHaveBeenCalledWith(BASE)
    })

    it('a chip click from page 3 goes to page 1: one request, and the URL has the chip and no page', async () => {
      vi.mocked(adminCreatorAppApi.users).mockResolvedValue(usersPage([user('01HQACTIVE')], 120, 3))
      const h = await mount({ page: '3' })
      vi.mocked(adminCreatorAppApi.users).mockClear()

      await h.wrapper.find('[data-testid="admin-creator-app-filter-push-dead"]').trigger('click')
      await flushPromises()

      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({ push: 'dead' }))
      expect(adminCreatorAppApi.users).toHaveBeenCalledTimes(1)
      expect(adminCreatorAppApi.users).toHaveBeenCalledWith({ ...BASE, push: 'dead' })
    })

    it('a typed term resets to page 1 with one request, and a trailing space is not a new term', async () => {
      vi.mocked(adminCreatorAppApi.users).mockResolvedValue(usersPage([user('01HQACTIVE')], 120, 3))
      const h = await mount({ page: '3', q: 'ada' })
      vi.mocked(adminCreatorAppApi.users).mockClear()

      const input = h.wrapper.find('[data-testid="admin-creator-app-search"] input')
      await input.setValue('ada ')
      await new Promise((resolve) => setTimeout(resolve, 450))
      expect(adminCreatorAppApi.users).not.toHaveBeenCalled()
      expect(h.router.currentRoute.value.query).toEqual({ page: '3', q: 'ada' })
      expect((input.element as HTMLInputElement).value).toBe('ada ')

      await input.setValue('adam')
      await new Promise((resolve) => setTimeout(resolve, 450))
      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({ q: 'adam' }))
      expect(adminCreatorAppApi.users).toHaveBeenCalledTimes(1)
      expect(adminCreatorAppApi.users).toHaveBeenCalledWith({ ...BASE, search: 'adam' })
    })

    it('clearing the search (Vuetify emits null) reloads with no term and takes `q` out of the URL', async () => {
      const h = await mount({ q: 'ada' })
      vi.mocked(adminCreatorAppApi.users).mockClear()

      const field = h.wrapper.findComponent<typeof import('vuetify/components').VTextField>(
        '[data-testid="admin-creator-app-search"]',
      )
      field.vm.$emit('update:modelValue', null)
      await new Promise((resolve) => setTimeout(resolve, 450))

      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({}))
      expect(adminCreatorAppApi.users).toHaveBeenCalledTimes(1)
      expect(adminCreatorAppApi.users).toHaveBeenCalledWith(BASE)
      expect(h.wrapper.find('[data-testid="admin-creator-app-error"]').exists()).toBe(false)
    })

    it('re-seeds when the route changes underneath it: a bare sidebar URL clears the chips, the text field and the page', async () => {
      vi.mocked(adminCreatorAppApi.users).mockResolvedValue(usersPage([user('01HQACTIVE')], 120, 3))
      const h = await mount({ status: 'active', push: 'dead', q: 'ada', page: '3' })
      vi.mocked(adminCreatorAppApi.users).mockClear()

      await h.router.replace({ name: 'app.creators.app' })
      await flushPromises()

      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({}))
      const input = h.wrapper.find('[data-testid="admin-creator-app-search"] input')
      expect((input.element as HTMLInputElement).value).toBe('')
      // Let any (wrongly) armed debounce fire before counting.
      await new Promise((resolve) => setTimeout(resolve, 450))
      expect(adminCreatorAppApi.users).toHaveBeenCalledTimes(1)
      expect(adminCreatorAppApi.users).toHaveBeenCalledWith(BASE)
    })

    it('a route re-seed drops the keystrokes still waiting on the pause', async () => {
      vi.mocked(adminCreatorAppApi.users).mockResolvedValue(usersPage([user('01HQACTIVE')], 120, 3))
      const h = await mount({ q: 'ada', page: '3' })
      vi.mocked(adminCreatorAppApi.users).mockClear()

      await h.wrapper.find('[data-testid="admin-creator-app-search"] input').setValue('adamx')
      await new Promise((resolve) => setTimeout(resolve, 100))
      await h.router.replace({ name: 'app.creators.app' })
      await flushPromises()
      await new Promise((resolve) => setTimeout(resolve, 450))

      expect(h.router.currentRoute.value.query).toEqual({})
      expect(adminCreatorAppApi.users).toHaveBeenCalledTimes(1)
      expect(adminCreatorAppApi.users).toHaveBeenCalledWith(BASE)
    })

    it('clamps a page past the last one to the last page: ONE refetch, the empty page never shown', async () => {
      let resolveLast!: (value: AdminCreatorAppUsersResponse) => void
      vi.mocked(adminCreatorAppApi.users).mockImplementation((params) =>
        params?.page === 2
          ? new Promise<AdminCreatorAppUsersResponse>((resolve) => {
              resolveLast = resolve
            })
          : Promise.resolve({
              data: [user('01HQOUTOFRANGE')],
              meta: { total: 30, page: 9, per_page: 25, last_page: 2 },
            }),
      )
      const h = await mount({ page: '9' })

      // The out-of-range response is on screen at no point while the refetch is out.
      expect(h.wrapper.text()).not.toContain('Creator 01HQOUTOFRANGE')

      resolveLast(usersPage([user('01HQLASTPAGE')], 30, 2))
      await flushPromises()
      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({ page: '2' }))

      expect(adminCreatorAppApi.users).toHaveBeenCalledTimes(2)
      expect(vi.mocked(adminCreatorAppApi.users).mock.calls[1]![0]).toEqual({ ...BASE, page: 2 })
      expect(h.wrapper.text()).toContain('Creator 01HQLASTPAGE')
      expect(h.wrapper.text()).not.toContain('Creator 01HQOUTOFRANGE')
    })

    it('pages through the table by REPLACING the URL, and offers 10, 25, 50 and 100 rows (no "All")', async () => {
      vi.mocked(adminCreatorAppApi.users).mockResolvedValue(usersPage([user('01HQACTIVE')], 120))
      const h = await mount()
      vi.mocked(adminCreatorAppApi.users).mockClear()
      const push = vi.spyOn(h.router, 'push')

      expect(
        h.wrapper.findComponent({ name: 'VDataTableServer' }).props('itemsPerPageOptions'),
      ).toEqual([10, 25, 50, 100])

      await h.wrapper.find('button[aria-label="Next page"]').trigger('click')
      await flushPromises()
      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({ page: '2' }))

      expect(adminCreatorAppApi.users).toHaveBeenCalledTimes(1)
      expect(adminCreatorAppApi.users).toHaveBeenCalledWith({ ...BASE, page: 2 })
      expect(push).not.toHaveBeenCalled()
    })

    it('a page-size change from the table is one request and lands in the URL', async () => {
      vi.mocked(adminCreatorAppApi.users).mockResolvedValue(usersPage([user('01HQACTIVE')], 120))
      const h = await mount()
      vi.mocked(adminCreatorAppApi.users).mockClear()

      h.wrapper.findComponent({ name: 'VDataTableServer' }).vm.$emit('update:options', {
        page: 1,
        itemsPerPage: 50,
        sortBy: [],
        groupBy: [],
        search: undefined,
      })
      await flushPromises()
      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({ per_page: '50' }))
      expect(adminCreatorAppApi.users).toHaveBeenCalledTimes(1)
      expect(adminCreatorAppApi.users).toHaveBeenCalledWith({ ...BASE, per_page: 50 })
    })

    it('Creator app → push deliveries → back returns to the same list state, writing nothing on the way', async () => {
      vi.mocked(adminCreatorAppApi.users).mockResolvedValue(usersPage([user('01HQACTIVE')], 120, 3))
      const seededQuery = { status: 'signed_out', q: 'ada', page: '3' }
      const h = await mount(seededQuery)
      vi.mocked(adminCreatorAppApi.users).mockClear()
      const replace = vi.spyOn(h.router, 'replace')

      // The dialog's deep link: a real push (the browser's click), with its own query.
      await h.router.push({
        name: 'app.operations.push-deliveries',
        query: { creator: '01HQACTIVE', creator_name: 'Creator 01HQACTIVE' },
      })
      await flushPromises()
      expect(h.router.currentRoute.value.name).toBe('app.operations.push-deliveries')
      // The list neither re-seeded from, nor wrote onto, the other page's URL.
      expect(h.router.currentRoute.value.query).toEqual({
        creator: '01HQACTIVE',
        creator_name: 'Creator 01HQACTIVE',
      })

      h.router.back()
      await vi.waitFor(() => expect(h.router.currentRoute.value.name).toBe('app.creators.app'))
      await flushPromises()
      await new Promise((resolve) => setTimeout(resolve, 400))

      expect(h.router.currentRoute.value.query).toEqual(seededQuery)
      const input = h.wrapper.find('[data-testid="admin-creator-app-search"] input')
      expect((input.element as HTMLInputElement).value).toBe('ada')
      expect(replace).not.toHaveBeenCalled()
      expect(adminCreatorAppApi.users).not.toHaveBeenCalled()
    })

    it('drops a stale response: the slow first request cannot overwrite the newer one', async () => {
      let resolveFirst!: (value: AdminCreatorAppUsersResponse) => void
      vi.mocked(adminCreatorAppApi.users)
        .mockImplementationOnce(
          () =>
            new Promise<AdminCreatorAppUsersResponse>((resolve) => {
              resolveFirst = resolve
            }),
        )
        .mockResolvedValue(usersPage([user('01HQNEWER')]))
      const h = await mount()

      await h.wrapper
        .find('[data-testid="admin-creator-app-filter-status-active"]')
        .trigger('click')
      await flushPromises()
      expect(h.wrapper.text()).toContain('Creator 01HQNEWER')

      resolveFirst(usersPage([user('01HQSTALE')]))
      await flushPromises()

      expect(h.wrapper.text()).toContain('Creator 01HQNEWER')
      expect(h.wrapper.text()).not.toContain('Creator 01HQSTALE')
    })
  })

  describe('sign-ins dialog', () => {
    async function openDialog() {
      const h = await mount()
      await h.wrapper.find('[data-testid="admin-creator-app-view-01HQACTIVE"]').trigger('click')
      await flushPromises()
      return h
    }

    const dialog = (): HTMLElement | null =>
      document.body.querySelector('[data-testid="admin-creator-app-signins"]')

    it("opens, fetches that creator's sign-ins by ULID, and renders them", async () => {
      await openDialog()

      expect(adminCreatorAppApi.signIns).toHaveBeenCalledWith('01HQACTIVE')
      expect(dialog()?.textContent).toContain('Sign-ins')
      expect(dialog()?.textContent).toContain('Creator 01HQACTIVE')
      expect(dialog()?.textContent).toContain('Live')
      expect(dialog()?.textContent).toContain('iOS')
      expect(dialog()?.textContent).toContain('Showing the latest 1 of 1 sign-ins')
    })

    it('renders "—" for a sign-in that never registered for push (no app version), and Expired', async () => {
      vi.mocked(adminCreatorAppApi.signIns).mockResolvedValue({
        data: [
          signIn({ app_version: null, push_state: 'none', push_registered_at: null }),
          signIn({
            state: 'expired',
            platform: 'android',
            app_version: '1.0.0',
            push_state: 'none',
          }),
        ],
        meta: { total: 2, limit: 50 },
      })
      await openDialog()

      expect(
        document.body
          .querySelector('[data-testid="admin-creator-app-signin-version-0"]')
          ?.textContent?.trim(),
      ).toBe('—')
      expect(
        document.body
          .querySelector('[data-testid="admin-creator-app-signin-version-1"]')
          ?.textContent?.trim(),
      ).toBe('1.0.0')
      expect(dialog()?.textContent).toContain('Expired')
    })

    it('states plainly when there are no sign-ins', async () => {
      vi.mocked(adminCreatorAppApi.signIns).mockResolvedValue({
        data: [],
        meta: { total: 0, limit: 50 },
      })
      await openDialog()
      expect(
        document.body.querySelector('[data-testid="admin-creator-app-signins-empty"]')?.textContent,
      ).toContain('No sign-ins on record.')
    })

    it('an unknown/transport error code shows the dialog message, never the raw key', async () => {
      vi.mocked(adminCreatorAppApi.signIns).mockRejectedValue(
        new ApiError({ status: 500, code: 'http.unknown_error', message: 'x' }),
      )
      await openDialog()

      const text =
        document.body.querySelector('[data-testid="admin-creator-app-signins-error"]')
          ?.textContent ?? ''
      expect(text).toContain("We couldn't load the sign-ins")
      expect(text).not.toContain('http.unknown_error')
    })

    it('shows the error surface when the sign-ins fail to load', async () => {
      vi.mocked(adminCreatorAppApi.signIns).mockRejectedValue(new Error('boom'))
      await openDialog()

      expect(
        document.body.querySelector('[data-testid="admin-creator-app-signins-error"]')?.textContent,
      ).toContain("We couldn't load the sign-ins")
    })

    it('the deep link to push deliveries carries the creator ULID (and the display name)', async () => {
      await openDialog()

      const href = document.body
        .querySelector('[data-testid="admin-creator-app-signins-deliveries"]')
        ?.getAttribute('href')

      expect(href).toContain('/operations/push-deliveries')
      expect(href).toContain('creator=01HQACTIVE')
      expect(href).toContain('creator_name=Creator+01HQACTIVE')
    })
  })
})

describe('Creator app copy and routing (AH-102b)', () => {
  it('SIGN-INS wording: no creator-app string says "device(s)"', () => {
    const strings: string[] = []
    const walk = (node: unknown): void => {
      if (typeof node === 'string') strings.push(node)
      else if (node !== null && typeof node === 'object') Object.values(node).forEach(walk)
    }
    walk(enCreators.admin.creators.app)

    expect(strings.length).toBeGreaterThan(20)
    expect(strings.filter((s) => /\bdevices?\b/i.test(s))).toEqual([])
    expect(strings.some((s) => /sign-in/i.test(s))).toBe(true)
  })

  it('/creators/app is registered BEFORE /creators/:ulid, and resolves to the app page', async () => {
    const paths = creatorsRoutes.map((r) => r.path)
    expect(paths.indexOf('/creators/app')).toBeGreaterThanOrEqual(0)
    expect(paths.indexOf('/creators/app')).toBeLessThan(paths.indexOf('/creators/:ulid'))

    const h = await mountCreatorPage(CreatorAppPage, { initialRoute: '/creators/app' })
    try {
      expect(h.router.currentRoute.value.name).toBe('app.creators.app')
    } finally {
      h.unmount()
    }
  })

  it('reads an erased and a leaving creator through the reader (AH-104c C2-4)', async () => {
    const erased = user('01HQERASED')
    erased.attributes.creator = {
      id: '01HQERASED',
      display_name: null,
      email: 'erased-01hqerased@erased.invalid',
      is_erased: true,
      deletion_pending: false,
    }
    const leaving = user('01HQLEAVING')
    leaving.attributes.creator.deletion_pending = true
    vi.mocked(adminCreatorAppApi.users).mockResolvedValue(usersPage([erased, leaving]))

    const h = await mountCreatorPage(CreatorAppPage, { initialRoute: { name: 'app.creators.app' } })
    await flushPromises()

    expect(h.wrapper.text()).toContain('Deleted creator')
    expect(h.wrapper.text()).toContain('Creator 01HQLEAVING')
    expect(h.wrapper.text()).not.toContain('erased.invalid')
    expect(h.wrapper.findAll('[data-testid="admin-deletion-requested-chip"]')).toHaveLength(1)
    h.unmount()
  })
})
