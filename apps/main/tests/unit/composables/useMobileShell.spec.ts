/**
 * AH-100 D4/D7 pin — the layout predicate. On the web the mobile chrome
 * still follows `smAndDown` exactly; inside the app it is on at any width.
 */

import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { createVuetify } from 'vuetify'

const platform = vi.hoisted(() => ({ native: false }))

vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
}))

import { useMobileShell, type MobileShell } from '@/composables/useMobileShell'

function setViewportWidth(width: number): void {
  Object.defineProperty(window, 'innerWidth', { value: width, writable: true, configurable: true })
  window.dispatchEvent(new Event('resize'))
}

function probe(width: number): MobileShell {
  setViewportWidth(width)
  let shell: MobileShell | null = null
  mount(
    defineComponent({
      setup() {
        shell = useMobileShell()
        return () => h('div')
      },
    }),
    { global: { plugins: [createVuetify()] } },
  )
  return shell as unknown as MobileShell
}

afterEach(() => {
  platform.native = false
  setViewportWidth(1024)
})

describe('useMobileShell', () => {
  it.each([
    [390, true],
    [959, true],
    [1280, false],
  ])('web at %ipx: isMobile=%s (smAndDown)', (width, expected) => {
    const shell = probe(width)
    expect(shell.isNative).toBe(false)
    expect(shell.isMobile.value).toBe(expected)
  })

  it.each([390, 1280])('native at %ipx: always mobile', (width) => {
    platform.native = true
    const shell = probe(width)
    expect(shell.isNative).toBe(true)
    expect(shell.isMobile.value).toBe(true)
  })
})
