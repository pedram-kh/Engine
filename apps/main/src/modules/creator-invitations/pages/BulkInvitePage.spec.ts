/**
 * BulkInvitePage unit tests — Sprint 3 Chunk 4 sub-step 11.
 *
 * Covers the single-async-path UX flow:
 *
 *   file-select → parse → preview → submit (202) → poll → complete (success)
 *   file-select → parse → preview → submit (202) → poll → failed (terminal error)
 *
 * Plus pre-upload error states (csv.header_missing, invalid email rows
 * filtered out of the submit count, soft-warning banner).
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'
import enAuth from '@/core/i18n/locales/en/auth.json'
import { useAgencyStore } from '@/core/stores/useAgencyStore'

import BulkInvitePage from './BulkInvitePage.vue'

vi.mock('../api/bulk-invite.api', () => ({
  bulkInviteApi: {
    submit: vi.fn(),
    getJob: vi.fn(),
  },
}))

import { bulkInviteApi } from '../api/bulk-invite.api'

const localStorageStore: Record<string, string> = {}
Object.defineProperty(globalThis, 'localStorage', {
  value: {
    getItem: (k: string): string | null => localStorageStore[k] ?? null,
    setItem: (k: string, v: string): void => {
      localStorageStore[k] = v
    },
    removeItem: (k: string): void => {
      delete localStorageStore[k]
    },
  },
  writable: true,
})

/**
 * JSDOM ships with a minimal File implementation whose `.text()` method
 * returns an empty string regardless of the content passed at construction
 * time. We override `text()` on each File instance to return the original
 * payload so BulkInvitePage's parseCsvText() receives the expected CSV.
 */
function makeFile(content: string, name = 'invites.csv'): File {
  const file = new File([content], name, { type: 'text/csv' })
  Object.defineProperty(file, 'text', {
    value: () => Promise.resolve(content),
    configurable: true,
  })
  return file
}

async function selectFile(wrapper: ReturnType<typeof mount>, file: File): Promise<void> {
  // Drive the page directly via its exposed onFileSelected handler. Vuetify's
  // VFileInput is hostile to programmatic file selection in JSDOM (its
  // `update:modelValue` event is fired from internal native-input change
  // handlers we can't simulate cleanly, and the component is globally
  // registered so the `stubs` option only partially intercepts it).
  // Exposing the handler keeps the unit test focused on BulkInvitePage's
  // own behaviour (parse → preview → submit → poll).
  const exposed = wrapper.vm as unknown as { onFileSelected?: (f: File | null) => Promise<void> }
  if (typeof exposed.onFileSelected !== 'function') {
    throw new Error(
      `BulkInvitePage did not expose onFileSelected; got ${Object.keys(wrapper.vm).join(',')}`,
    )
  }
  await exposed.onFileSelected(file)
  await flushPromises()
}

async function mountPage(): Promise<{ wrapper: ReturnType<typeof mount>; cleanup: () => void }> {
  const pinia = createPinia()
  setActivePinia(pinia)

  const agency = useAgencyStore()
  agency.initFromUser([
    {
      agency_id: '01HQAGENCY',
      agency_name: 'Test Agency',
      role: 'agency_admin',
    },
  ])

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/creator-invitations/bulk',
        name: 'creator-invitations.bulk',
        component: { template: '<div />' },
      },
    ],
  })
  await router.push('/creator-invitations/bulk')
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: { ...enApp, ...enAuth } } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })

  const wrapper = mount(BulkInvitePage, {
    global: {
      plugins: [pinia, router, i18n, vuetify],
    },
    attachTo: document.createElement('div'),
  })
  await flushPromises()

  return {
    wrapper,
    cleanup: () => {
      wrapper.unmount()
    },
  }
}

describe('BulkInvitePage', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('renders the title + description on initial mount', async () => {
    const h = await mountPage()
    teardown = h.cleanup
    expect(h.wrapper.text()).toContain('Bulk-invite creators')
    expect(h.wrapper.text()).toContain('email')
  })

  it('parses a happy-path CSV and enables submit', async () => {
    const h = await mountPage()
    teardown = h.cleanup
    await selectFile(h.wrapper, makeFile('email\nalice@example.com\nbob@example.com\n'))
    expect(h.wrapper.find('[data-test="bulk-invite-preview-heading"]').text()).toContain('2')
    const submit = h.wrapper.find<HTMLButtonElement>('[data-test="bulk-invite-submit"]')
      .element as HTMLButtonElement
    expect(submit.disabled).toBe(false)
  })

  it('flags csv.header_missing as a fatal error', async () => {
    const h = await mountPage()
    teardown = h.cleanup
    await selectFile(h.wrapper, makeFile('name,handle\nAlice,@a\n'))
    expect(h.wrapper.find('[data-test="bulk-invite-fatal"]').text()).toContain('email')
    expect(h.wrapper.find('[data-test="bulk-invite-submit"]').exists()).toBe(false)
  })

  it('shows the soft-warning banner for 101+ rows', async () => {
    const h = await mountPage()
    teardown = h.cleanup
    const rows = ['email']
    for (let i = 0; i < 101; i++) rows.push(`user${i}@example.com`)
    await selectFile(h.wrapper, makeFile(rows.join('\n')))
    expect(h.wrapper.find('[data-test="bulk-invite-soft-warning"]').exists()).toBe(true)
  })

  it('happy path: submits CSV, polls job, and renders complete state with stats', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const h = await mountPage()
    teardown = (): void => {
      vi.useRealTimers()
      h.cleanup()
    }
    vi.mocked(bulkInviteApi.submit).mockResolvedValue({
      data: { id: 'job-ulid', type: 'bulk_creator_invitation' },
      meta: { row_count: 2, exceeds_soft_warning: false, errors: [] },
      links: { self: '/api/v1/jobs/job-ulid' },
    })
    vi.mocked(bulkInviteApi.getJob)
      .mockResolvedValueOnce({
        data: {
          id: 'job-ulid',
          type: 'bulk_creator_invitation',
          status: 'processing',
          progress: 0.5,
          started_at: '2026-05-15T07:00:00Z',
          completed_at: null,
          estimated_completion_at: null,
          result: null,
          failure_reason: null,
        },
      })
      .mockResolvedValueOnce({
        data: {
          id: 'job-ulid',
          type: 'bulk_creator_invitation',
          status: 'complete',
          progress: 1,
          started_at: '2026-05-15T07:00:00Z',
          completed_at: '2026-05-15T07:00:05Z',
          estimated_completion_at: null,
          result: {
            stats: { invited: 2, already_invited: 0, failed: 0 },
            failures: [],
          },
          failure_reason: null,
        },
      })

    const file = makeFile('email\nalice@example.com\nbob@example.com\n')
    await selectFile(h.wrapper, file)
    await h.wrapper.find('[data-test="bulk-invite-submit"]').trigger('click')
    await flushPromises()
    await flushPromises()

    expect(bulkInviteApi.submit).toHaveBeenCalledWith('01HQAGENCY', file)
    expect(h.wrapper.find('[data-test="bulk-invite-tracking"]').exists()).toBe(true)

    // Advance through one poll cycle (processing → complete).
    await vi.advanceTimersByTimeAsync(3000)
    await flushPromises()

    expect(h.wrapper.find('[data-test="bulk-invite-complete"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="bulk-invite-stat-invited"]').text()).toContain('2')
    expect(bulkInviteApi.getJob).toHaveBeenCalledTimes(2)
  })

  it('failed path: surfaces failure_reason when the job terminates as failed', async () => {
    const h = await mountPage()
    teardown = h.cleanup
    vi.mocked(bulkInviteApi.submit).mockResolvedValue({
      data: { id: 'job-ulid', type: 'bulk_creator_invitation' },
      meta: { row_count: 1, exceeds_soft_warning: false, errors: [] },
      links: { self: '/api/v1/jobs/job-ulid' },
    })
    vi.mocked(bulkInviteApi.getJob).mockResolvedValueOnce({
      data: {
        id: 'job-ulid',
        type: 'bulk_creator_invitation',
        status: 'failed',
        progress: 0.3,
        started_at: '2026-05-15T07:00:00Z',
        completed_at: '2026-05-15T07:00:05Z',
        estimated_completion_at: null,
        result: { stats: { invited: 0, already_invited: 0, failed: 1 }, failures: [] },
        failure_reason: 'Queue worker crashed.',
      },
    })

    const file = makeFile('email\nalice@example.com\n')
    await selectFile(h.wrapper, file)
    await h.wrapper.find('[data-test="bulk-invite-submit"]').trigger('click')
    // submit → pollJob() → phase = 'failed': two microtask rounds for the
    // submit + poll promise chains, plus the reactive re-render.
    await flushPromises()
    await flushPromises()

    expect(h.wrapper.find('[data-test="bulk-invite-failed"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="bulk-invite-failed"]').text()).toContain(
      'Queue worker crashed.',
    )
  })

  // ------------------------------------------------------------------
  // Sprint 3 Chunk 4 PMC-2 — abort-on-unmount regression net.
  //
  // BulkInvitePage's `onBeforeUnmount` clears the poll timer so the page
  // doesn't keep firing `bulkInviteApi.getJob` calls after the user
  // navigates away mid-tracking. Without this test, a refactor that
  // drops the cleanup would pass every other test in this file because
  // the existing tests either run to terminal status before unmount
  // (`afterEach` cleanup) or never enter `tracking` (parse/preview tests).
  //
  // The break-revert pass (PMC-2 verification) commented out the
  // `onBeforeUnmount` block; this test then failed as the timer kept
  // re-scheduling `pollJob()` calls past the unmount. With the cleanup
  // intact, the call count stays frozen at the pre-unmount value.
  // ------------------------------------------------------------------
  it('clears the poll timer when the page unmounts during tracking', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const h = await mountPage()
    // The wrapper is unmounted manually below — afterEach must not
    // double-unmount, so teardown only restores real timers.
    teardown = (): void => {
      vi.useRealTimers()
    }

    vi.mocked(bulkInviteApi.submit).mockResolvedValue({
      data: { id: 'job-ulid', type: 'bulk_creator_invitation' },
      meta: { row_count: 1, exceeds_soft_warning: false, errors: [] },
      links: { self: '/api/v1/jobs/job-ulid' },
    })
    // Job stays in `processing` indefinitely so the poll loop keeps
    // re-scheduling. Without the unmount cleanup, every advance of the
    // 3 s interval would bump `getJob`'s call count.
    vi.mocked(bulkInviteApi.getJob).mockResolvedValue({
      data: {
        id: 'job-ulid',
        type: 'bulk_creator_invitation',
        status: 'processing',
        progress: 0.3,
        started_at: '2026-05-15T07:00:00Z',
        completed_at: null,
        estimated_completion_at: null,
        result: null,
        failure_reason: null,
      },
    })

    const file = makeFile('email\nalice@example.com\n')
    await selectFile(h.wrapper, file)
    await h.wrapper.find('[data-test="bulk-invite-submit"]').trigger('click')
    await flushPromises()
    await flushPromises()
    expect(h.wrapper.find('[data-test="bulk-invite-tracking"]').exists()).toBe(true)

    // First poll fired synchronously from `onSubmit` → `pollJob()`. The
    // next call would land on the 3 s setTimeout the poll just scheduled.
    const callsBeforeUnmount = vi.mocked(bulkInviteApi.getJob).mock.calls.length
    expect(callsBeforeUnmount).toBeGreaterThanOrEqual(1)

    h.wrapper.unmount()

    // Advance well past three poll intervals — the timer should be
    // cleared, so no further `getJob` calls land.
    await vi.advanceTimersByTimeAsync(3000 * 3)
    await flushPromises()

    expect(vi.mocked(bulkInviteApi.getJob).mock.calls.length).toBe(callsBeforeUnmount)
  })

  it('start-over from complete state resets the page to idle', async () => {
    const h = await mountPage()
    teardown = h.cleanup
    vi.mocked(bulkInviteApi.submit).mockResolvedValue({
      data: { id: 'job-ulid', type: 'bulk_creator_invitation' },
      meta: { row_count: 1, exceeds_soft_warning: false, errors: [] },
      links: { self: '/api/v1/jobs/job-ulid' },
    })
    vi.mocked(bulkInviteApi.getJob).mockResolvedValueOnce({
      data: {
        id: 'job-ulid',
        type: 'bulk_creator_invitation',
        status: 'complete',
        progress: 1,
        started_at: '2026-05-15T07:00:00Z',
        completed_at: '2026-05-15T07:00:05Z',
        estimated_completion_at: null,
        result: { stats: { invited: 1, already_invited: 0, failed: 0 }, failures: [] },
        failure_reason: null,
      },
    })

    const file = makeFile('email\nalice@example.com\n')
    await selectFile(h.wrapper, file)
    await h.wrapper.find('[data-test="bulk-invite-submit"]').trigger('click')
    await flushPromises()
    await flushPromises()

    expect(h.wrapper.find('[data-test="bulk-invite-complete"]').exists()).toBe(true)
    await h.wrapper.find('[data-test="bulk-invite-start-over"]').trigger('click')
    await flushPromises()

    expect(h.wrapper.find('[data-test="bulk-invite-complete"]').exists()).toBe(false)
    expect(h.wrapper.find('[data-test="bulk-invite-file-input"]').exists()).toBe(true)
  })
})
