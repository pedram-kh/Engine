/**
 * PL-3.2b — the reorder of one list: the move shows at once, the WHOLE id list is sent, moves made while one is on its way are
 * folded into the newest list, and a refusal goes to the caller (which reloads and tells the person).
 */
import type { WaveScript } from '@catalyst/api-client'
import { describe, expect, it, vi } from 'vitest'

import { makeScript } from '../scriptsTestKit'
import { useReorder } from './useReorder'

const script = (n: number): WaveScript => makeScript(n)

function deferred<T>() {
  let resolve!: (v: T) => void
  let reject!: (e: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

describe('useReorder (PL-3.2b)', () => {
  it('shows the move at once, sends the whole list and confirms with the answer', async () => {
    const optimistic = vi.fn()
    const send = vi.fn().mockResolvedValue(script(1))
    const confirmed = vi.fn()
    const failed = vi.fn()
    const r = useReorder({ optimistic, send, confirmed, failed })
    r.request(['b', 'a', 'c'])
    expect(optimistic).toHaveBeenCalledWith(['b', 'a', 'c'])
    await vi.waitFor(() => expect(confirmed).toHaveBeenCalledTimes(1))
    expect(send).toHaveBeenCalledWith(['b', 'a', 'c'])
    expect(failed).not.toHaveBeenCalled()
  })

  it('moves made while one is on its way are folded: only the newest list is sent next, and only its answer is confirmed', async () => {
    const first = deferred<WaveScript>()
    const send = vi
      .fn()
      .mockImplementationOnce(() => first.promise)
      .mockResolvedValue(script(3))
    const confirmed = vi.fn()
    const r = useReorder({ optimistic: vi.fn(), send, confirmed, failed: vi.fn() })
    r.request(['b', 'a', 'c'])
    r.request(['b', 'c', 'a'])
    r.request(['c', 'b', 'a'])
    first.resolve(script(1))
    await vi.waitFor(() => expect(confirmed).toHaveBeenCalledTimes(1))
    expect(send).toHaveBeenCalledTimes(2)
    expect(send.mock.calls[1]?.[0]).toEqual(['c', 'b', 'a'])
    expect(confirmed).toHaveBeenCalledWith(script(3))
  })

  it('a refusal goes to the caller once, and a later move still works', async () => {
    const err = new Error('stale')
    const send = vi.fn().mockRejectedValueOnce(err).mockResolvedValue(script(2))
    const confirmed = vi.fn()
    const failed = vi.fn()
    const r = useReorder({ optimistic: vi.fn(), send, confirmed, failed })
    r.request(['b', 'a'])
    await vi.waitFor(() => expect(failed).toHaveBeenCalledWith(err))
    expect(confirmed).not.toHaveBeenCalled()
    r.request(['a', 'b'])
    await vi.waitFor(() => expect(confirmed).toHaveBeenCalledTimes(1))
    expect(failed).toHaveBeenCalledTimes(1)
  })
})
