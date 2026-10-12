/**
 * PushDeliveriesPage unit tests (AH-102b, Half A).
 *
 * The backend owns filtering, terminal-only counting and the cross-agency
 * read (AdminPushDeliveriesTest.php); this spec asserts the SPA sends exactly
 * the params each control promises, resets the cursor when the query changes,
 * and renders what the page is accountable for: the tiles, the footer, the
 * "what this counts" panel and the "Not tracked" email channel.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/operations/api/pushDeliveries.api', async () => {
  const actual = await vi.importActual<
    typeof import('@/modules/operations/api/pushDeliveries.api')
  >('@/modules/operations/api/pushDeliveries.api')
  return {
    ...actual,
    adminPushDeliveriesApi: {
      list: vi.fn(),
      summary: vi.fn(),
    },
  }
})

import {
  adminPushDeliveriesApi,
  type AdminPushDeliveriesResponse,
  type AdminPushDeliveriesSummary,
} from '@/modules/operations/api/pushDeliveries.api'

import { mountOperationsPage } from '../../../../tests/unit/helpers/mountOperationsPage'
import PushDeliveriesPage from './PushDeliveriesPage.vue'

function listPage(
  opts: {
    id?: string
    next?: string | null
    inApp?: 'read' | 'unread' | 'none'
    empty?: boolean
  } = {},
): AdminPushDeliveriesResponse {
  return {
    data: opts.empty
      ? []
      : [
          {
            id: opts.id ?? '501',
            type: 'push_deliveries',
            attributes: {
              created_at: '2026-10-12T10:00:00Z',
              platform: 'android',
              notification_type: 'assignment.invited',
              outcome: 'token_invalid',
              attempt: 1,
              final: true,
              code: 'UNREGISTERED',
              http_status: 404,
              detail: 'Requested entity was not found.',
              creator: {
                id: '01HQCREATOR',
                display_name: 'Ada Lovelace',
                email: 'ada@example.test',
              },
              in_app: opts.inApp ?? 'unread',
            },
          },
        ],
    meta: {
      per_page: 50,
      next_cursor: opts.next ?? null,
      prev_cursor: null,
      has_more: (opts.next ?? null) !== null,
    },
  }
}

function summary(
  over: Partial<AdminPushDeliveriesSummary['counts']> = {},
  table: AdminPushDeliveriesSummary['table'] = {
    total_rows: 1234,
    oldest_at: '2026-08-01T00:00:00Z',
  },
  window: AdminPushDeliveriesSummary['window'] = '7d',
): AdminPushDeliveriesSummary {
  return {
    window,
    since: '2026-10-05T00:00:00Z',
    counts: {
      delivered: 40,
      token_invalid: 3,
      retryable_final: 2,
      config_error: 0,
      rejected: 1,
      ...over,
    },
    table,
  }
}

describe('PushDeliveriesPage (AH-102b)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(adminPushDeliveriesApi.list).mockResolvedValue(listPage())
    vi.mocked(adminPushDeliveriesApi.summary).mockResolvedValue(summary())
  })

  afterEach(() => {
    teardown?.()
    teardown = null
    document.body.innerHTML = ''
  })

  async function mount(route = '/operations/push-deliveries') {
    const h = await mountOperationsPage(PushDeliveriesPage, { initialRoute: route })
    teardown = h.unmount
    await flushPromises()
    return h
  }

  it('loads the first page and the 7-day summary on mount, and renders tiles and rows', async () => {
    const h = await mount()

    expect(adminPushDeliveriesApi.summary).toHaveBeenCalledWith('7d')
    expect(adminPushDeliveriesApi.list).toHaveBeenCalledWith({
      outcome: undefined,
      notification_type: undefined,
      creator: undefined,
      date_from: undefined,
      date_to: undefined,
      per_page: 50,
      cursor: undefined,
    })
    expect(h.wrapper.find('[data-testid="admin-push-tile-delivered-count"]').text()).toBe('40')
    expect(h.wrapper.find('[data-testid="admin-push-tile-token_invalid-count"]').text()).toBe('3')
    expect(h.wrapper.find('[data-testid="admin-push-tile-retryable_final-count"]').text()).toBe('2')
    expect(h.wrapper.find('[data-testid="admin-push-tile-rejected-count"]').text()).toBe('1')

    const row = h.wrapper.find('[data-testid="admin-push-row-501"]')
    expect(row.text()).toContain('Ada Lovelace')
    expect(row.text()).toContain('assignment.invited')
    expect(row.text()).toContain('Token invalid')
    expect(row.text()).toContain('UNREGISTERED')
    expect(row.text()).toContain('Android')
  })

  it('switching the window re-fetches the SUMMARY with that window (and not the list)', async () => {
    const h = await mount()
    vi.mocked(adminPushDeliveriesApi.summary).mockResolvedValue(summary({}, undefined, '30d'))
    vi.mocked(adminPushDeliveriesApi.list).mockClear()
    vi.mocked(adminPushDeliveriesApi.summary).mockClear()

    await h.wrapper.find('[data-testid="admin-push-window-30d"]').trigger('click')
    await flushPromises()

    expect(adminPushDeliveriesApi.summary).toHaveBeenCalledTimes(1)
    expect(adminPushDeliveriesApi.summary).toHaveBeenCalledWith('30d')
    expect(adminPushDeliveriesApi.list).not.toHaveBeenCalled()
  })

  it('the Failures chip sends outcome=failures and resets the cursor to the first page', async () => {
    vi.mocked(adminPushDeliveriesApi.list).mockResolvedValue(listPage({ next: 'CURSOR_2' }))
    const h = await mount()

    // Walk to page 2 first, so there IS a cursor to reset.
    await h.wrapper.find('[data-testid="admin-push-next"]').trigger('click')
    await flushPromises()
    expect(adminPushDeliveriesApi.list).toHaveBeenLastCalledWith(
      expect.objectContaining({ cursor: 'CURSOR_2' }),
    )

    await h.wrapper.find('[data-testid="admin-push-outcome-failures"]').trigger('click')
    await flushPromises()

    expect(adminPushDeliveriesApi.list).toHaveBeenLastCalledWith(
      expect.objectContaining({ outcome: 'failures', cursor: undefined }),
    )
    // …and "previous" is disabled again: the history was reset.
    expect(h.wrapper.find('[data-testid="admin-push-prev"]').attributes('disabled')).toBeDefined()
  })

  it.each([['delivered'], ['token_invalid'], ['retryable'], ['config_error'], ['rejected']])(
    'the %s chip sends exactly outcome=%s',
    async (outcome) => {
      const h = await mount()
      await h.wrapper.find(`[data-testid="admin-push-outcome-${outcome}"]`).trigger('click')
      await flushPromises()

      expect(adminPushDeliveriesApi.list).toHaveBeenLastCalledWith(
        expect.objectContaining({ outcome }),
      )
    },
  )

  it('each text/date filter sends exactly its own param on Apply', async () => {
    const h = await mount()
    const cases: Array<[string, string, Record<string, string>]> = [
      [
        'admin-push-filter-type',
        'assignment.accepted',
        { notification_type: 'assignment.accepted' },
      ],
      ['admin-push-filter-creator', '01HQCREATOR', { creator: '01HQCREATOR' }],
      ['admin-push-filter-date-from', '2026-10-01', { date_from: '2026-10-01' }],
      ['admin-push-filter-date-to', '2026-10-09', { date_to: '2026-10-09' }],
    ]

    for (const [testid, value, expected] of cases) {
      const fresh = await mount()
      vi.mocked(adminPushDeliveriesApi.list).mockClear()
      await fresh.wrapper.find(`[data-testid="${testid}"] input`).setValue(value)
      await fresh.wrapper.find('[data-testid="admin-push-apply"]').trigger('click')
      await flushPromises()

      expect(adminPushDeliveriesApi.list).toHaveBeenCalledWith({
        outcome: undefined,
        notification_type: undefined,
        creator: undefined,
        date_from: undefined,
        date_to: undefined,
        per_page: 50,
        cursor: undefined,
        ...expected,
      })
      fresh.unmount()
    }
    expect(h.wrapper.exists()).toBe(true)
  })

  it('walks forward by next_cursor and back by the cursor stack', async () => {
    vi.mocked(adminPushDeliveriesApi.list).mockResolvedValue(listPage({ next: 'CURSOR_2' }))
    const h = await mount()

    vi.mocked(adminPushDeliveriesApi.list).mockResolvedValue(listPage({ id: '502' }))
    await h.wrapper.find('[data-testid="admin-push-next"]').trigger('click')
    await flushPromises()
    expect(adminPushDeliveriesApi.list).toHaveBeenLastCalledWith(
      expect.objectContaining({ cursor: 'CURSOR_2' }),
    )
    expect(h.wrapper.find('[data-testid="admin-push-row-502"]').exists()).toBe(true)

    await h.wrapper.find('[data-testid="admin-push-prev"]').trigger('click')
    await flushPromises()
    expect(adminPushDeliveriesApi.list).toHaveBeenLastCalledWith(
      expect.objectContaining({ cursor: undefined }),
    )
  })

  it('the config-error tile is prominent ONLY when its count is > 0', async () => {
    const quiet = await mount()
    expect(
      quiet.wrapper
        .find('[data-testid="admin-push-tile-config_error"]')
        .attributes('data-prominent'),
    ).toBe('false')
    expect(quiet.wrapper.find('[data-testid="admin-push-config-error-hint"]').exists()).toBe(false)
    quiet.unmount()

    vi.mocked(adminPushDeliveriesApi.summary).mockResolvedValue(summary({ config_error: 4 }))
    const loud = await mount()
    expect(
      loud.wrapper
        .find('[data-testid="admin-push-tile-config_error"]')
        .attributes('data-prominent'),
    ).toBe('true')
    expect(loud.wrapper.find('[data-testid="admin-push-config-error-hint"]').exists()).toBe(true)
  })

  it('footer: stored rows and oldest date; and a plain message for an empty table', async () => {
    const h = await mount()
    expect(h.wrapper.find('[data-testid="admin-push-footer"]').text()).toContain(
      'Stored rows: 1234',
    )
    expect(h.wrapper.find('[data-testid="admin-push-footer"]').text()).toContain('oldest:')
    h.unmount()

    vi.mocked(adminPushDeliveriesApi.summary).mockResolvedValue(
      summary({}, { total_rows: 0, oldest_at: null }),
    )
    vi.mocked(adminPushDeliveriesApi.list).mockResolvedValue(listPage({ empty: true }))
    const empty = await mount()
    expect(empty.wrapper.find('[data-testid="admin-push-footer"]').text()).toContain(
      'No push attempts stored yet.',
    )
    expect(empty.wrapper.find('[data-testid="admin-push-empty"]').exists()).toBe(true)
  })

  it('renders the in-app state per row (read / unread / none)', async () => {
    for (const state of ['read', 'unread', 'none'] as const) {
      vi.mocked(adminPushDeliveriesApi.list).mockResolvedValue(listPage({ inApp: state }))
      const h = await mount()
      expect(h.wrapper.find('[data-testid="admin-push-inapp-501"]').text()).toBe(
        { read: 'Read', unread: 'Unread', none: 'None' }[state],
      )
      h.unmount()
    }
  })

  it('shows the error surface when the list fails, with the API error code', async () => {
    vi.mocked(adminPushDeliveriesApi.list).mockRejectedValue(
      new ApiError({
        status: 500,
        code: 'admin.operations.push_deliveries.load_failed',
        message: 'x',
      }),
    )
    const h = await mount()

    expect(h.wrapper.find('[data-testid="admin-push-error"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-testid="admin-push-error"]').text()).toContain(
      "We couldn't load the push deliveries",
    )
  })

  it('an unknown/transport error code shows the page message, never the raw key, and no false empty claims', async () => {
    // `http.unknown_error` is what the api-client emits for a non-envelope 500
    // or a dropped connection; it has no translation.
    vi.mocked(adminPushDeliveriesApi.list).mockRejectedValue(
      new ApiError({ status: 500, code: 'http.unknown_error', message: 'x' }),
    )
    vi.mocked(adminPushDeliveriesApi.summary).mockRejectedValue(
      new ApiError({ status: 500, code: 'http.unknown_error', message: 'x' }),
    )
    const h = await mount()

    const error = h.wrapper.find('[data-testid="admin-push-error"]').text()
    expect(error).toContain("We couldn't load the push deliveries")
    expect(error).not.toContain('http.unknown_error')
    // A failed load must not claim "no push attempts match" / "none stored yet".
    expect(h.wrapper.find('[data-testid="admin-push-empty"]').exists()).toBe(false)
    expect(h.wrapper.find('[data-testid="admin-push-footer"]').text()).not.toContain(
      'No push attempts stored yet',
    )
  })

  it('names the email channel as NOT tracked, and the push and in-app channels as tracked', async () => {
    const h = await mount()
    const strip = h.wrapper.find('[data-testid="admin-push-channels"]')

    expect(strip.find('[data-testid="admin-push-channel-email"]').text()).toContain('Email')
    expect(strip.find('[data-testid="admin-push-channel-email"]').text()).toContain('not tracked')
    expect(strip.find('[data-testid="admin-push-channel-push"]').text()).toContain(
      'tracked in this table',
    )
    expect(strip.find('[data-testid="admin-push-channel-in-app"]').text()).toContain('read state')
  })

  it('carries the "What this counts" panel, including what it does NOT count', async () => {
    const h = await mount()
    const panel = h.wrapper.find('[data-testid="admin-push-counts-panel"]')

    expect(panel.text()).toContain('What this counts')
    expect(panel.text()).toContain('does not count')
    expect(panel.text()).toContain('throttled')
    expect(panel.text()).toContain('test pushes')
    expect(panel.text()).toContain('not that a phone displayed it')
  })

  it('deep link: ?creator prefills the filter and is sent; creator_name is shown but NEVER sent', async () => {
    const h = await mount(
      '/operations/push-deliveries?creator=01HQCREATOR&creator_name=Ada%20Lovelace',
    )

    expect(adminPushDeliveriesApi.list).toHaveBeenCalledWith(
      expect.objectContaining({ creator: '01HQCREATOR' }),
    )
    const sent = vi
      .mocked(adminPushDeliveriesApi.list)
      .mock.calls.flatMap((c) => Object.keys(c[0] ?? {}))
    expect(sent).not.toContain('creator_name')
    expect(JSON.stringify(vi.mocked(adminPushDeliveriesApi.list).mock.calls)).not.toContain(
      'Ada Lovelace',
    )
    expect(
      (
        h.wrapper.find('[data-testid="admin-push-filter-creator"] input')
          .element as HTMLInputElement
      ).value,
    ).toBe('01HQCREATOR')
    // The display name is the field's hint.
    expect(h.wrapper.find('[data-testid="admin-push-filter-creator"]').text()).toContain(
      'Ada Lovelace',
    )
  })
})
