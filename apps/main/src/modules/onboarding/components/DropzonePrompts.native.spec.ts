/**
 * AH-100c — the upload dropzones say "tap" inside the Capacitor app.
 *
 * "Drop a photo here, or click to choose" is wrong on a touch screen: there is
 * nothing to drop onto and nothing to click. Native swaps the prompt for a
 * "Tap to choose…" variant; the web copy is pinned beside it, unchanged.
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

const platform = vi.hoisted(() => ({ native: false }))
vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
}))

import AvatarUploadDrop from './AvatarUploadDrop.vue'
import PortfolioUploadGrid from './PortfolioUploadGrid.vue'

let teardown: (() => void) | null = null

afterEach(() => {
  teardown?.()
  teardown = null
  platform.native = false
})

async function mountIt(
  component: typeof AvatarUploadDrop | typeof PortfolioUploadGrid,
  native: boolean,
) {
  platform.native = native
  const mounted = await mountAuthPage(component, { initialRoute: { path: '/' } })
  teardown = mounted.unmount
  return mounted.wrapper
}

describe('AvatarUploadDrop prompt', () => {
  it('native: "Tap to choose a photo"', async () => {
    const wrapper = await mountIt(AvatarUploadDrop, true)
    expect(wrapper.find('.avatar-upload-drop__primary').text()).toBe('Tap to choose a photo')
  })

  it('web: the drag-and-drop copy is unchanged', async () => {
    const wrapper = await mountIt(AvatarUploadDrop, false)
    expect(wrapper.find('.avatar-upload-drop__primary').text()).toBe(
      'Drop a photo here, or click to choose',
    )
  })
})

describe('PortfolioUploadGrid prompt', () => {
  it('native: "Tap to choose files"', async () => {
    const wrapper = await mountIt(PortfolioUploadGrid, true)
    expect(wrapper.find('.portfolio-upload-grid__primary').text()).toBe('Tap to choose files')
  })

  it('web: the drag-and-drop copy is unchanged', async () => {
    const wrapper = await mountIt(PortfolioUploadGrid, false)
    expect(wrapper.find('.portfolio-upload-grid__primary').text()).toBe(
      'Drop files here, or click to choose',
    )
  })
})
