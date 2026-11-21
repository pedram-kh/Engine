/** PL-2.3 (G3) — every blob URL is revoked when its tile goes, when the path changes, and when a stale read finishes late. */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'

vi.mock('../api/strategy.api', () => ({ strategyApi: { imageBlob: vi.fn() } }))

import { strategyApi } from '../api/strategy.api'
import { liveBlobUrlCount, useAuthenticatedImage } from './useAuthenticatedImage'

let n = 0
const created: string[] = []
const revoked: string[] = []

const path = ref<string | null>('/a')
const Probe = defineComponent({
  setup() {
    const { url, failed } = useAuthenticatedImage(() => path.value)
    return () => h('span', { 'data-url': url.value ?? '', 'data-failed': String(failed.value) })
  },
})

describe('useAuthenticatedImage', () => {
  beforeEach(() => {
    n = 0
    created.length = 0
    revoked.length = 0
    path.value = '/a'
    URL.createObjectURL = vi.fn(() => {
      const u = `blob:test/${++n}`
      created.push(u)
      return u
    })
    URL.revokeObjectURL = vi.fn((u: string) => void revoked.push(u))
    vi.mocked(strategyApi.imageBlob).mockResolvedValue(new Blob(['x']))
  })
  afterEach(() => expect(liveBlobUrlCount()).toBe(0))

  it('creates one URL per image and revokes it when the tile is removed', async () => {
    const w = mount(Probe)
    await flushPromises()
    expect(liveBlobUrlCount()).toBe(1)
    expect(w.attributes('data-url')).toBe('blob:test/1')
    w.unmount()
    expect(revoked).toEqual(['blob:test/1'])
    expect(liveBlobUrlCount()).toBe(0)
  })

  it('a path change revokes the old URL before the new one is shown', async () => {
    const w = mount(Probe)
    await flushPromises()
    path.value = '/b'
    await nextTick()
    await flushPromises()
    expect(created).toEqual(['blob:test/1', 'blob:test/2'])
    expect(revoked).toEqual(['blob:test/1'])
    expect(liveBlobUrlCount()).toBe(1)
    w.unmount()
  })

  it('leaving the page before a read finishes leaves no URL behind', async () => {
    let release: (b: Blob) => void = () => undefined
    vi.mocked(strategyApi.imageBlob).mockReturnValue(new Promise<Blob>((r) => (release = r)))
    const w = mount(Probe)
    w.unmount()
    release(new Blob(['x']))
    await flushPromises()
    expect(created).toEqual([])
    expect(liveBlobUrlCount()).toBe(0)
  })

  it('a failed read shows the failure state and creates nothing', async () => {
    vi.mocked(strategyApi.imageBlob).mockRejectedValue(new Error('403'))
    const w = mount(Probe)
    await flushPromises()
    expect(w.attributes('data-failed')).toBe('true')
    expect(created).toEqual([])
    w.unmount()
  })
})
