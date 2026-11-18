/** PL-1.3 W6 — the Brand page and wave page bar renders the server's object and holds no rule of its own. */
import type { WaveProgress } from '@catalyst/api-client'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'
import WaveProgressSegments from './WaveProgressSegments.vue'

function bar(progress?: WaveProgress | null) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  return mount(WaveProgressSegments, { props: { progress }, global: { plugins: [i18n] } })
}

const states = (w: ReturnType<typeof bar>) =>
  w.findAll('.wave-progress__segment').map((s) => s.attributes('data-state'))

describe('WaveProgressSegments (PL-1.3 W6)', () => {
  it('draws done, in progress and empty segments in the order the server sent them', () => {
    const w = bar({
      segments: ['done', 'in_progress', 'empty', 'done', 'empty', 'empty'],
      label_kind: 'step',
      step_name: 'Personas',
    })
    expect(states(w)).toEqual(['done', 'in_progress', 'empty', 'done', 'empty', 'empty'])
    expect(w.get('[data-test="wave-progress-label"]').text()).toBe('Personas')
  })

  it('label_kind complete reads "Complete", whatever step_name says', () => {
    const w = bar({
      segments: ['done', 'done', 'done', 'done', 'done', 'done'],
      label_kind: 'complete',
      step_name: 'Stale name',
    })
    expect(w.get('[data-test="wave-progress-label"]').text()).toBe('Complete')
  })

  it('label_kind not_started reads "Not started"', () => {
    const w = bar({
      segments: ['empty', 'empty', 'empty', 'empty', 'empty', 'empty'],
      label_kind: 'not_started',
      step_name: 'Stale name',
    })
    expect(w.get('[data-test="wave-progress-label"]').text()).toBe('Not started')
  })

  it('a renamed step shows its new name', () => {
    const w = bar({
      segments: ['done', 'empty', 'empty', 'empty', 'empty', 'empty'],
      label_kind: 'step',
      step_name: 'Value props',
    })
    expect(w.get('[data-test="wave-progress-label"]').text()).toBe('Value props')
  })

  it('without a progress object it is the PL-1.1 placeholder: six empty segments and "Not started"', () => {
    for (const w of [bar(), bar(null)]) {
      expect(states(w)).toEqual(['empty', 'empty', 'empty', 'empty', 'empty', 'empty'])
      expect(w.get('[data-test="wave-progress-label"]').text()).toBe('Not started')
    }
  })
})
