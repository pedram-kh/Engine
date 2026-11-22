/** PL-1.3 W2, W3, W4, W8 — the steps panel: grouping and filters, pill tones, role gates, strategy steps. */
import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import { makeStep, makeTracker, mountWithApp, type Role } from '../waveTestKit'
import WaveStepsPanel from './WaveStepsPanel.vue'

const ME = 'user-1'

function fixtureSteps() {
  return [
    makeStep(
      {
        name: 'USPs',
        key: 'ugc.usps',
        is_strategy: true,
        status: 'done',
        display_status: 'done',
        completed_on: '2026-10-01',
      },
      'usps',
    ),
    makeStep(
      {
        name: 'Personas',
        key: 'ugc.personas',
        is_strategy: true,
        status: 'in_progress',
        display_status: 'in_progress',
        owner: { id: ME, name: 'Ada Admin' },
      },
      'personas',
    ),
    makeStep({ name: 'Brief the creators', display_status: 'up_next' }, 'brief'),
    makeStep(
      {
        name: 'Statics kickoff',
        stream: 'statics',
        display_status: 'due_today',
        owner: { id: 'x', name: 'Sam Staff' },
      },
      'kick',
    ),
    makeStep(
      {
        name: 'Stills grade',
        stream: 'stills',
        display_status: 'delayed',
        days_overdue: 2,
        owner_client: true,
      },
      'grade',
    ),
  ]
}

async function mountPanel(role: Role = 'agency_admin', steps = fixtureSteps()) {
  const w = await mountWithApp(
    WaveStepsPanel,
    {
      tracker: makeTracker(steps),
      canManage: role !== 'agency_staff',
      meUlid: ME,
      busy: new Set<string>(),
      rowErrors: {},
    },
    { role },
  )
  await flushPromises()
  return w
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('WaveStepsPanel (PL-1.3)', () => {
  it('W2: one group per stream, with the completed steps folded into one line', async () => {
    const w = await mountPanel()
    expect(w.findAll('[data-test^="steps-group-"]').map((g) => g.attributes('data-test'))).toEqual([
      'steps-group-ugc_video',
      'steps-group-statics',
      'steps-group-stills',
    ])
    expect(w.find('[data-test="step-row-usps"]').exists()).toBe(false)
    const line = w.get('[data-test="steps-completed"]').text()
    expect(line).toContain('1 completed step')
    expect(line).toContain('USPs')
    expect(line).not.toContain('→')
    expect(line).toContain('(Oct 1)')
    await w.get('[data-test="steps-completed-toggle-ugc_video"]').trigger('click')
    expect(w.find('[data-test="step-row-usps"]').exists()).toBe(true)
    w.unmount()
  })

  it('W2: the filters narrow to a stream, and Mine to the signed-in member', async () => {
    const w = await mountPanel()
    await w.get('[data-test="steps-filter-statics"]').trigger('click')
    expect(w.findAll('[data-test^="steps-group-"]')).toHaveLength(1)
    await w.get('[data-test="steps-filter-mine"]').trigger('click')
    expect(w.findAll('[data-test^="step-row-"]').map((r) => r.attributes('data-test'))).toEqual([
      'step-row-personas',
    ])
    await w.get('[data-test="steps-filter-all"]').trigger('click')
    expect(w.findAll('[data-test^="steps-group-"]')).toHaveLength(3)
    w.unmount()
  })

  it('W2: Mine shows an empty line when the member owns nothing', async () => {
    const w = await mountPanel('agency_admin', [makeStep({})])
    await w.get('[data-test="steps-filter-mine"]').trigger('click')
    expect(w.find('[data-test="steps-empty"]').exists()).toBe(true)
    w.unmount()
  })

  it('W3: each display status has its own word and tone', async () => {
    const w = await mountPanel()
    const tone = (id: string) => w.get(`[data-test="step-status-${id}"]`).attributes('data-tone')
    expect(tone('personas')).toBe('info')
    expect(tone('brief')).toBe('neutral')
    expect(tone('kick')).toBe('warning')
    expect(tone('grade')).toBe('danger')
    expect(w.get('[data-test="step-status-kick"]').text()).toBe('Due today')
    expect(w.get('[data-test="step-status-grade"]').text()).toBe('Delayed')
    await w.get('[data-test="steps-completed-toggle-ugc_video"]').trigger('click')
    expect(tone('usps')).toBe('success')
    w.unmount()
  })

  it('W3: a delayed step says how long, a client step says Client', async () => {
    const w = await mountPanel()
    const row = w.get('[data-test="step-row-grade"]').text()
    // D1: the pill says Delayed; the text beside it is the duration only.
    expect(row).toContain('2 days')
    expect(row.match(/Delayed/g)).toHaveLength(1)
    expect(row).not.toContain('Delayed ·')
    expect(w.get('[data-test="step-owner-grade"]').text()).toBe('Client')
    w.unmount()
  })

  it('W4: admin and manager see Add step, Add stream, Remove stream; staff see none of them', async () => {
    const stepsNoStatics = fixtureSteps().filter((s) => s.attributes.stream !== 'statics')
    for (const role of ['agency_admin', 'agency_manager'] as const) {
      const w = await mountPanel(role, stepsNoStatics)
      expect(w.find('[data-test="step-add"]').exists(), role).toBe(true)
      expect(w.find('[data-test="stream-add"]').exists(), role).toBe(true)
      expect(w.find('[data-test="stream-menu-stills"]').exists(), role).toBe(true)
      w.unmount()
    }
    const staff = await mountPanel('agency_staff', stepsNoStatics)
    expect(staff.find('[data-test="step-add"]').exists()).toBe(false)
    expect(staff.find('[data-test="stream-add"]').exists()).toBe(false)
    expect(staff.find('[data-test="stream-menu-stills"]').exists()).toBe(false)
    // Staff still get the check and the row menu with Edit.
    expect(staff.find('[data-test="step-check-brief"]').exists()).toBe(true)
    await staff.get('[data-test="step-menu-brief"]').trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[data-test="step-edit-brief"]')).not.toBeNull()
    staff.unmount()
  })

  it('W4: Remove is in the row menu for admin and not for staff', async () => {
    const admin = await mountPanel('agency_admin')
    await admin.get('[data-test="step-menu-brief"]').trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[data-test="step-remove-brief"]')).not.toBeNull()
    admin.unmount()
    document.body.innerHTML = ''

    const staff = await mountPanel('agency_staff')
    await staff.get('[data-test="step-menu-brief"]').trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[data-test="step-remove-brief"]')).toBeNull()
    staff.unmount()
  })

  it('W8: a strategy step has no Remove in its menu, even for admin', async () => {
    const w = await mountPanel('agency_admin')
    await w.get('[data-test="step-menu-personas"]').trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[data-test="step-edit-personas"]')).not.toBeNull()
    expect(document.body.querySelector('[data-test="step-remove-personas"]')).toBeNull()
    w.unmount()
  })

  it('W8: the UGC video group has no Remove stream', async () => {
    const w = await mountPanel('agency_admin')
    expect(w.find('[data-test="stream-menu-ugc_video"]').exists()).toBe(false)
    expect(w.find('[data-test="stream-menu-statics"]').exists()).toBe(true)
    w.unmount()
  })

  it('PL-2.5: a row set by hand beyond the real step shows the marker and what the step says; a row the system holds shows none', async () => {
    const hand = makeStep(
      {
        name: 'Personas',
        key: 'ugc.personas',
        is_strategy: true,
        status: 'done',
        status_source: 'manual',
        display_status: 'done',
        strategy: {
          state: 'in_progress',
          markets_done: 1,
          markets_total: 2,
          hand_set_beyond: true,
        },
      },
      'hand',
    )
    const sys = makeStep(
      {
        name: 'Matrix',
        key: 'ugc.matrix',
        is_strategy: true,
        status: 'in_progress',
        display_status: 'in_progress',
        strategy: {
          state: 'in_progress',
          markets_done: 1,
          markets_total: 2,
          hand_set_beyond: false,
        },
      },
      'sys',
    )
    const w = await mountWithApp(WaveStepsPanel, {
      tracker: makeTracker([hand, sys]),
      canManage: true,
      meUlid: ME,
      busy: new Set<string>(),
      rowErrors: {},
    })
    await flushPromises()
    expect(w.get('[data-test="step-hand-hand"]').text()).toBe(
      'Set by hand · the step says In progress (1 of 2 markets)',
    )
    expect(w.find('[data-test="step-hand-sys"]').exists()).toBe(false)
    w.unmount()
  })
})
