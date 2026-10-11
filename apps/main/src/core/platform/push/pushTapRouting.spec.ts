import { describe, expect, it, vi } from 'vitest'

import { createPushTapHandler, PUSH_TAP_ROUTE_NAME } from './pushTapRouting'

function deferred(): { promise: Promise<void>; resolve: () => void } {
  let resolve!: () => void
  const promise = new Promise<void>((r) => {
    resolve = r
  })
  return { promise, resolve }
}

describe('createPushTapHandler', () => {
  it('opens the notification list once the router is ready', async () => {
    const push = vi.fn()
    createPushTapHandler({ isReady: () => Promise.resolve(), push })()
    await Promise.resolve()
    await Promise.resolve()

    expect(push).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledWith({ name: 'creator.notifications' })
    expect(PUSH_TAP_ROUTE_NAME).toBe('creator.notifications')
  })

  it('does NOT push while the first navigation is still in flight (the cold-start tap)', async () => {
    const ready = deferred()
    const push = vi.fn()
    createPushTapHandler({ isReady: () => ready.promise, push })()
    await Promise.resolve()
    await Promise.resolve()

    expect(push).not.toHaveBeenCalled()

    ready.resolve()
    await ready.promise
    await Promise.resolve()

    expect(push).toHaveBeenCalledTimes(1)
  })

  it('never throws when the router fails to become ready', async () => {
    const push = vi.fn()
    const handler = createPushTapHandler({
      isReady: () => Promise.reject(new Error('navigation failed')),
      push,
    })

    expect(() => handler()).not.toThrow()
    await Promise.resolve()
    await Promise.resolve()
    expect(push).not.toHaveBeenCalled()
  })
})
