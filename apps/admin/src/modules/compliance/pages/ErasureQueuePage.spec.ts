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
      erasurePlan: vi.fn(),
      erasureRow: vi.fn(),
      erase: vi.fn(),
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
  type ErasePayload,
  type ErasurePlan,
  type ErasureQueueRow,
  type ErasureRun,
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
      run: null,
      blockers: [],
      ...attributes,
    },
  }
}

function run(state: ErasureRun['state'], stuck = false): ErasureRun {
  return { state, stuck, can_retry: state === 'incomplete' || stuck }
}

function plan(overrides: Partial<ErasurePlan['attributes']> = {}): ErasurePlan {
  return {
    id: 'R1',
    type: 'erasure_plans',
    attributes: {
      user_ulid: 'R1',
      claimed: false,
      run: null,
      gate: [],
      blockers: [],
      ready: true,
      database: [
        { stage: 'a', id: 'users', table: 'users', disposition: 'anonymise', count: 1, read: 1 },
        {
          stage: 'a',
          id: 'creators',
          table: 'creators',
          disposition: 'anonymise',
          count: 1,
          read: 1,
        },
        { stage: 'a', id: 'msgs', table: 'messages', disposition: 'delete', count: 40, read: 40 },
        { stage: 'a', id: 'noop', table: 'posts', disposition: 'delete', count: 0, read: 3 },
        { stage: 'b', id: 'msgs2', table: 'messages', disposition: 'anonymise', count: 2, read: 2 },
      ],
      files: { avatars: 2, documents: 3, skipped_outside_own_prefix: 7 },
      cost: { rows: 300, batches: 4, largest: 'messages', largest_rows: 200 },
      assignments_to_cancel: 2,
      vendor_calls: 0,
      audit_scrub_available: true,
      digest: 'abcdef0123456789'.repeat(4),
      ...overrides,
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
        liveRow('I1', 'in_progress', { run: run('waiting') }),
        liveRow('I2', 'in_progress', { run: run('waiting', true) }),
        liveRow('I3', 'in_progress', { run: run('running') }),
        liveRow('I4', 'in_progress', { run: run('running', true) }),
        liveRow('I5', 'in_progress', { run: run('incomplete') }),
        liveRow('I6', 'in_progress', { run: run('db_done') }),
      ]),
    )

    expect(stateText(h, 'P1')).toMatch(/^Waiting\. The grace period ends on /)
    expect(stateText(h, 'R1')).toBe('The grace period has ended and nothing stands in the way.')
    expect(stateText(h, 'B1')).toBe(
      'The grace period has ended, but it cannot be erased: a payment is still in progress; a payout provider account is still connected.',
    )
    expect(stateText(h, 'H1')).toMatch(/^On hold since .+\. Held by Ada Admin\.$/)
    expect(stateText(h, 'H2')).toMatch(/^On hold since .+\.$/)
    expect(stateText(h, 'I1')).toBe('Waiting for the erasure worker to pick it up.')
    expect(stateText(h, 'I3')).toBe('Erasing is under way.')
    // The stuck states say what to do.
    expect(stateText(h, 'I2')).toBe(
      'Waiting for over an hour: the erasure worker may be down. Check it (see the erasure runbook), then review the plan and confirm again.',
    )
    expect(stateText(h, 'I4')).toBe(
      'Erasing has been running for over an hour and may have stopped. Review the plan and confirm again.',
    )
    expect(stateText(h, 'I5')).toBe(
      'The last run stopped before it finished. Review the plan and confirm again.',
    )
    expect(stateText(h, 'I6')).toBe(
      'The database step is done, but the last step did not finish. This needs an engineer: follow the erasure runbook.',
    )
  })

  it('never says "claim" to the admin', async () => {
    const h = await mountPage(
      response('in_progress', [liveRow('I1', 'in_progress', { run: run('incomplete') })]),
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

  describe('the account id (AH-104c C3 walk: an admin must be able to read the id they are asked to type)', () => {
    let writeText: ReturnType<typeof vi.fn>

    beforeEach(() => {
      writeText = vi.fn().mockResolvedValue(undefined)
      Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    })

    afterEach(() => {
      Reflect.deleteProperty(navigator, 'clipboard')
      vi.useRealTimers()
    })

    it('shows every account row with its id and a copy button, whatever the state', async () => {
      const h = await mountPage(
        response('pending', [
          liveRow('P1', 'pending'),
          liveRow('R1', 'ready'),
          liveRow('B1', 'blocked', { blockers: ['vendor'] }),
          liveRow('H1', 'held'),
          liveRow('W1', 'in_progress', { run: run('waiting') }),
        ]),
      )

      for (const id of ['P1', 'R1', 'B1', 'H1', 'W1']) {
        expect(h.wrapper.find(`[data-testid="admin-erasure-id-${id}"]`).text()).toBe(id)
        expect(h.wrapper.find(`[data-testid="admin-erasure-id-${id}-copy"]`).exists()).toBe(true)
      }
    })

    it('copies the id of the row it sits on, and says so, then stops saying so', async () => {
      vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
      const h = await mountPage(
        response('pending', [liveRow('P1', 'pending'), liveRow('R1', 'ready')]),
      )

      await h.wrapper.find('[data-testid="admin-erasure-id-R1-copy"]').trigger('click')
      await flushPromises()

      expect(writeText).toHaveBeenCalledTimes(1)
      expect(writeText).toHaveBeenCalledWith('R1')
      expect(h.wrapper.find('[data-testid="admin-erasure-id-R1-copied"]').text()).toBe('Copied')
      expect(h.wrapper.find('[data-testid="admin-erasure-id-P1-copied"]').exists()).toBe(false)

      vi.advanceTimersByTime(2100)
      await flushPromises()
      expect(h.wrapper.find('[data-testid="admin-erasure-id-R1-copied"]').exists()).toBe(false)
    })

    it('does not say Copied when the browser refuses the copy', async () => {
      writeText.mockRejectedValue(new Error('denied'))
      const h = await mountPage(response('pending', [liveRow('R1', 'ready')]))

      await h.wrapper.find('[data-testid="admin-erasure-id-R1-copy"]').trigger('click')
      await flushPromises()

      expect(writeText).toHaveBeenCalledWith('R1')
      expect(h.wrapper.find('[data-testid="admin-erasure-id-R1-copied"]').exists()).toBe(false)
    })

    it('says nothing is copied, and does not fail, when the browser has no clipboard', async () => {
      Reflect.deleteProperty(navigator, 'clipboard')
      const h = await mountPage(response('pending', [liveRow('R1', 'ready')]))

      await h.wrapper.find('[data-testid="admin-erasure-id-R1-copy"]').trigger('click')
      await flushPromises()

      expect(h.wrapper.find('[data-testid="admin-erasure-id-R1-copied"]').exists()).toBe(false)
    })
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

  describe('the Erase click (AH-104c C3)', () => {
    const ULID = 'R1'
    const REASON = 'Subject asked; ticket on file'

    function body(testid: string): HTMLElement {
      return document.body.querySelector<HTMLElement>(`[data-testid="${testid}"]`)!
    }

    function dialogText(testid: string): string {
      return document.body.querySelector(`[data-testid="${testid}"]`)?.textContent?.trim() ?? ''
    }

    function typeInto(testid: string, selector: string, value: string): void {
      const input = body(testid).querySelector<HTMLInputElement | HTMLTextAreaElement>(selector)!
      input.value = value
      input.dispatchEvent(new Event('input'))
    }

    function confirm(): HTMLButtonElement {
      return document.body.querySelector<HTMLButtonElement>(
        '[data-testid="admin-erasure-erase-dialog-confirm"]',
      )!
    }

    async function fill(ulid = ULID, reason = REASON, code = '123456'): Promise<void> {
      typeInto('admin-erasure-erase-dialog-ulid', 'input', ulid)
      typeInto('admin-erasure-erase-dialog-reason', 'textarea', reason)
      typeInto('admin-erasure-erase-dialog-mfa', 'input', code)
      await flushPromises()
    }

    async function openFor(
      row = liveRow(ULID, 'ready'),
      thePlan: ErasurePlan = plan(),
    ): Promise<Awaited<ReturnType<typeof mountPage>>> {
      vi.mocked(adminComplianceApi.erasurePlan).mockResolvedValue({ data: thePlan })
      const h = await mountPage(response('ready', [row]))
      await h.wrapper.find(`[data-testid="admin-erasure-erase-${row.id}"]`).trigger('click')
      await flushPromises()
      return h
    }

    afterEach(() => {
      vi.useRealTimers()
    })

    it('offers Erase on a Ready row only, and the retry button only where the server allows it', async () => {
      const h = await mountPage(
        response('pending', [
          liveRow('P1', 'pending'),
          liveRow('R1', 'ready'),
          liveRow('B1', 'blocked', { blockers: ['vendor'] }),
          liveRow('H1', 'held'),
          liveRow('W1', 'in_progress', { run: run('waiting') }),
          liveRow('W2', 'in_progress', { run: run('waiting', true) }),
          liveRow('I1', 'in_progress', { run: run('incomplete') }),
          liveRow('D1', 'in_progress', { run: run('db_done') }),
        ]),
      )
      const has = (testid: string): boolean => h.wrapper.find(`[data-testid="${testid}"]`).exists()

      expect(has('admin-erasure-erase-R1')).toBe(true)
      for (const id of ['P1', 'B1', 'H1', 'W1', 'W2', 'I1', 'D1']) {
        expect(has(`admin-erasure-erase-${id}`)).toBe(false)
      }
      expect(['W1', 'W2', 'I1', 'D1', 'R1'].map((id) => has(`admin-erasure-retry-${id}`))).toEqual([
        false,
        true,
        true,
        false,
        false,
      ])
    })

    it('reads the plan when it opens and shows it as numbers, with no person in it', async () => {
      await openFor()

      expect(adminComplianceApi.erasurePlan).toHaveBeenCalledWith(ULID)
      expect(dialogText('admin-erasure-erase-dialog-title')).toBe('Erase this account')
      expect(dialogText('admin-erasure-erase-plan-rows')).toBe(
        'Database rows to change: 44, in 3 tables.',
      )
      // The files the plan leaves alone are not counted as deleted.
      expect(dialogText('admin-erasure-erase-plan-files')).toBe('Files to delete: 5.')
      expect(dialogText('admin-erasure-erase-plan-assignments')).toBe(
        'Open assignments to cancel: 2. Each one tells its agency.',
      )
      expect(dialogText('admin-erasure-erase-plan-read')).toBe('Rows read to work this out: 300.')
      expect(dialogText('admin-erasure-erase-plan-reference')).toBe('Plan reference: abcdef012345')
      expect(document.body.querySelector('[data-testid="admin-erasure-erase-dialog-retry"]')).toBe(
        null,
      )
    })

    it('shows the id the admin is asked to type, above the field, with a copy button', async () => {
      const writeText = vi.fn().mockResolvedValue(undefined)
      Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
      await openFor()

      expect(dialogText('admin-erasure-erase-dialog-id')).toBe(ULID)
      body('admin-erasure-erase-dialog-id-copy').click()
      await flushPromises()
      expect(writeText).toHaveBeenCalledWith(ULID)

      // Copying is not confirming: the typed id is still the check.
      expect(confirm().disabled).toBe(true)
      expect(
        body('admin-erasure-erase-dialog-ulid').querySelector<HTMLInputElement>('input')!.value,
      ).toBe('')
      Reflect.deleteProperty(navigator, 'clipboard')
    })

    it('keeps the button disabled until the id, the reason and the code are all filled in', async () => {
      await openFor()
      expect(confirm().disabled).toBe(true)

      await fill('WRONG', REASON, '123456')
      expect(confirm().disabled).toBe(true)

      await fill(ULID, 'too short', '123456')
      expect(confirm().disabled).toBe(true)

      await fill(ULID, REASON, '   ')
      expect(confirm().disabled).toBe(true)

      await fill(ULID, REASON, '123456')
      expect(confirm().disabled).toBe(false)
    })

    it('does not offer the click when the plan failed to load', async () => {
      vi.mocked(adminComplianceApi.erasurePlan).mockRejectedValue(
        new ApiError({ status: 429, code: 'admin.erasure.plan.rate_limited', message: 'slow' }),
      )
      const h = await mountPage(response('ready', [liveRow(ULID, 'ready')]))
      await h.wrapper.find(`[data-testid="admin-erasure-erase-${ULID}"]`).trigger('click')
      await flushPromises()
      await fill()

      expect(dialogText('admin-erasure-erase-dialog-plan-error')).toBe(
        'Too many plan requests. Wait a minute, then try again.',
      )
      expect(confirm().disabled).toBe(true)
    })

    it('shows why a plan that is not ready is not ready, and does not offer the click', async () => {
      await openFor(
        liveRow(ULID, 'ready'),
        plan({ ready: false, gate: ['held'], blockers: ['payment', 'audit_scrub_unavailable'] }),
      )
      await fill()

      expect(dialogText('admin-erasure-erase-dialog-not-ready')).toBe(
        'This account is not ready to be erased: it is on hold; a payment is still in progress; the audit-log step is not available here.',
      )
      expect(confirm().disabled).toBe(true)
    })

    it('says an earlier run stopped when the row is being retried', async () => {
      vi.mocked(adminComplianceApi.erasurePlan).mockResolvedValue({
        data: plan({ claimed: true, run: run('incomplete') }),
      })
      const h = await mountPage(
        response('in_progress', [liveRow(ULID, 'in_progress', { run: run('incomplete') })]),
      )
      await h.wrapper.find(`[data-testid="admin-erasure-retry-${ULID}"]`).trigger('click')
      await flushPromises()

      expect(dialogText('admin-erasure-erase-dialog-retry')).toBe(
        'An earlier erasure of this account stopped. Review the plan, then confirm again.',
      )
    })

    it('sends the typed id, the trimmed reason, the digest it showed and the code', async () => {
      vi.mocked(adminComplianceApi.erase).mockResolvedValue({
        data: liveRow(ULID, 'in_progress', { run: run('waiting') }),
        meta: { queued: true, retry: false, status_url: '/x' },
      })
      const h = await openFor()
      await fill('r1', `  ${REASON}  `, ' 123456 ')

      confirm().click()
      await flushPromises()

      expect(adminComplianceApi.erase).toHaveBeenCalledWith(ULID, {
        ulid: ULID,
        reason: REASON,
        digest: 'abcdef0123456789'.repeat(4),
        mfa_code: '123456',
      } satisfies ErasePayload)
      // The dialog closes, the page moves to the Erasing tab and tells where the account stands.
      expect(
        document.body
          .querySelector('[data-testid="admin-erasure-erase-dialog"]')
          ?.classList.contains('v-overlay--active'),
      ).not.toBe(true)
      expect(adminComplianceApi.listErasureQueue).toHaveBeenLastCalledWith(
        expect.objectContaining({ tab: 'in_progress' }),
      )
      expect(h.wrapper.find('[data-testid="admin-erasure-watch"]').text()).toBe(
        'Waiting for the erasure worker to pick it up.',
      )
    })

    it.each([
      ['admin.erasure.execute.disabled', /switched off/],
      ['admin.erasure.execute.ulid_mismatch', /does not match this account/],
      ['admin.erasure.execute.in_progress', /already in progress/],
      ['admin.erasure.execute.mfa_required', /Enter a two-factor code/],
      ['admin.erasure.execute.mfa_invalid', /not valid/],
      ['admin.erasure.execute.mfa_rate_limited', /Wait 15 minutes/],
      ['admin.erasure.execute.mfa_suspended', /suspended/],
      ['admin.erasure.execute.rate_limited', /Wait an hour/],
      ['server.broke', /could not be saved/],
    ])('says what %s means, and empties the code field', async (code, pattern) => {
      vi.mocked(adminComplianceApi.erase).mockRejectedValue(
        new ApiError({ status: 403, code, message: 'no' }),
      )
      await openFor()
      await fill()

      confirm().click()
      await flushPromises()

      expect(dialogText('admin-erasure-erase-dialog-error')).toMatch(pattern)
      expect(body('admin-erasure-erase-dialog-mfa').querySelector('input')!.value).toBe('')
      expect(confirm().disabled).toBe(true)
      // Nothing was sent off: the page did not start watching.
      expect(adminComplianceApi.erasureRow).not.toHaveBeenCalled()
    })

    it('reads the reasons out when the server refuses an account that is not ready', async () => {
      vi.mocked(adminComplianceApi.erase).mockRejectedValue(
        new ApiError({
          status: 422,
          code: 'admin.erasure.execute.not_ready',
          message: 'no',
          details: [
            {
              code: 'admin.erasure.execute.not_ready',
              meta: { codes: ['held', 'vendor'] },
            },
          ],
        }),
      )
      await openFor()
      await fill()

      confirm().click()
      await flushPromises()

      expect(dialogText('admin-erasure-erase-dialog-error')).toBe(
        'This account is not ready to be erased.',
      )
      expect(dialogText('admin-erasure-erase-dialog-refused')).toBe(
        'This account is not ready to be erased: it is on hold; a payout provider account is still connected.',
      )
    })

    describe('watching the account', () => {
      async function sendOff(): Promise<Awaited<ReturnType<typeof mountPage>>> {
        vi.mocked(adminComplianceApi.erase).mockResolvedValue({
          data: liveRow(ULID, 'in_progress', { run: run('waiting') }),
          meta: { queued: true, retry: false, status_url: '/x' },
        })
        const h = await openFor()
        vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
        await fill()
        confirm().click()
        await flushPromises()
        return h
      }

      async function tick(): Promise<void> {
        await vi.advanceTimersByTimeAsync(4000)
        await flushPromises()
      }

      it('follows it from waiting to running to erased, then stops asking', async () => {
        const h = await sendOff()

        vi.mocked(adminComplianceApi.erasureRow).mockResolvedValue({
          data: liveRow(ULID, 'in_progress', { run: run('running') }),
        })
        await tick()
        expect(h.wrapper.find('[data-testid="admin-erasure-watch"]').text()).toBe(
          'Erasing is under way.',
        )

        vi.mocked(adminComplianceApi.erasureRow).mockRejectedValue(
          new ApiError({ status: 404, code: 'http.not_found', message: 'gone' }),
        )
        await tick()
        expect(h.wrapper.find('[data-testid="admin-erasure-outcome-completed"]').text()).toBe(
          'The account was erased. Each cancelled assignment told its agency.',
        )
        expect(h.wrapper.find('[data-testid="admin-erasure-watch"]').exists()).toBe(false)

        const asked = vi.mocked(adminComplianceApi.erasureRow).mock.calls.length
        await tick()
        await tick()
        expect(vi.mocked(adminComplianceApi.erasureRow).mock.calls.length).toBe(asked)
      })

      it('says nothing was erased when the claim was released and the account is back in the queue', async () => {
        const h = await sendOff()

        vi.mocked(adminComplianceApi.erasureRow).mockResolvedValue({
          data: liveRow(ULID, 'ready'),
        })
        await tick()

        const note = h.wrapper.find('[data-testid="admin-erasure-outcome-released"]')
        expect(note.text()).toBe(
          'The erasure did not start: the data changed since the plan, or a check now refuses. Nothing was erased, and the creator can still cancel. Review the plan and try again.',
        )
        expect(h.wrapper.find('[data-testid="admin-erasure-watch"]').exists()).toBe(false)
      })

      it('keeps looking through a failed poll', async () => {
        const h = await sendOff()

        vi.mocked(adminComplianceApi.erasureRow).mockRejectedValue(
          new ApiError({ status: 500, code: 'server.broke', message: 'boom' }),
        )
        await tick()
        expect(h.wrapper.find('[data-testid="admin-erasure-watch"]').exists()).toBe(true)
        expect(h.wrapper.find('[data-testid="admin-erasure-outcome-completed"]').exists()).toBe(
          false,
        )
      })

      it('stops the timer when the page goes away', async () => {
        const h = await sendOff()
        h.unmount()
        teardown = null
        const asked = vi.mocked(adminComplianceApi.erasureRow).mock.calls.length

        await vi.advanceTimersByTimeAsync(20000)

        expect(vi.mocked(adminComplianceApi.erasureRow).mock.calls.length).toBe(asked)
      })
    })

    describe('the Erasing tab on its own', () => {
      it('reloads by itself while an account is waiting or running, and not once nothing moves', async () => {
        vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
        const h = await mountPage(
          response('in_progress', [liveRow('W1', 'in_progress', { run: run('waiting') })]),
        )
        await h.wrapper.find('[data-testid="admin-erasure-tab-in_progress"]').trigger('click')
        await flushPromises()
        const before = vi.mocked(adminComplianceApi.listErasureQueue).mock.calls.length

        await vi.advanceTimersByTimeAsync(4000)
        await flushPromises()
        expect(vi.mocked(adminComplianceApi.listErasureQueue).mock.calls.length).toBeGreaterThan(
          before,
        )

        // Once the run is incomplete nothing is moving, so the page stops asking.
        vi.mocked(adminComplianceApi.listErasureQueue).mockResolvedValue(
          response('in_progress', [liveRow('W1', 'in_progress', { run: run('incomplete') })]),
        )
        await vi.advanceTimersByTimeAsync(4000)
        await flushPromises()
        const settled = vi.mocked(adminComplianceApi.listErasureQueue).mock.calls.length
        await vi.advanceTimersByTimeAsync(12000)
        await flushPromises()
        expect(vi.mocked(adminComplianceApi.listErasureQueue).mock.calls.length).toBe(settled)
      })
    })
  })
})
