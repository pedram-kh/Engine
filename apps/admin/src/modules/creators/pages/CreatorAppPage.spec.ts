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
      creator: { id, display_name: `Creator ${id}`, email: `${id.toLowerCase()}@example.test` },
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

function usersPage(data: AdminCreatorAppUser[], total = data.length): AdminCreatorAppUsersResponse {
  return { data, meta: { total, page: 1, per_page: 25, last_page: 1 } }
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

  async function mount() {
    const h = await mountCreatorPage(CreatorAppPage, { initialRoute: { name: 'app.creators.app' } })
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
    vi.mocked(adminCreatorAppApi.users).mockResolvedValue(usersPage([user('01HQACTIVE')], 80))
    const h = await mount()

    // Page 2 via the table's own pager.
    await h.wrapper.find('button[aria-label="Next page"]').trigger('click')
    await flushPromises()
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
    expect(adminCreatorAppApi.users).not.toHaveBeenCalled()

    vi.advanceTimersByTime(300)
    await flushPromises()

    expect(adminCreatorAppApi.users).toHaveBeenCalledTimes(1)
    expect(adminCreatorAppApi.users).toHaveBeenCalledWith({ ...BASE, search: 'ada' })
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
})
