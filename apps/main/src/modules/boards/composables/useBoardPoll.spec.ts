/**
 * useBoardPoll (Sprint 12 Chunk 2, D-4). Pins the 30s reschedule loop, the
 * stop() cleanup contract, and the onBeforeUnmount discipline (the poll must
 * stop when the board tab unmounts — Q3, no background polling).
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, onMounted } from 'vue'

import { BOARD_POLL_INTERVAL_MS, useBoardPoll } from './useBoardPoll'

describe('useBoardPoll', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it("ticks every 30s after start (the initial load is the caller's, not the poll's)", async () => {
    const tick = vi.fn().mockResolvedValue(undefined)
    const poll = useBoardPoll(tick)

    poll.start()
    // No immediate tick — the first one fires at +30s.
    expect(tick).toHaveBeenCalledTimes(0)

    await vi.advanceTimersByTimeAsync(BOARD_POLL_INTERVAL_MS)
    expect(tick).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(BOARD_POLL_INTERVAL_MS)
    expect(tick).toHaveBeenCalledTimes(2)

    poll.stop()
  })

  it('stops cleanly — no further ticks fire after stop()', async () => {
    const tick = vi.fn().mockResolvedValue(undefined)
    const poll = useBoardPoll(tick)

    poll.start()
    await vi.advanceTimersByTimeAsync(BOARD_POLL_INTERVAL_MS)
    expect(tick).toHaveBeenCalledTimes(1)

    poll.stop()
    await vi.advanceTimersByTimeAsync(BOARD_POLL_INTERVAL_MS * 3)
    expect(tick).toHaveBeenCalledTimes(1)
  })

  it('stops the poll on component unmount (onBeforeUnmount — no background polling, Q3)', async () => {
    const tick = vi.fn().mockResolvedValue(undefined)

    const Host = defineComponent({
      setup() {
        const poll = useBoardPoll(tick)
        onMounted(() => poll.start())
        return () => h('div')
      },
    })

    const wrapper = mount(Host)
    await vi.advanceTimersByTimeAsync(BOARD_POLL_INTERVAL_MS)
    expect(tick).toHaveBeenCalledTimes(1)

    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(BOARD_POLL_INTERVAL_MS * 3)
    // The onBeforeUnmount stop() held — the loop did not survive the tab leave.
    expect(tick).toHaveBeenCalledTimes(1)
  })
})
