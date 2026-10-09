/**
 * AH-100d (item 8, layer b) — the status poll's own contract, both
 * polarities: runs only while active, stops when it goes inactive and on scope
 * disposal, skips while the document is hidden, never overlaps, survives a
 * failing tick.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, ref } from 'vue'

import { APPLICATION_STATUS_POLL_MS, useApplicationStatusPoll } from './useApplicationStatusPoll'

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
})

function run(options: Partial<Parameters<typeof useApplicationStatusPoll>[0]> & { active: never }) {
  const scope = effectScope()
  scope.run(() =>
    useApplicationStatusPoll({
      refresh: async () => undefined,
      ...options,
    }),
  )
  return scope
}

describe('useApplicationStatusPoll', () => {
  it('refreshes once per interval while active — and not before the first one', async () => {
    const refresh = vi.fn(async () => undefined)
    run({ active: ref(true) as never, refresh })

    await vi.advanceTimersByTimeAsync(APPLICATION_STATUS_POLL_MS - 1)
    expect(refresh).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(1)
    expect(refresh).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(APPLICATION_STATUS_POLL_MS * 2)
    expect(refresh).toHaveBeenCalledTimes(3)
  })

  it('never starts while inactive, starts when it turns active, stops when it turns inactive', async () => {
    const refresh = vi.fn(async () => undefined)
    const active = ref(false)
    run({ active: active as never, refresh })

    await vi.advanceTimersByTimeAsync(APPLICATION_STATUS_POLL_MS * 2)
    expect(refresh).not.toHaveBeenCalled()

    active.value = true
    await vi.advanceTimersByTimeAsync(APPLICATION_STATUS_POLL_MS)
    expect(refresh).toHaveBeenCalledTimes(1)

    active.value = false
    await vi.advanceTimersByTimeAsync(APPLICATION_STATUS_POLL_MS * 3)
    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('stops on scope disposal (unmount) and leaves no timer behind', async () => {
    const refresh = vi.fn(async () => undefined)
    const scope = run({ active: ref(true) as never, refresh })

    scope.stop()
    await vi.advanceTimersByTimeAsync(APPLICATION_STATUS_POLL_MS * 3)

    expect(refresh).not.toHaveBeenCalled()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('skips a tick while the document is hidden and resumes when visible', async () => {
    const refresh = vi.fn(async () => undefined)
    let hidden = true
    run({ active: ref(true) as never, refresh, isHidden: () => hidden })

    await vi.advanceTimersByTimeAsync(APPLICATION_STATUS_POLL_MS * 2)
    expect(refresh).not.toHaveBeenCalled()

    hidden = false
    await vi.advanceTimersByTimeAsync(APPLICATION_STATUS_POLL_MS)
    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('does not overlap itself while a refresh is still in flight', async () => {
    let release!: () => void
    const refresh = vi.fn(
      () =>
        new Promise<void>((resolve) => {
          release = resolve
        }),
    )
    run({ active: ref(true) as never, refresh })

    await vi.advanceTimersByTimeAsync(APPLICATION_STATUS_POLL_MS * 3)
    expect(refresh).toHaveBeenCalledTimes(1)

    release()
    await vi.advanceTimersByTimeAsync(APPLICATION_STATUS_POLL_MS)
    expect(refresh).toHaveBeenCalledTimes(2)
  })

  it('a failing tick does not stop the poll', async () => {
    const refresh = vi
      .fn<() => Promise<void>>()
      .mockRejectedValueOnce(new Error('offline'))
      .mockResolvedValue(undefined)
    run({ active: ref(true) as never, refresh })

    await vi.advanceTimersByTimeAsync(APPLICATION_STATUS_POLL_MS * 2)

    expect(refresh).toHaveBeenCalledTimes(2)
  })
})
