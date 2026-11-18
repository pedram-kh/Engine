/** PL-1.3 W5, W8 — the tracker section: optimistic check, removed-by-someone, owner picker, stream confirm. */
import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/waves.api', () => ({
  wavesApi: {
    tracker: vi.fn(),
    addStep: vi.fn(),
    updateStep: vi.fn(),
    removeStep: vi.fn(),
    removeStream: vi.fn(),
    addStream: vi.fn(),
  },
}))
vi.mock('@/modules/agency-users/api/members.api', () => ({
  membersApi: {
    list: vi.fn(() =>
      Promise.resolve({
        data: [
          {
            id: 'm1',
            type: 'agency_memberships',
            attributes: { user_id: 'U-DESIGNER', name: 'Dee Designer', status: 'active' },
          },
          {
            id: 'm2',
            type: 'agency_memberships',
            attributes: { user_id: 'U-PENDING', name: 'Pat Pending', status: 'pending' },
          },
        ],
      }),
    ),
  },
}))

import { wavesApi } from '../api/waves.api'
import {
  AGENCY_ULID,
  BRAND_ULID,
  makeStep,
  makeTracker,
  mountWithApp,
  WAVE_ULID,
  type Role,
} from '../waveTestKit'
import WaveTrackerSection from './WaveTrackerSection.vue'

const api = vi.mocked(wavesApi)

function steps() {
  return [
    makeStep({ name: 'Personas', key: 'ugc.personas', is_strategy: true }, 'personas'),
    makeStep(
      { name: 'Brief', display_status: 'delayed', days_overdue: 2, due_on: '2026-11-16' },
      'brief',
    ),
    makeStep({ name: 'Statics kickoff', stream: 'statics' }, 'kick'),
  ]
}

async function mountSection(role: Role = 'agency_admin') {
  const w = await mountWithApp(
    WaveTrackerSection,
    {
      agencyId: AGENCY_ULID,
      brandId: BRAND_ULID,
      waveId: WAVE_ULID,
      canManage: role !== 'agency_staff',
      meUlid: 'user-1',
    },
    { role },
  )
  await flushPromises()
  return w
}

const body = (sel: string) => document.body.querySelector<HTMLElement>(sel)

beforeEach(() => {
  vi.clearAllMocks()
  api.tracker.mockResolvedValue({ data: makeTracker(steps()) })
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('WaveTrackerSection (PL-1.3)', () => {
  it('loads the tracker once and draws a card per present stream', async () => {
    const w = await mountSection()
    expect(api.tracker).toHaveBeenCalledWith(AGENCY_ULID, BRAND_ULID, WAVE_ULID)
    expect(w.findAll('[data-test^="stream-card-"]')).toHaveLength(2)
    expect(w.emitted('tracker')).toHaveLength(1)
    w.unmount()
  })

  it('shows an error with Retry when the load fails', async () => {
    api.tracker.mockRejectedValueOnce(new Error('boom'))
    const w = await mountSection()
    expect(w.find('[data-test="tracker-error"]').exists()).toBe(true)
    await w.get('[data-test="tracker-retry"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-test="stream-cards"]').exists()).toBe(true)
    w.unmount()
  })

  it('W5: the check marks a step done at once and keeps it when the save works', async () => {
    let release: (v: never) => void = () => {}
    api.updateStep.mockReturnValue(new Promise((r) => (release = r as never)))
    const w = await mountSection()
    await w.get('[data-test="step-check-brief"]').trigger('click')
    expect(api.updateStep).toHaveBeenCalledWith(AGENCY_ULID, BRAND_ULID, WAVE_ULID, 'brief', {
      status: 'done',
    })
    // Still saving, and already shown as done (optimistic): the row sits in the completed line.
    expect(w.find('[data-test="step-row-brief"]').exists()).toBe(false)
    release({ data: makeStep() } as never)
    await flushPromises()
    expect(api.tracker).toHaveBeenCalledTimes(2)
    w.unmount()
  })

  it('W5: a failed save puts the step back and says so on the row', async () => {
    api.updateStep.mockRejectedValue(
      new ApiError({ status: 500, code: 'server_error', message: 'x' } as never),
    )
    const w = await mountSection()
    await w.get('[data-test="step-check-brief"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-test="step-row-brief"]').exists()).toBe(true)
    expect(w.get('[data-test="step-row-brief"]').attributes('data-display-status')).toBe('delayed')
    expect(w.get('[data-test="step-error-brief"]').text()).toContain('could not be saved')
    w.unmount()
  })

  it('W5: a 404 on the check shows "This step was removed" and reloads', async () => {
    api.updateStep.mockRejectedValue(
      new ApiError({ status: 404, code: 'not_found', message: 'x' } as never),
    )
    const w = await mountSection()
    await w.get('[data-test="step-check-brief"]').trigger('click')
    await flushPromises()
    expect(body('[data-test="step-removed-snackbar"]')?.textContent).toContain(
      'removed by someone else',
    )
    expect(api.tracker).toHaveBeenCalledTimes(2)
    w.unmount()
  })

  it('W8: the owner picker offers Unassigned, Client and the active members only', async () => {
    const w = await mountSection()
    await w.get('[data-test="step-owner-brief"]').trigger('click')
    await flushPromises()
    const select = body('[data-test="step-owner-select"]')
    expect(select).not.toBeNull()
    select?.querySelector('input')?.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
    select?.querySelector<HTMLElement>('.v-field')?.click()
    await flushPromises()
    const items = [...document.body.querySelectorAll('.v-list-item-title')].map(
      (n) => n.textContent,
    )
    expect(items).toEqual(expect.arrayContaining(['Unassigned', 'Client', 'Dee Designer']))
    expect(items).not.toContain('Pat Pending')
    w.unmount()
  })

  it('W8: Remove stream asks first, naming the stream and its step count, and removes only on confirm', async () => {
    api.removeStream.mockResolvedValue({
      data: makeTracker(steps().filter((s) => s.attributes.stream !== 'statics')),
    })
    const w = await mountSection()
    await w.get('[data-test="stream-menu-statics"]').trigger('click')
    await flushPromises()
    body('[data-test="stream-remove-statics"]')?.click()
    await flushPromises()
    expect(body('[data-test="confirm-body"]')?.textContent).toContain('Statics and its 1 step')
    expect(api.removeStream).not.toHaveBeenCalled()
    body('[data-test="confirm-ok"]')?.click()
    await flushPromises()
    expect(api.removeStream).toHaveBeenCalledWith(AGENCY_ULID, BRAND_ULID, WAVE_ULID, 'statics')
    expect(w.find('[data-test="stream-card-statics"]').exists()).toBe(false)
    w.unmount()
  })

  it('D2: Add stream says so when every default step is already on the wave', async () => {
    api.tracker.mockResolvedValue({
      data: makeTracker(steps().filter((s) => s.attributes.stream !== 'statics')),
    })
    api.addStream.mockRejectedValue(
      new ApiError({ status: 422, code: 'stream.defaults_exist', message: 'x' } as never),
    )
    const w = await mountSection()
    await w.get('[data-test="stream-add"]').trigger('click')
    await flushPromises()
    body('[data-test="stream-add-statics"]')?.click()
    await flushPromises()
    expect(w.get('[data-test="stream-error"]').text()).toContain('already on the wave')
    w.unmount()
  })

  it('Remove step asks first and then removes', async () => {
    api.removeStep.mockResolvedValue(undefined as never)
    const w = await mountSection()
    await w.get('[data-test="step-menu-brief"]').trigger('click')
    await flushPromises()
    body('[data-test="step-remove-brief"]')?.click()
    await flushPromises()
    expect(body('[data-test="confirm-body"]')?.textContent).toContain('Brief')
    body('[data-test="confirm-ok"]')?.click()
    await flushPromises()
    expect(api.removeStep).toHaveBeenCalledWith(AGENCY_ULID, BRAND_ULID, WAVE_ULID, 'brief')
    w.unmount()
  })

  it('a staff request never carries name or stream', async () => {
    api.updateStep.mockResolvedValue({ data: makeStep() })
    const w = await mountSection('agency_staff')
    await w.get('[data-test="step-menu-brief"]').trigger('click')
    await flushPromises()
    body('[data-test="step-edit-brief"]')?.click()
    await flushPromises()
    expect(body('[data-test="step-name-input"]')).toBeNull()
    expect(body('[data-test="step-stream-select"]')).toBeNull()
    const due = body('[data-test="step-due-on"] input') as HTMLInputElement
    due.value = '2026-11-25'
    due.dispatchEvent(new Event('input', { bubbles: true }))
    await flushPromises()
    body('[data-test="step-save"]')?.click()
    await flushPromises()
    expect(api.updateStep).toHaveBeenCalledWith(AGENCY_ULID, BRAND_ULID, WAVE_ULID, 'brief', {
      due_on: '2026-11-25',
    })
    w.unmount()
  })
})
