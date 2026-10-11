import { describe, expect, it, vi } from 'vitest'

import type { PushRegistration } from './pushRegistration'
import { startPush } from './startPush'

function fakeRegistration() {
  const stop = vi.fn()
  const start = vi.fn(() => stop)
  const syncIfGranted = vi.fn(async () => undefined)
  return {
    registration: { start, syncIfGranted } as unknown as PushRegistration,
    start,
    stop,
    syncIfGranted,
  }
}

function fakeDocument(initial: 'visible' | 'hidden' = 'visible') {
  const listeners = new Map<string, () => void>()
  const doc = {
    visibilityState: initial as DocumentVisibilityState,
    addEventListener: vi.fn(
      (type: string, listener: () => void) => void listeners.set(type, listener),
    ),
    removeEventListener: vi.fn((type: string) => void listeners.delete(type)),
  }
  return {
    doc: doc as unknown as Pick<
      Document,
      'addEventListener' | 'removeEventListener' | 'visibilityState'
    >,
    raw: doc,
    fire: () => listeners.get('visibilitychange')?.(),
  }
}

describe('startPush()', () => {
  it('is a complete no-op where push is unavailable (web; iOS before AH-102-ios)', () => {
    const f = fakeRegistration()
    const d = fakeDocument()

    const started = startPush({
      isAvailable: () => false,
      registration: f.registration,
      document: d.doc,
      onForeground: vi.fn(),
      onTap: vi.fn(),
    })
    started.sync()
    started.stop()

    expect(f.start).not.toHaveBeenCalled()
    expect(f.syncIfGranted).not.toHaveBeenCalled()
    expect(d.raw.addEventListener).not.toHaveBeenCalled()
  })

  it('starts the listeners with the injected handlers', () => {
    const f = fakeRegistration()
    const onForeground = vi.fn()
    const onTap = vi.fn()

    startPush({
      isAvailable: () => true,
      registration: f.registration,
      document: fakeDocument().doc,
      onForeground,
      onTap,
    })

    expect(f.start).toHaveBeenCalledWith({ onForeground, onTap })
  })

  it('syncs when the app comes back to the foreground, and not while hidden', () => {
    const f = fakeRegistration()
    const d = fakeDocument('hidden')
    startPush({
      isAvailable: () => true,
      registration: f.registration,
      document: d.doc,
      onForeground: vi.fn(),
      onTap: vi.fn(),
    })

    d.fire()
    expect(f.syncIfGranted).not.toHaveBeenCalled()

    d.raw.visibilityState = 'visible'
    d.fire()
    expect(f.syncIfGranted).toHaveBeenCalledTimes(1)
  })

  it('sync() re-checks permission on demand (a session appeared)', () => {
    const f = fakeRegistration()
    const started = startPush({
      isAvailable: () => true,
      registration: f.registration,
      document: fakeDocument().doc,
      onForeground: vi.fn(),
      onTap: vi.fn(),
    })

    started.sync()

    expect(f.syncIfGranted).toHaveBeenCalledTimes(1)
  })

  it('a failing sync never surfaces', async () => {
    const f = fakeRegistration()
    f.syncIfGranted.mockRejectedValue(new Error('boom'))
    const started = startPush({
      isAvailable: () => true,
      registration: f.registration,
      document: fakeDocument().doc,
      onForeground: vi.fn(),
      onTap: vi.fn(),
    })

    expect(() => started.sync()).not.toThrow()
    await Promise.resolve()
  })

  it('stop() stops the registration and removes the visibility listener', () => {
    const f = fakeRegistration()
    const d = fakeDocument()
    const started = startPush({
      isAvailable: () => true,
      registration: f.registration,
      document: d.doc,
      onForeground: vi.fn(),
      onTap: vi.fn(),
    })

    started.stop()

    expect(f.stop).toHaveBeenCalledTimes(1)
    expect(d.raw.removeEventListener).toHaveBeenCalledWith('visibilitychange', expect.any(Function))
  })
})
