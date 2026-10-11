import { beforeEach, describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({ available: true }))
const registration = vi.hoisted(() => ({ logoutCleanup: vi.fn() }))
const factory = vi.hoisted(() => vi.fn())

vi.mock('./pushChannelAvailable', () => ({ pushChannelAvailable: () => state.available }))
vi.mock('./usePushRegistration', () => ({
  usePushRegistration: () => {
    factory()
    return registration
  },
}))

import { pushLogoutCleanup } from './pushLogoutCleanup'

beforeEach(() => {
  state.available = true
  registration.logoutCleanup.mockReset().mockResolvedValue(undefined)
  factory.mockReset()
})

describe('pushLogoutCleanup()', () => {
  it('runs the registration cleanup where push is available', async () => {
    await pushLogoutCleanup()

    expect(registration.logoutCleanup).toHaveBeenCalledTimes(1)
  })

  it('does not even load the registration where push is unavailable (web sign-out is untouched)', async () => {
    state.available = false

    await pushLogoutCleanup()

    expect(factory).not.toHaveBeenCalled()
    expect(registration.logoutCleanup).not.toHaveBeenCalled()
  })

  it('never throws: a failing cleanup must not block sign-out', async () => {
    registration.logoutCleanup.mockRejectedValue(new Error('boom'))

    await expect(pushLogoutCleanup()).resolves.toBeUndefined()
  })
})
