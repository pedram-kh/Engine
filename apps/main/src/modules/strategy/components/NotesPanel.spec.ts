/**
 * PL-2.5 — the notes panel: an expand control that asks for the large view, and a thread that is drawn only while the
 * large view is not (one composer at a time, so the draft is never in two places).
 */
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/strategy.api', () => ({ strategyApi: { listNotes: vi.fn() } }))

import { strategyApi } from '../api/strategy.api'
import { mountStrategy } from '../strategyTestKit'
import NotesPanel from './NotesPanel.vue'

describe('NotesPanel (PL-2.5)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(strategyApi.listNotes).mockResolvedValue({ data: [] })
  })
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('has an expand control named "Expand notes" that asks for the large view', async () => {
    const w = await mountStrategy(NotesPanel, { step: 'usps', market: null })
    await flushPromises()
    const btn = w.get('[data-test="notes-expand"]')
    expect(btn.attributes('aria-label')).toBe('Expand notes')
    expect(btn.attributes('aria-expanded')).toBe('false')
    await btn.trigger('click')
    expect(w.emitted('expand')).toHaveLength(1)
    expect(w.find('[data-test="notes-scroll"]').exists()).toBe(true)
    w.unmount()
  })

  it('draws no thread, so no second composer, while the large view is open', async () => {
    const w = await mountStrategy(NotesPanel, { step: 'usps', market: null, expanded: true })
    await flushPromises()
    expect(w.find('[data-test="note-input"]').exists()).toBe(false)
    expect(w.get('[data-test="notes-expand"]').attributes('aria-expanded')).toBe('true')
    w.unmount()
  })
})
