/**
 * AH-121 C3: the early-error buffer. `@sentry/capacitor` has no client for a short
 * window after `init()`; whatever is thrown then must be replayed, not dropped.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp } from 'vue'

import {
  drainEarlyErrors,
  resetEarlyErrors,
  startEarlyErrorBuffer,
} from '@/core/observability/earlyErrors'

function throwOnWindow(error: Error): void {
  window.dispatchEvent(new ErrorEvent('error', { error, message: error.message }))
}

beforeEach(() => resetEarlyErrors())
afterEach(() => resetEarlyErrors())

describe('earlyErrors', () => {
  it('replays an error thrown on window before the client exists, oldest first', () => {
    startEarlyErrorBuffer(createApp({ render: () => null }))
    const first = new Error('boot one')
    const second = new Error('boot two')
    throwOnWindow(first)
    throwOnWindow(second)

    const captured: unknown[] = []
    expect(drainEarlyErrors((error) => captured.push(error))).toBe(2)
    expect(captured).toEqual([first, second])
  })

  it('replays an unhandled rejection by its reason', () => {
    startEarlyErrorBuffer(createApp({ render: () => null }))
    const reason = new Error('rejected at boot')
    const event = new Event('unhandledrejection') as PromiseRejectionEvent
    Object.defineProperty(event, 'reason', { value: reason })
    window.dispatchEvent(event)

    const captured: unknown[] = []
    drainEarlyErrors((error) => captured.push(error))
    expect(captured).toEqual([reason])
  })

  it('replays a Vue error and still calls the handler that was installed before', () => {
    const app = createApp({ render: () => null })
    const previous = vi.fn()
    app.config.errorHandler = previous
    startEarlyErrorBuffer(app)
    const error = new Error('render failed')
    app.config.errorHandler?.(error, null, 'render')

    expect(previous).toHaveBeenCalledOnce()
    const captured: unknown[] = []
    drainEarlyErrors((e) => captured.push(e))
    expect(captured).toEqual([error])
  })

  it('keeps at most 10 errors (a boot loop cannot grow it)', () => {
    startEarlyErrorBuffer(createApp({ render: () => null }))
    for (let i = 0; i < 25; i += 1) {
      throwOnWindow(new Error(`e${i}`))
    }
    const captured: Error[] = []
    expect(drainEarlyErrors((error) => captured.push(error as Error))).toBe(10)
    expect(captured[0]?.message).toBe('e0')
    expect(captured[9]?.message).toBe('e9')
  })

  it('stops buffering after the drain: later errors are not queued and listeners are gone', () => {
    startEarlyErrorBuffer(createApp({ render: () => null }))
    drainEarlyErrors(() => undefined)
    // Nothing listens any more, so jsdom would report this as an uncaught error: swallow it.
    const swallow = (event: ErrorEvent): void => event.preventDefault()
    window.addEventListener('error', swallow)
    throwOnWindow(new Error('after the client exists'))
    window.removeEventListener('error', swallow)
    const captured: unknown[] = []
    expect(drainEarlyErrors((error) => captured.push(error))).toBe(0)
    expect(captured).toEqual([])
  })

  it('after the drain the Vue handler stays harmless: it queues nothing', () => {
    const app = createApp({ render: () => null })
    startEarlyErrorBuffer(app)
    drainEarlyErrors(() => undefined)
    app.config.errorHandler?.(new Error('late render error'), null, 'render')
    const captured: unknown[] = []
    expect(drainEarlyErrors((error) => captured.push(error))).toBe(0)
  })

  it('an error object missing from the event falls back to its message', () => {
    startEarlyErrorBuffer(createApp({ render: () => null }))
    window.dispatchEvent(new ErrorEvent('error', { message: 'Script error.' }))
    const captured: unknown[] = []
    drainEarlyErrors((error) => captured.push(error))
    expect((captured[0] as Error).message).toBe('Script error.')
  })
})
