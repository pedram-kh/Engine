/** PL-1.3 W1 — the stream card: each state, and one segment per step. */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'

import enApp from '@/core/i18n/locales/en/app.json'
import { makeStep, makeTracker, TODAY } from '../waveTestKit'
import WaveStreamCard from './WaveStreamCard.vue'

function card(steps: ReturnType<typeof makeStep>[], stream = 0) {
  const summary = makeTracker(steps).attributes.streams[stream]
  if (summary === undefined) throw new Error('no stream')
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  return mount(WaveStreamCard, {
    props: { summary, today: TODAY },
    global: { plugins: [i18n, createVuetify({ components: vuetifyComponents })] },
  })
}

const thirteen = (done: number, over: Parameters<typeof makeStep>[0] = {}) =>
  Array.from({ length: 13 }, (_, i) =>
    makeStep(i < done ? { status: 'done', display_status: 'done', ...over } : over),
  )

describe('WaveStreamCard (PL-1.3 W1)', () => {
  it('draws one segment per step, filled for the done ones, and "9 of 13 steps"', () => {
    const w = card(thirteen(9))
    const segs = w.findAll('.stream-card__segment')
    expect(segs).toHaveLength(13)
    expect(w.findAll('.stream-card__segment--on')).toHaveLength(9)
    expect(w.get('[data-test="stream-count"]').text()).toBe('9 of 13 steps')
    expect(w.text()).toContain('On track')
    expect(w.classes()).not.toContain('stream-card--delayed')
  })

  it('a stream of five steps draws five segments, not a fixed six', () => {
    const w = card(Array.from({ length: 5 }, () => makeStep({ stream: 'statics' })))
    expect(w.findAll('.stream-card__segment')).toHaveLength(5)
  })

  it('a delayed stream has the red border and says how many days', () => {
    const w = card([
      makeStep({
        status: 'in_progress',
        display_status: 'delayed',
        days_overdue: 3,
        due_on: '2026-11-15',
      }),
      makeStep(),
    ])
    expect(w.classes()).toContain('stream-card--delayed')
    expect(w.text()).toContain('Delayed · 3 days')
    expect(w.get('[data-test="stream-next"]').text()).toContain('Nov 15')
  })

  it('a single delayed day is singular', () => {
    const w = card([makeStep({ display_status: 'delayed', days_overdue: 1, due_on: '2026-11-17' })])
    expect(w.text()).toContain('Delayed · 1 day')
    expect(w.text()).not.toContain('1 days')
  })

  it('a finished stream says Complete and has nothing next', () => {
    const w = card(thirteen(13))
    expect(w.text()).toContain('Complete')
    expect(w.get('[data-test="stream-next"]').text()).toBe('All steps done')
    expect(w.findAll('.stream-card__segment--on')).toHaveLength(13)
  })

  it('shows the Client among the owners when a step is client-owned', () => {
    const w = card([makeStep({ owner_client: true })])
    expect(w.get('[data-test="stream-owners"]').text()).toContain('Client')
  })
})
