/**
 * PL-2.5 — the notes thread: plain text only (a hostile string stays text), the add / edit / delete flow, the
 * ownership buttons the server decides (`can_edit`, `can_delete`), a removed author shown as a former member, and a
 * late answer for a market you have left not painting into the market you are on.
 */
import type { StrategyNoteResource } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/strategy.api', () => ({
  strategyApi: { listNotes: vi.fn(), addNote: vi.fn(), editNote: vi.fn(), deleteNote: vi.fn() },
}))

import { strategyApi } from '../api/strategy.api'
import { mountStrategy, overlay } from '../strategyTestKit'
import NotesThread from './NotesThread.vue'

const HOSTILE = '<img src=x onerror=alert(1)><script>window.__pwn=1</script>'

function note(
  n: number,
  over: Partial<StrategyNoteResource['attributes']> = {},
): StrategyNoteResource {
  return {
    id: `01NOTE${String(n).padStart(20, '0')}`,
    type: 'wave_strategy_notes',
    attributes: {
      step: 'usps',
      market: null,
      body: `Note ${n}`,
      author: { id: '01USER', name: 'Ada Admin' },
      author_is_former: false,
      created_at: '2026-11-22T10:00:00.000000Z',
      edited: false,
      edited_at: null,
      can_edit: true,
      can_delete: true,
      ...over,
    },
  }
}

async function mountThread(notes: StrategyNoteResource[], market: string | null = null) {
  vi.mocked(strategyApi.listNotes).mockResolvedValue({ data: notes })
  const w = await mountStrategy(NotesThread, { step: 'usps', market })
  await flushPromises()
  return w
}

async function type(w: Awaited<ReturnType<typeof mountThread>>, value: string) {
  const el = w.get('[data-test="note-input"] textarea')
  await el.setValue(value)
}

describe('NotesThread (PL-2.5)', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('draws a hostile note as plain text: no element is created from it and nothing runs', async () => {
    const w = await mountThread([note(1, { body: HOSTILE })])
    expect(w.get('[data-test="note-body"]').text()).toBe(HOSTILE)
    expect(w.find('img').exists()).toBe(false)
    expect(w.find('script').exists()).toBe(false)
    expect((window as unknown as { __pwn?: number }).__pwn).toBeUndefined()
    w.unmount()
  })

  it('adds a typed hostile note and shows what the server answered, as text', async () => {
    vi.mocked(strategyApi.addNote).mockResolvedValue({ data: note(2, { body: HOSTILE }) })
    const w = await mountThread([])
    expect(w.find('[data-test="note-add"]').attributes('disabled')).toBeDefined()
    await type(w, HOSTILE)
    await w.get('[data-test="note-add"]').trigger('click')
    await flushPromises()
    expect(vi.mocked(strategyApi.addNote).mock.calls[0]?.[3]).toBe(HOSTILE)
    expect(w.findAll('[data-test="note-body"]').map((b) => b.text())).toEqual([HOSTILE])
    expect(w.emitted('changed')).toHaveLength(1)
    expect(w.find('img').exists()).toBe(false)
    w.unmount()
  })

  it('refuses an empty or blank note and one over 2000 characters', async () => {
    const w = await mountThread([])
    await type(w, '   \n ')
    expect(w.get('[data-test="note-add"]').attributes('disabled')).toBeDefined()
    await type(w, 'x'.repeat(2001))
    expect(w.get('[data-test="note-add"]').attributes('disabled')).toBeDefined()
    await type(w, 'x'.repeat(2000))
    expect(w.get('[data-test="note-add"]').attributes('disabled')).toBeUndefined()
    w.unmount()
  })

  it('shows Edit and Delete only where the server says the person may, and a former member without a name', async () => {
    const w = await mountThread([
      note(1),
      note(2, { can_edit: false, can_delete: true }),
      note(3, { can_edit: false, can_delete: false, author: null, author_is_former: true }),
    ])
    const rows = w.findAll('li')
    expect(rows[0]?.find('[data-test="note-edit"]').exists()).toBe(true)
    expect(rows[1]?.find('[data-test="note-edit"]').exists()).toBe(false)
    expect(rows[1]?.find('[data-test="note-delete"]').exists()).toBe(true)
    expect(rows[2]?.find('[data-test="note-edit"], [data-test="note-delete"]').exists()).toBe(false)
    expect(rows[2]?.text()).toContain('Former member')
    expect(rows[2]?.text()).not.toContain('Ada Admin')
    w.unmount()
  })

  it('edits a note and replaces it with the answer', async () => {
    vi.mocked(strategyApi.editNote).mockResolvedValue({
      data: note(1, { body: 'Changed', edited: true, edited_at: '2026-11-22T11:00:00.000000Z' }),
    })
    const w = await mountThread([note(1)])
    await w.get('[data-test="note-edit"]').trigger('click')
    await w.get('[data-test="note-edit-input"] textarea').setValue('Changed')
    await w.get('[data-test="note-edit-save"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-test="note-body"]').text()).toBe('Changed')
    expect(w.find('[data-test="note-edited"]').exists()).toBe(true)
    w.unmount()
  })

  it('deletes only after the confirm, and treats a note that is already gone as deleted', async () => {
    vi.mocked(strategyApi.deleteNote).mockResolvedValue(undefined)
    const w = await mountThread([note(1)])
    await w.get('[data-test="note-delete"]').trigger('click')
    await flushPromises()
    expect(strategyApi.deleteNote).not.toHaveBeenCalled()
    overlay('[data-test="confirm-ok"]')?.click()
    await flushPromises()
    expect(strategyApi.deleteNote).toHaveBeenCalledTimes(1)
    expect(w.find('[data-test="note-body"]').exists()).toBe(false)
    expect(w.emitted('changed')).toHaveLength(1)
    w.unmount()
  })

  it('a slow answer for the market you left does not paint into the market you are on', async () => {
    let late: (v: { data: StrategyNoteResource[] }) => void = () => {}
    vi.mocked(strategyApi.listNotes)
      .mockImplementationOnce(() => new Promise((res) => (late = res)))
      .mockResolvedValueOnce({ data: [note(9, { body: 'Romania note' })] })
    const w = await mountStrategy(NotesThread, { step: 'personas', market: 'SK' })
    await w.setProps({ market: 'RO' })
    await flushPromises()
    late({ data: [note(1, { body: 'Slovakia note' })] })
    await flushPromises()
    expect(w.findAll('[data-test="note-body"]').map((b) => b.text())).toEqual(['Romania note'])
    w.unmount()
  })

  it('PL-2.5 N1: opens scrolled to the newest note, and scrolls to a note just added', async () => {
    const heights = vi.spyOn(HTMLElement.prototype, 'scrollHeight', 'get').mockReturnValue(900)
    try {
      const w = await mountThread([note(1), note(2), note(3)])
      const list = w.get('[data-test="notes-scroll"]').element as HTMLElement
      expect(list.scrollTop).toBe(900)
      list.scrollTop = 0
      heights.mockReturnValue(1200)
      vi.mocked(strategyApi.addNote).mockResolvedValue({ data: note(4) })
      await type(w, 'Another')
      await w.get('[data-test="note-add"]').trigger('click')
      await flushPromises()
      expect(list.scrollTop).toBe(1200)
      // The composer is outside the scrolling list, so it stays pinned at the bottom of the panel.
      expect(w.get('[data-test="notes-scroll"]').find('[data-test="note-input"]').exists()).toBe(
        false,
      )
      w.unmount()
    } finally {
      heights.mockRestore()
    }
  })

  it('PL-2.5 N2: the draft is a model the parent owns, so it survives the thread being replaced', async () => {
    vi.mocked(strategyApi.listNotes).mockResolvedValue({ data: [note(1)] })
    const w = await mountStrategy(NotesThread, { step: 'usps', market: null, draft: 'kept' })
    await flushPromises()
    expect((w.get('[data-test="note-input"] textarea').element as HTMLTextAreaElement).value).toBe(
      'kept',
    )
    w.unmount()
  })
})
