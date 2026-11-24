/* eslint-disable vue/one-component-per-file -- tiny host components that only run the composable */
/**
 * PL-3.2a — the autosave of one block: debounce, the saving/saved states, a late answer that must not eat typing, a failed save
 * that stays visibly unsaved and retries (network) or waits (refused), the 409 that keeps the person's text, and the flush the
 * leave guard relies on. No text is ever replaced silently.
 */
import { ApiError } from '@catalyst/api-client'
import { mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { conflict } from '../scriptsTestKit'
import { useBlockAutosave, type BlockAutosave, type BlockSnapshot } from './useBlockAutosave'

type V = { text: string }

function host(
  save: (v: V, rev: number, base: V) => Promise<BlockSnapshot<V>>,
  remote: BlockSnapshot<V> = { value: { text: 'a' }, revision: 1 },
): { block: BlockAutosave<V>; unmount: () => void } {
  let block!: BlockAutosave<V>
  const w = mount(
    defineComponent({
      setup() {
        block = useBlockAutosave<V>({
          remote: () => remote,
          save,
          same: (a, b) => a.text === b.text,
          fromConflict: (c) => ({
            value: { text: String((c as unknown as { t: string }).t) },
            revision: 9,
          }),
          clone: (v) => ({ ...v }),
          debounceMs: 1000,
          retryMs: [3000, 10000],
        })
        return () => null
      },
    }),
  )
  return { block, unmount: () => w.unmount() }
}

const ok = (text: string, revision: number) => Promise.resolve({ value: { text }, revision })
const tick = async (ms: number) => {
  await vi.advanceTimersByTimeAsync(ms)
}

describe('useBlockAutosave (PL-3.2a)', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('an edit is unsaved at once, sent once after the debounce with the block revision, then saved', async () => {
    const save = vi.fn((v: V, _r: number) => ok(v.text, 2))
    const { block } = host(save)
    block.edit((d) => (d.text = 'ab'))
    block.edit((d) => (d.text = 'abc'))
    expect(block.status.value).toBe('dirty')
    await tick(900)
    expect(save).not.toHaveBeenCalled()
    await tick(200)
    expect(save).toHaveBeenCalledTimes(1)
    expect(save.mock.calls[0]?.[0]).toEqual({ text: 'abc' })
    expect(save.mock.calls[0]?.[1]).toBe(1)
    expect(block.status.value).toBe('saved')
    expect(block.dirty.value).toBe(false)
  })

  it('typing back to the saved text sends nothing', async () => {
    const save = vi.fn((v: V) => ok(v.text, 2))
    const { block } = host(save)
    block.edit((d) => (d.text = 'x'))
    block.edit((d) => (d.text = 'a'))
    await tick(2000)
    expect(save).not.toHaveBeenCalled()
    expect(block.status.value).toBe('saved')
    expect(block.dirty.value).toBe(false)
  })

  it('flush saves at once (type and leave at once)', async () => {
    const save = vi.fn((v: V) => ok(v.text, 2))
    const { block } = host(save)
    block.edit((d) => (d.text = 'quick'))
    await expect(block.flush()).resolves.toBe(true)
    expect(save).toHaveBeenCalledTimes(1)
    expect(block.status.value).toBe('saved')
  })

  it('text typed while a save is out is kept, and goes in a second request', async () => {
    let release!: (r: BlockSnapshot<V>) => void
    const save = vi
      .fn<(v: V, r: number) => Promise<BlockSnapshot<V>>>()
      .mockImplementationOnce(() => new Promise((res) => (release = res)))
      .mockImplementation((v, r) => ok(v.text, r + 1))
    const { block } = host(save)
    block.edit((d) => (d.text = 'one'))
    const first = block.flush()
    await tick(0)
    expect(block.status.value).toBe('saving')
    block.edit((d) => (d.text = 'one two'))
    release({ value: { text: 'one' }, revision: 2 })
    await first
    expect(block.draft.value.text).toBe('one two')
    expect(save).toHaveBeenCalledTimes(2)
    expect(save.mock.calls[1]?.[0]).toEqual({ text: 'one two' })
    expect(save.mock.calls[1]?.[1]).toBe(2)
  })

  it("the server's cleaned wording is adopted only when nothing was typed meanwhile", async () => {
    const save = vi.fn(() => ok('trimmed', 2))
    const { block } = host(save)
    block.edit((d) => (d.text = 'trimmed  '))
    await block.flush()
    expect(block.draft.value.text).toBe('trimmed')
  })

  it('a network failure stays visibly unsaved, keeps the text, retries after 3 s and then saves', async () => {
    const save = vi
      .fn<(v: V, r: number) => Promise<BlockSnapshot<V>>>()
      .mockRejectedValueOnce(new Error('network'))
      .mockImplementation((v) => ok(v.text, 2))
    const { block } = host(save)
    block.edit((d) => (d.text = 'keep me'))
    await tick(1000)
    expect(block.status.value).toBe('failed')
    expect(block.failure.value).toBe('retry')
    expect(block.draft.value.text).toBe('keep me')
    await tick(3000)
    expect(save).toHaveBeenCalledTimes(2)
    expect(block.status.value).toBe('saved')
  })

  it('after the automatic retries are used up the block stays failed until Retry; Retry saves', async () => {
    const save = vi
      .fn<(v: V, r: number) => Promise<BlockSnapshot<V>>>()
      .mockRejectedValue(new Error('down'))
    const { block } = host(save)
    block.edit((d) => (d.text = 'x'))
    await tick(1000)
    await tick(3000)
    await tick(10000)
    expect(save).toHaveBeenCalledTimes(3)
    await tick(60000)
    expect(save).toHaveBeenCalledTimes(3)
    expect(block.status.value).toBe('failed')
    save.mockImplementation((v) => ok(v.text, 2))
    await block.retry()
    expect(block.status.value).toBe('saved')
  })

  it('a refusal (422) is not retried by itself and says so', async () => {
    const save = vi.fn().mockRejectedValue(new ApiError({ status: 422, code: 'x', message: 'x' }))
    const { block } = host(save)
    block.edit((d) => (d.text = 'x'))
    await tick(1000)
    await tick(30000)
    expect(save).toHaveBeenCalledTimes(1)
    expect(block.failure.value).toBe('refused')
    expect(block.status.value).toBe('failed')
  })

  it('a 404 marks the block gone', async () => {
    const save = vi.fn().mockRejectedValue(new ApiError({ status: 404, code: 'x', message: 'x' }))
    const { block } = host(save)
    block.edit((d) => (d.text = 'x'))
    await tick(1000)
    expect(block.status.value).toBe('gone')
  })

  it('a 409 keeps my text, holds theirs, and sends nothing more until I choose', async () => {
    const save = vi.fn().mockRejectedValue(conflict('hook', { t: 'theirs' }))
    const { block } = host(save)
    block.edit((d) => (d.text = 'mine'))
    await tick(1000)
    expect(block.status.value).toBe('conflict')
    expect(block.draft.value.text).toBe('mine')
    expect(block.theirs.value?.text).toBe('theirs')
    block.edit((d) => (d.text = 'mine!'))
    await tick(5000)
    expect(save).toHaveBeenCalledTimes(1)
    expect(block.draft.value.text).toBe('mine!')
  })

  it('Keep mine saves my text on top of their revision', async () => {
    const save = vi
      .fn<(v: V, r: number) => Promise<BlockSnapshot<V>>>()
      .mockRejectedValueOnce(conflict('hook', { t: 'theirs' }))
      .mockImplementation((v) => ok(v.text, 10))
    const { block } = host(save)
    block.edit((d) => (d.text = 'mine'))
    await tick(1000)
    await block.keepMine()
    expect(save.mock.calls[1]?.[1]).toBe(9)
    expect(save.mock.calls[1]?.[0]).toEqual({ text: 'mine' })
    expect(block.status.value).toBe('saved')
    expect(block.theirs.value).toBeNull()
  })

  it('Use their version replaces my text with theirs and nothing is sent', async () => {
    const save = vi.fn().mockRejectedValue(conflict('hook', { t: 'theirs' }))
    const { block } = host(save)
    block.edit((d) => (d.text = 'mine'))
    await tick(1000)
    block.useTheirs()
    expect(block.draft.value.text).toBe('theirs')
    expect(block.status.value).toBe('saved')
    expect(save).toHaveBeenCalledTimes(1)
  })

  it('syncRemote adopts a newer server version only when the block holds no work of its own', async () => {
    let remote: BlockSnapshot<V> = { value: { text: 'a' }, revision: 1 }
    let block!: BlockAutosave<V>
    mount(
      defineComponent({
        setup() {
          block = useBlockAutosave<V>({
            remote: () => remote,
            save: (v) => ok(v.text, 2),
            same: (a, b) => a.text === b.text,
            fromConflict: () => null,
            clone: (v) => ({ ...v }),
          })
          return () => null
        },
      }),
    )
    remote = { value: { text: 'b' }, revision: 3 }
    block.syncRemote()
    expect(block.draft.value.text).toBe('b')
    block.edit((d) => (d.text = 'typing'))
    remote = { value: { text: 'c' }, revision: 4 }
    block.syncRemote()
    expect(block.draft.value.text).toBe('typing')
  })

  it('unmounting stops the timers: nothing is sent after the block is gone', async () => {
    const save = vi.fn((v: V) => ok(v.text, 2))
    const { block, unmount } = host(save)
    block.edit((d) => (d.text = 'x'))
    unmount()
    await tick(5000)
    expect(save).not.toHaveBeenCalled()
  })
})
