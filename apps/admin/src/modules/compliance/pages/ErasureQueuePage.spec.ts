/**
 * ErasureQueuePage unit tests (AH-104c C2).
 *
 * The six-tab queue: each tab asks the API for its own state, every row carries one sentence
 * that says where the account stands and why (the stuck states say what to do), the Erased tab
 * shows a reference and a date and nothing of the person, and placing or releasing a hold needs
 * a reason in a dialog. This replaces the Sprint 13 shell spec: no `meta.shell` path is left.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/compliance/api/compliance.api', async () => {
  const actual = await vi.importActual<typeof import('@/modules/compliance/api/compliance.api')>(
    '@/modules/compliance/api/compliance.api',
  )
  return {
    ...actual,
    adminComplianceApi: {
      listExports: vi.fn(),
      listErasureQueue: vi.fn(),
      holdErasure: vi.fn(),
      releaseErasure: vi.fn(),
    },
  }
})

import {
  adminComplianceApi,
  type ErasedQueueRow,
  type ErasureQueueCounts,
  type ErasureQueueResponse,
  type ErasureQueueRow,
  type ErasureTab,
} from '@/modules/compliance/api/compliance.api'

import { mountCompliancePage } from '../../../../tests/unit/helpers/mountCompliancePage'
import ErasureQueuePage from './ErasureQueuePage.vue'

const COUNTS: ErasureQueueCounts = {
  pending: 1,
  ready: 2,
  blocked: 3,
  held: 4,
  in_progress: 5,
  erased: 6,
  due_scan_capped: false,
}

function liveRow(
  id: string,
  state: ErasureQueueRow['attributes']['state'],
  attributes: Partial<ErasureQueueRow['attributes']> = {},
): ErasureQueueRow {
  return {
    id,
    type: 'erasure_queue_rows',
    attributes: {
      state,
      creator_ulid: `creator-${id}`,
      name: `Name ${id}`,
      email: `${id.toLowerCase()}@example.test`,
      requested_at: '2026-10-01T10:00:00Z',
      due_at: '2026-10-15T10:00:00Z',
      days_left: 0,
      held: false,
      held_at: null,
      held_by: null,
      claimed_at: null,
      ledger_state: null,
      blockers: [],
      ...attributes,
    },
  }
}

function erasedRow(id: string): ErasedQueueRow {
  return {
    id,
    type: 'erasure_queue_rows',
    attributes: { state: 'erased', ledger_state: 'completed', erased_at: '2026-10-20T10:00:00Z' },
  }
}

function response(
  tab: ErasureTab,
  data: ErasureQueueResponse['data'],
  nextCursor: string | null = null,
  counts: Partial<ErasureQueueCounts> = {},
): ErasureQueueResponse {
  return {
    data,
    meta: { tab, per_page: 25, next_cursor: nextCursor, counts: { ...COUNTS, ...counts } },
  }
}

describe('ErasureQueuePage (AH-104c C2)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
    document.body.innerHTML = ''
  })

  async function mountPage(first: ErasureQueueResponse = response('pending', [])) {
    vi.mocked(adminComplianceApi.listErasureQueue).mockResolvedValue(first)
    const h = await mountCompliancePage(ErasureQueuePage, {
      initialRoute: '/compliance/erasure-queue',
    })
    teardown = h.unmount
    await flushPromises()
    return h
  }

  function stateText(h: Awaited<ReturnType<typeof mountPage>>, id: string): string {
    return h.wrapper.find(`[data-testid="admin-erasure-state-${id}"]`).text()
  }

  it('opens on Pending and shows the six tabs with their counts', async () => {
    const h = await mountPage()

    expect(adminComplianceApi.listErasureQueue).toHaveBeenCalledWith(
      expect.objectContaining({ tab: 'pending' }),
    )
    const labels = ['pending', 'ready', 'blocked', 'held', 'in_progress', 'erased'].map((tab) =>
      h.wrapper.find(`[data-testid="admin-erasure-tab-${tab}"]`).text(),
    )
    expect(labels).toEqual([
      'Pending (1)',
      'Ready (2)',
      'Blocked (3)',
      'On hold (4)',
      'Erasing (5)',
      'Erased (6)',
    ])
  })

  it('asks the API for the tab that was clicked, and only that tab', async () => {
    const h = await mountPage()
    vi.mocked(adminComplianceApi.listErasureQueue).mockResolvedValue(
      response('blocked', [liveRow('B1', 'blocked', { blockers: ['payment'] })]),
    )

    await h.wrapper.find('[data-testid="admin-erasure-tab-blocked"]').trigger('click')
    await flushPromises()

    expect(adminComplianceApi.listErasureQueue).toHaveBeenLastCalledWith(
      expect.objectContaining({ tab: 'blocked', cursor: null }),
    )
    expect(h.wrapper.text()).toContain('Name B1')
  })

  it('says, per tab, where an account stands and why', async () => {
    const h = await mountPage(
      response('pending', [
        liveRow('P1', 'pending'),
        liveRow('R1', 'ready'),
        liveRow('B1', 'blocked', { blockers: ['payment', 'vendor'] }),
        liveRow('H1', 'held', { held_at: '2026-10-05T10:00:00Z', held_by: 'Ada Admin' }),
        liveRow('H2', 'held', { held_at: '2026-10-05T10:00:00Z', held_by: null }),
        liveRow('I1', 'in_progress', { ledger_state: 'claimed' }),
        liveRow('I2', 'in_progress', { ledger_state: 'started' }),
        liveRow('I3', 'in_progress', { ledger_state: 'db_done' }),
        liveRow('I4', 'in_progress', { ledger_state: 'incomplete' }),
      ]),
    )

    expect(stateText(h, 'P1')).toMatch(/^Waiting\. The grace period ends on /)
    expect(stateText(h, 'R1')).toBe('The grace period has ended and nothing stands in the way.')
    expect(stateText(h, 'B1')).toBe(
      'The grace period has ended, but it cannot be erased: a payment is still in progress; a payout provider account is still connected.',
    )
    expect(stateText(h, 'H1')).toMatch(/^On hold since .+\. Held by Ada Admin\.$/)
    expect(stateText(h, 'H2')).toMatch(/^On hold since .+\.$/)
    expect(stateText(h, 'I1')).toBe('Erasing has begun.')
    expect(stateText(h, 'I2')).toBe('Erasing is under way.')
    expect(stateText(h, 'I3')).toBe(
      'The database step is done. The files and the audit log are still to be finished.',
    )
    // The stuck state says what to do.
    expect(stateText(h, 'I4')).toBe(
      'The last run stopped before it finished. This needs an engineer: follow the erasure runbook.',
    )
  })

  it('never says "claim" to the admin', async () => {
    const h = await mountPage(
      response('in_progress', [liveRow('I1', 'in_progress', { ledger_state: 'incomplete' })]),
    )

    expect(h.wrapper.text().toLowerCase()).not.toContain('claim')
  })

  it('shows an erased account as a reference and a date, with nothing of the person', async () => {
    const h = await mountPage(response('erased', [erasedRow('01JERASED000000000000000001')]))
    await h.wrapper.find('[data-testid="admin-erasure-tab-erased"]').trigger('click')
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-erasure-erased-ref"]').text()).toBe(
      '01JERASED000000000000000001',
    )
    expect(stateText(h, '01JERASED000000000000000001')).toMatch(/^Erased on /)
    expect(h.wrapper.find('[data-testid^="admin-erasure-hold-"]').exists()).toBe(false)
    expect(h.wrapper.find('[data-testid^="admin-erasure-release-"]').exists()).toBe(false)
  })

  it('shows the empty state of the tab that is empty', async () => {
    const h = await mountPage(response('pending', []))
    expect(h.wrapper.text()).toContain('No account is waiting out its grace period.')

    vi.mocked(adminComplianceApi.listErasureQueue).mockResolvedValue(response('held', []))
    await h.wrapper.find('[data-testid="admin-erasure-tab-held"]').trigger('click')
    await flushPromises()
    expect(h.wrapper.text()).toContain('No account is on hold.')
  })

  it('appends the next page with the cursor', async () => {
    const h = await mountPage(response('pending', [liveRow('P1', 'pending')], 'CURSOR1'))
    vi.mocked(adminComplianceApi.listErasureQueue).mockResolvedValue(
      response('pending', [liveRow('P2', 'pending')], null),
    )

    await h.wrapper.find('[data-testid="admin-erasure-load-more"]').trigger('click')
    await flushPromises()

    expect(adminComplianceApi.listErasureQueue).toHaveBeenLastCalledWith(
      expect.objectContaining({ tab: 'pending', cursor: 'CURSOR1' }),
    )
    expect(h.wrapper.text()).toContain('Name P1')
    expect(h.wrapper.text()).toContain('Name P2')
    expect(h.wrapper.find('[data-testid="admin-erasure-load-more"]').exists()).toBe(false)
  })

  it('says so when the due scan was capped, on the due tabs only', async () => {
    const h = await mountPage(response('pending', [], null, { due_scan_capped: true }))
    expect(h.wrapper.find('[data-testid="admin-erasure-queue-capped"]').exists()).toBe(false)

    vi.mocked(adminComplianceApi.listErasureQueue).mockResolvedValue(
      response('ready', [], null, { due_scan_capped: true }),
    )
    await h.wrapper.find('[data-testid="admin-erasure-tab-ready"]').trigger('click')
    await flushPromises()
    expect(h.wrapper.find('[data-testid="admin-erasure-queue-capped"]').exists()).toBe(true)
  })

  it('surfaces the API error code on failure', async () => {
    vi.mocked(adminComplianceApi.listErasureQueue).mockRejectedValue(
      new ApiError({ status: 403, code: 'auth.forbidden', message: 'no' }),
    )

    const h = await mountCompliancePage(ErasureQueuePage, {
      initialRoute: '/compliance/erasure-queue',
    })
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-erasure-queue-error"]').exists()).toBe(true)
  })

  it('has no shell path left', () => {
    const source = readFileSync(path.resolve(__dirname, 'ErasureQueuePage.vue'), 'utf8')

    expect(source).not.toMatch(/meta\.shell|isShell|shellNotice/)
  })

  describe('the hold', () => {
    function dialogText(testid: string): string {
      return document.body.querySelector(`[data-testid="${testid}"]`)?.textContent?.trim() ?? ''
    }

    function type(reason: string): void {
      const textarea = document.body.querySelector<HTMLTextAreaElement>(
        '[data-testid="admin-erasure-hold-dialog-reason"] textarea',
      )!
      textarea.value = reason
      textarea.dispatchEvent(new Event('input'))
    }

    function confirmButton(): HTMLButtonElement {
      return document.body.querySelector<HTMLButtonElement>(
        '[data-testid="admin-erasure-hold-dialog-confirm"]',
      )!
    }

    it('offers Hold on live accounts that are not held, and Release on held ones', async () => {
      const h = await mountPage(
        response('pending', [
          liveRow('P1', 'pending'),
          liveRow('R1', 'ready'),
          liveRow('B1', 'blocked', { blockers: ['vendor'] }),
          liveRow('H1', 'held'),
          liveRow('I1', 'in_progress'),
        ]),
      )

      for (const id of ['P1', 'R1', 'B1']) {
        expect(h.wrapper.find(`[data-testid="admin-erasure-hold-${id}"]`).exists()).toBe(true)
      }
      expect(h.wrapper.find('[data-testid="admin-erasure-release-H1"]').exists()).toBe(true)
      expect(h.wrapper.find('[data-testid="admin-erasure-hold-H1"]').exists()).toBe(false)
      expect(h.wrapper.find('[data-testid="admin-erasure-hold-I1"]').exists()).toBe(false)
      expect(h.wrapper.find('[data-testid="admin-erasure-release-I1"]').exists()).toBe(false)
    })

    it('needs a reason, says it must carry no personal details, and sends it trimmed', async () => {
      const h = await mountPage(response('pending', [liveRow('P1', 'pending')]))
      vi.mocked(adminComplianceApi.holdErasure).mockResolvedValue({
        data: liveRow('P1', 'held'),
        meta: { changed: true },
      })

      await h.wrapper.find('[data-testid="admin-erasure-hold-P1"]').trigger('click')
      await flushPromises()

      expect(dialogText('admin-erasure-hold-dialog-title')).toBe('Place a hold')
      expect(dialogText('admin-erasure-hold-dialog-pii')).toContain('personal details')
      expect(confirmButton().disabled).toBe(true)

      type('too short')
      await flushPromises()
      expect(confirmButton().disabled).toBe(true)

      type('   Open legal matter, reference on file   ')
      await flushPromises()
      expect(confirmButton().disabled).toBe(false)

      confirmButton().click()
      await flushPromises()

      expect(adminComplianceApi.holdErasure).toHaveBeenCalledWith(
        'P1',
        'Open legal matter, reference on file',
      )
      // The tab is reloaded so the row and the counts come from the server.
      expect(adminComplianceApi.listErasureQueue).toHaveBeenCalledTimes(2)
    })

    it('releases a hold with a reason', async () => {
      const h = await mountPage(response('held', [liveRow('H1', 'held')]))
      vi.mocked(adminComplianceApi.releaseErasure).mockResolvedValue({
        data: liveRow('H1', 'ready'),
        meta: { changed: true },
      })

      await h.wrapper.find('[data-testid="admin-erasure-release-H1"]').trigger('click')
      await flushPromises()
      expect(dialogText('admin-erasure-hold-dialog-title')).toBe('Release the hold')

      type('Matter closed, reference on file')
      await flushPromises()
      confirmButton().click()
      await flushPromises()

      expect(adminComplianceApi.releaseErasure).toHaveBeenCalledWith(
        'H1',
        'Matter closed, reference on file',
      )
      expect(adminComplianceApi.holdErasure).not.toHaveBeenCalled()
    })

    it('shows the refusal in words when the account has been taken for erasure meanwhile', async () => {
      const h = await mountPage(response('pending', [liveRow('P1', 'pending')]))
      vi.mocked(adminComplianceApi.holdErasure).mockRejectedValue(
        new ApiError({ status: 409, code: 'admin.erasure.hold.claimed', message: 'claimed' }),
      )

      await h.wrapper.find('[data-testid="admin-erasure-hold-P1"]').trigger('click')
      await flushPromises()
      type('Open legal matter, reference on file')
      await flushPromises()
      confirmButton().click()
      await flushPromises()

      expect(dialogText('admin-erasure-hold-dialog-error')).toBe(
        'This account is already being erased, so it cannot be held.',
      )
    })

    it('falls back to a plain sentence for an error code it has no words for', async () => {
      const h = await mountPage(response('pending', [liveRow('P1', 'pending')]))
      vi.mocked(adminComplianceApi.holdErasure).mockRejectedValue(
        new ApiError({ status: 500, code: 'server.broke', message: 'boom' }),
      )

      await h.wrapper.find('[data-testid="admin-erasure-hold-P1"]').trigger('click')
      await flushPromises()
      type('Open legal matter, reference on file')
      await flushPromises()
      confirmButton().click()
      await flushPromises()

      expect(dialogText('admin-erasure-hold-dialog-error')).toBe(
        'This could not be saved. Please try again.',
      )
    })
  })
})
