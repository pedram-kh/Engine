import { beforeEach, describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({ available: true }))
const registration = vi.hoisted(() => ({ logoutCleanup: vi.fn(), forgetLocal: vi.fn() }))
const factory = vi.hoisted(() => vi.fn())

vi.mock('./pushChannelAvailable', () => ({ pushChannelAvailable: () => state.available }))
vi.mock('./usePushRegistration', () => ({
  usePushRegistration: () => {
    factory()
    return registration
  },
}))

import { pushForgetLocal, pushLogoutCleanup } from './pushLogoutCleanup'

beforeEach(() => {
  state.available = true
  registration.logoutCleanup.mockReset().mockResolvedValue(undefined)
  registration.forgetLocal.mockReset().mockResolvedValue(undefined)
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

describe('pushForgetLocal() (AH-104b-del)', () => {
  it('forgets locally where push is available, and never calls the server cleanup', async () => {
    await pushForgetLocal()

    expect(registration.forgetLocal).toHaveBeenCalledTimes(1)
    expect(registration.logoutCleanup).not.toHaveBeenCalled()
  })

  it('does not load the registration where push is unavailable', async () => {
    state.available = false

    await pushForgetLocal()

    expect(factory).not.toHaveBeenCalled()
  })

  it('never throws', async () => {
    registration.forgetLocal.mockRejectedValue(new Error('boom'))

    await expect(pushForgetLocal()).resolves.toBeUndefined()
  })
})
