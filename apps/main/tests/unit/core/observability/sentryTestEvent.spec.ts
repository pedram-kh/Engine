/**
 * AH-121 C3: the deliberate test event fires at most once per install and waits for the
 * user (or gives up waiting and fires signed out).
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const capacitor = vi.hoisted(() => ({
  captureException: vi.fn(),
  nativeCrash: vi.fn(),
  flush: vi.fn(async () => true),
}))
vi.mock('@sentry/capacitor', () => capacitor)

import {
  TEST_EVENT_MARKER,
  TEST_EVENT_MESSAGE,
  fireEarlyTestError,
  runTestEvent,
} from '@/core/observability/sentryTestEvent'

const ULID = '01HZX3K9Q5M7R2T8V4W6Y0A1B2'

beforeEach(() => {
  vi.useFakeTimers()
  window.localStorage.clear()
  capacitor.captureException.mockClear()
  capacitor.nativeCrash.mockClear()
})
afterEach(() => vi.useRealTimers())

describe('sentryTestEvent', () => {
  it('mode 1 captures the named error once the user is known', async () => {
    let id: string | null = null
    const done = runTestEvent('1', () => ({ id, type: null }))
    await vi.advanceTimersByTimeAsync(5_000)
    expect(capacitor.captureException).not.toHaveBeenCalled()
    id = ULID
    await vi.advanceTimersByTimeAsync(3_000)
    await done
    expect(capacitor.captureException).toHaveBeenCalledOnce()
    expect((capacitor.captureException.mock.calls[0]?.[0] as Error).message).toBe(
      TEST_EVENT_MESSAGE,
    )
  })

  it('fires signed out after the wait runs out', async () => {
    const done = runTestEvent('1', () => ({ id: null, type: null }))
    await vi.advanceTimersByTimeAsync(185_000)
    await done
    expect(capacitor.captureException).toHaveBeenCalledOnce()
  })

  it('crash mode crashes natively, and writes the marker before it does', async () => {
    capacitor.nativeCrash.mockImplementation(() => {
      expect(window.localStorage.getItem(TEST_EVENT_MARKER)).not.toBeNull()
    })
    const done = runTestEvent('crash', () => ({ id: ULID, type: 'creator' }))
    await vi.advanceTimersByTimeAsync(3_000)
    await done
    expect(capacitor.nativeCrash).toHaveBeenCalledOnce()
    expect(capacitor.captureException).not.toHaveBeenCalled()
  })

  it('fires at most once per install: a second run with the marker present does nothing', async () => {
    window.localStorage.setItem(TEST_EVENT_MARKER, 'earlier')
    const done = runTestEvent('crash', () => ({ id: ULID, type: 'creator' }))
    await vi.advanceTimersByTimeAsync(3_000)
    await done
    expect(capacitor.nativeCrash).not.toHaveBeenCalled()
    expect(capacitor.captureException).not.toHaveBeenCalled()
    fireEarlyTestError()
    await vi.advanceTimersByTimeAsync(10)
    expect(window.localStorage.getItem(TEST_EVENT_MARKER)).toBe('earlier')
  })

  it('two runs in one install produce one event', async () => {
    const first = runTestEvent('1', () => ({ id: ULID, type: null }))
    await vi.advanceTimersByTimeAsync(3_000)
    await first
    const second = runTestEvent('1', () => ({ id: ULID, type: null }))
    await vi.advanceTimersByTimeAsync(3_000)
    await second
    expect(capacitor.captureException).toHaveBeenCalledOnce()
  })

  it('early mode is handled by fireEarlyTestError, not by runTestEvent', async () => {
    await runTestEvent('early', () => ({ id: ULID, type: null }))
    expect(capacitor.captureException).not.toHaveBeenCalled()
    expect(window.localStorage.getItem(TEST_EVENT_MARKER)).toBeNull()
  })
})
