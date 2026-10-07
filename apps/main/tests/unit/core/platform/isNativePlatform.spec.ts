import { afterEach, describe, expect, it, vi } from 'vitest'

const capacitor = vi.hoisted(() => ({
  isNativePlatform: vi.fn(() => false),
  getPlatform: vi.fn(() => 'web'),
}))

vi.mock('@capacitor/core', () => ({ Capacitor: capacitor }))

import { isNativePlatform, nativePlatform } from '@/core/platform/isNativePlatform'

afterEach(() => {
  capacitor.isNativePlatform.mockReset().mockReturnValue(false)
  capacitor.getPlatform.mockReset().mockReturnValue('web')
})

describe('isNativePlatform', () => {
  it('mirrors Capacitor.isNativePlatform()', () => {
    expect(isNativePlatform()).toBe(false)
    capacitor.isNativePlatform.mockReturnValue(true)
    expect(isNativePlatform()).toBe(true)
  })
})

describe('nativePlatform', () => {
  it('is null on the web', () => {
    expect(nativePlatform()).toBeNull()
    expect(capacitor.getPlatform).not.toHaveBeenCalled()
  })

  it.each(['ios', 'android'] as const)('reports %s inside the shell', (platform) => {
    capacitor.isNativePlatform.mockReturnValue(true)
    capacitor.getPlatform.mockReturnValue(platform)
    expect(nativePlatform()).toBe(platform)
  })

  it('is null for an unknown native platform', () => {
    capacitor.isNativePlatform.mockReturnValue(true)
    capacitor.getPlatform.mockReturnValue('electron')
    expect(nativePlatform()).toBeNull()
  })
})
