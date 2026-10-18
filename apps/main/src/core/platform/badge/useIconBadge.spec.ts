import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, nextTick, ref } from 'vue'

import { createIconBadge, type CatalystBadgePlugin } from './iconBadge'
import { useIconBadge } from './useIconBadge'

/** The one host component: runs the composable with whatever the test hands it. */
const Host = defineComponent({
  props: {
    poll: { type: Object, required: true },
    options: { type: Object, default: () => ({}) },
  },
  setup(props) {
    useIconBadge(props.poll as never, props.options as never)
    return () => null
  },
})

function harness(options: { ios?: boolean } = {}) {
  const set = vi.fn<CatalystBadgePlugin['set']>().mockResolvedValue({ applied: true, count: 0 })
  const badge = createIconBadge({ isIos: () => options.ios ?? true, plugin: () => ({ set }) })
  const unreadCount = ref(0)
  const hasLoaded = ref(false)
  const listeners = new Set<() => void>()
  const doc = {
    visibilityState: 'visible' as DocumentVisibilityState,
    addEventListener: vi.fn((_t: string, fn: () => void) => listeners.add(fn)),
    removeEventListener: vi.fn((_t: string, fn: () => void) => listeners.delete(fn)),
  }

  const wrapper = mount(Host, {
    props: { poll: { unreadCount, hasLoaded }, options: { badge, document: doc } },
  })

  return {
    set,
    badge,
    unreadCount,
    hasLoaded,
    doc,
    wrapper,
    resume: () => {
      for (const fn of listeners) fn()
    },
  }
}

describe('useIconBadge (AH-108 4b)', () => {
  it('writes NOTHING before the poll has read a real count (a cold-start 0 must not wipe a pushed badge)', async () => {
    const h = harness()
    await nextTick()

    expect(h.set).not.toHaveBeenCalled()
    h.wrapper.unmount()
  })

  it('writes the bell total once the first count has loaded, including a real 0', async () => {
    const h = harness()

    h.unreadCount.value = 0
    h.hasLoaded.value = true
    await nextTick()
    expect(h.set).toHaveBeenCalledTimes(1)
    expect(h.set).toHaveBeenCalledWith({ count: 0 })

    h.wrapper.unmount()
  })

  it('follows every change in the count (a poll, a thread opened, read-all)', async () => {
    const h = harness()
    h.hasLoaded.value = true
    h.unreadCount.value = 5
    await nextTick()
    h.unreadCount.value = 2
    await nextTick()
    h.unreadCount.value = 0
    await nextTick()

    expect(h.set.mock.calls.map(([o]) => o.count)).toEqual([5, 2, 0])
    h.wrapper.unmount()
  })

  it('on return to the foreground, forces a write of the current number even when unchanged', async () => {
    const h = harness()
    h.hasLoaded.value = true
    h.unreadCount.value = 3
    await nextTick()
    h.set.mockClear()

    h.resume()
    await nextTick()

    expect(h.set).toHaveBeenCalledTimes(1)
    expect(h.set).toHaveBeenCalledWith({ count: 3 })
    h.wrapper.unmount()
  })

  it('does not write on resume before the first count has loaded', async () => {
    const h = harness()

    h.resume()
    await nextTick()

    expect(h.set).not.toHaveBeenCalled()
    h.wrapper.unmount()
  })

  it('ignores a "hidden" visibility change', async () => {
    const h = harness()
    h.hasLoaded.value = true
    h.unreadCount.value = 3
    await nextTick()
    h.set.mockClear()

    h.doc.visibilityState = 'hidden'
    h.resume()
    await nextTick()

    expect(h.set).not.toHaveBeenCalled()
    h.wrapper.unmount()
  })

  it('does nothing off native iOS (web and Android never call the plugin)', async () => {
    const h = harness({ ios: false })
    h.hasLoaded.value = true
    h.unreadCount.value = 8
    await nextTick()
    h.resume()
    await nextTick()

    expect(h.set).not.toHaveBeenCalled()
    h.wrapper.unmount()
  })

  it('re-enables a badge that a previous session cleared, as the new shell mounts', async () => {
    const set = vi.fn<CatalystBadgePlugin['set']>().mockResolvedValue({ applied: true, count: 0 })
    const badge = createIconBadge({ isIos: () => true, plugin: () => ({ set }) })
    await badge.clear()
    set.mockClear()

    const unreadCount = ref(4)
    const hasLoaded = ref(true)
    const wrapper = mount(Host, { props: { poll: { unreadCount, hasLoaded }, options: { badge } } })
    await nextTick()

    expect(set).toHaveBeenCalledTimes(1)
    expect(set).toHaveBeenCalledWith({ count: 4 })
    wrapper.unmount()
  })

  it('stops on unmount: no more writes, and the visibility listener is removed', async () => {
    const h = harness()
    h.hasLoaded.value = true
    h.unreadCount.value = 1
    await nextTick()
    h.set.mockClear()

    h.wrapper.unmount()
    h.unreadCount.value = 9
    await nextTick()

    expect(h.set).not.toHaveBeenCalled()
    expect(h.doc.removeEventListener).toHaveBeenCalledTimes(1)
  })
})
