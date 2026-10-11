import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { pushPreferenceStorage } from './usePushPreference'

describe('pushPreferenceStorage', () => {
  beforeEach(() => {
    window.localStorage.clear()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('round-trips a value and removes it', () => {
    expect(pushPreferenceStorage.get('catalyst.push.askedAt')).toBeNull()

    // Spy rather than read localStorage here: the theme-SoT architecture test
    // flags any direct get/set/removeItem outside the allowlisted composable.
    const setSpy = vi.spyOn(Storage.prototype, 'setItem')
    pushPreferenceStorage.set('catalyst.push.askedAt', '123')
    expect(setSpy).toHaveBeenCalledWith('catalyst.push.askedAt', '123')
    expect(pushPreferenceStorage.get('catalyst.push.askedAt')).toBe('123')

    pushPreferenceStorage.remove('catalyst.push.askedAt')
    expect(pushPreferenceStorage.get('catalyst.push.askedAt')).toBeNull()
  })

  it('unavailable storage reads as empty and never throws (reads, writes, removes)', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('quota')
    })
    vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
      throw new Error('blocked')
    })

    expect(pushPreferenceStorage.get('k')).toBeNull()
    expect(() => pushPreferenceStorage.set('k', 'v')).not.toThrow()
    expect(() => pushPreferenceStorage.remove('k')).not.toThrow()
  })
})
