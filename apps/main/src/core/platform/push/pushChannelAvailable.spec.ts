import { describe, expect, it } from 'vitest'

import { IOS_PUSH_ENABLED, pushChannelAvailable } from './pushChannelAvailable'

describe('pushChannelAvailable (AH-102, P-8)', () => {
  it('is false on the web, whatever the iOS switch says', () => {
    expect(pushChannelAvailable({ platform: () => null })).toBe(false)
    expect(pushChannelAvailable({ platform: () => null, iosPushEnabled: true })).toBe(false)
  })

  it('is true on Android', () => {
    expect(pushChannelAvailable({ platform: () => 'android' })).toBe(true)
  })

  it('is false on iOS until AH-102-ios flips the constant — native is not enough', () => {
    expect(pushChannelAvailable({ platform: () => 'ios' })).toBe(false)
    expect(pushChannelAvailable({ platform: () => 'ios', iosPushEnabled: false })).toBe(false)
  })

  it('is true on iOS once the iOS switch is on', () => {
    expect(pushChannelAvailable({ platform: () => 'ios', iosPushEnabled: true })).toBe(true)
  })

  it('ships with iOS push OFF (flipped only by the AH-102-ios chunk)', () => {
    expect(IOS_PUSH_ENABLED).toBe(false)
  })

  it('defaults to the real platform probe: false under jsdom (no Capacitor shell)', () => {
    expect(pushChannelAvailable()).toBe(false)
  })
})
