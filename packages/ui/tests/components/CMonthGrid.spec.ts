/**
 * Unit tests for the shared `CMonthGrid` layout primitive (`@catalyst/ui`,
 * Sprint 5 Chunk B, D-b2).
 *
 * Pins the pure calendar geometry the availability calendar relies on:
 *   - 42 cells (6×7) with leading/trailing-month padding marked `inMonth`.
 *   - today marker keys off the `today` prop.
 *   - prev/next + day-click emits.
 *   - the `#day` slot receives the correct per-cell scope.
 *
 * The grid is lightweight (a CSS grid, no Vuetify-heavy components), so it
 * renders REAL under the themed harness without the jsdom heap concerns
 * that gate the SPA's heavier component specs.
 */

import { describe, expect, it } from 'vitest'

import CMonthGrid from '../../src/components/CMonthGrid.vue'
import { mountThemed } from '../helpers/mountThemed'

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

function mountGrid(props: Record<string, unknown> = {}, slots: Record<string, unknown> = {}) {
  return mountThemed(CMonthGrid, {
    props: {
      year: 2026,
      month: 6,
      monthLabel: 'June 2026',
      weekdayLabels: WEEKDAYS,
      ...props,
    },
    slots,
  })
}

describe('CMonthGrid — geometry', () => {
  it('renders exactly 42 day cells (6 weeks × 7 days)', () => {
    const { wrapper } = mountGrid()
    expect(wrapper.findAll('.cmg__cell')).toHaveLength(42)
  })

  it('renders the seven weekday headers in order', () => {
    const { wrapper } = mountGrid()
    const headers = wrapper.findAll('.cmg__weekday').map((h) => h.text())
    expect(headers).toEqual(WEEKDAYS)
  })

  it('marks leading/trailing padding cells as out-of-month', () => {
    // June 2026 starts on a Monday (weekStartsOn=1 default) → no leading pad,
    // so the first cell is June 1 in-month; the last cells spill into July.
    const { wrapper } = mountGrid()
    const cells = wrapper.findAll('.cmg__cell')
    const first = cells[0]!
    expect(first.attributes('data-date')).toBe('2026-06-01')
    expect(first.attributes('data-in-month')).toBe('true')
    // 30 days in June + Monday start = last in-month cell is index 29; the
    // remaining 12 cells are trailing July padding.
    const trailing = cells.slice(30)
    expect(trailing.every((c) => c.attributes('data-in-month') === 'false')).toBe(true)
    expect(trailing[0]!.attributes('data-date')).toBe('2026-07-01')
  })

  it('adds leading padding from the previous month when the 1st is not the week start', () => {
    // July 2026 starts on a Wednesday → Mon/Tue (June 29/30) lead.
    const { wrapper } = mountGrid({ month: 7, monthLabel: 'July 2026' })
    const cells = wrapper.findAll('.cmg__cell')
    expect(cells[0]!.attributes('data-date')).toBe('2026-06-29')
    expect(cells[0]!.attributes('data-in-month')).toBe('false')
    expect(cells[2]!.attributes('data-date')).toBe('2026-07-01')
    expect(cells[2]!.attributes('data-in-month')).toBe('true')
  })

  it('supports a Sunday-first week (weekStartsOn=0)', () => {
    // June 2026: June 1 is Monday, so Sunday-first leads with May 31.
    const { wrapper } = mountGrid({ weekStartsOn: 0 })
    const cells = wrapper.findAll('.cmg__cell')
    expect(cells[0]!.attributes('data-date')).toBe('2026-05-31')
    expect(cells[1]!.attributes('data-date')).toBe('2026-06-01')
  })
})

describe('CMonthGrid — today marker', () => {
  it('flags the cell matching the today prop', () => {
    const { wrapper } = mountGrid({ today: '2026-06-15' })
    const today = wrapper.find('.cmg__cell--today')
    expect(today.exists()).toBe(true)
    expect(today.attributes('data-date')).toBe('2026-06-15')
    expect(today.attributes('aria-current')).toBe('date')
  })

  it('renders no today marker when today falls outside the visible month', () => {
    const { wrapper } = mountGrid({ today: '2025-01-01' })
    expect(wrapper.find('.cmg__cell--today').exists()).toBe(false)
  })
})

describe('CMonthGrid — interaction', () => {
  it('emits prev / next when the nav buttons are clicked', async () => {
    const { wrapper } = mountGrid()
    await wrapper.find('[data-test="cmg-prev"]').trigger('click')
    await wrapper.find('[data-test="cmg-next"]').trigger('click')
    expect(wrapper.emitted('prev')).toHaveLength(1)
    expect(wrapper.emitted('next')).toHaveLength(1)
  })

  it('emits day-click with the cell date', async () => {
    const { wrapper } = mountGrid()
    await wrapper.findAll('.cmg__cell')[0]!.trigger('click')
    expect(wrapper.emitted('day-click')?.[0]).toEqual(['2026-06-01'])
  })

  it('exposes the per-cell scope object to the #day slot', () => {
    const { wrapper } = mountGrid(
      { today: '2026-06-01' },
      {
        day: `
          <span
            class="slot-probe"
            :data-d="params.cell.date"
            :data-day="params.cell.day"
            :data-in="params.cell.inMonth"
            :data-t="params.cell.isToday"
          />
        `,
      },
    )
    const probes = wrapper.findAll('.slot-probe')
    expect(probes).toHaveLength(42)
    expect(probes[0]!.attributes('data-d')).toBe('2026-06-01')
    expect(probes[0]!.attributes('data-day')).toBe('1')
    expect(probes[0]!.attributes('data-in')).toBe('true')
    expect(probes[0]!.attributes('data-t')).toBe('true')
  })
})
