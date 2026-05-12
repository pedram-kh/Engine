import { describe, expect, it, vi, afterEach, beforeEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import RecoveryCodesDisplay from './RecoveryCodesDisplay.vue'

/**
 * Mirror of `apps/main/src/modules/auth/components/RecoveryCodesDisplay.spec.ts`
 * (chunk 6.7) with admin's i18n strings substituted ("Save your admin
 * recovery codes" via `auth.ui.headings.recovery_codes`) and the
 * download filename adjusted to `catalyst-admin-recovery-codes.txt`.
 * Same invariants: countdown gate enforced, codes flow in via prop
 * only (never store), `confirmed` emit on click after countdown.
 */

const CODES = ['code-1', 'code-2', 'code-3'] as const

describe('RecoveryCodesDisplay', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
    vi.useRealTimers()
  })

  it('renders the heading + warning + every code on a separate line', async () => {
    const h = await mountAuthPage(RecoveryCodesDisplay, {
      props: { codes: CODES },
    })
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="recovery-codes-heading"]').text()).toBe(
      'Save your admin recovery codes',
    )
    expect(h.wrapper.find('[data-test="recovery-codes-warning"]').text()).toContain(
      'Save these one-time codes',
    )
    expect(h.wrapper.find('[data-test="recovery-codes-list"]').text()).toBe(
      'code-1\ncode-2\ncode-3',
    )
  })

  it('confirm button starts disabled (countdown active)', async () => {
    const h = await mountAuthPage(RecoveryCodesDisplay, { props: { codes: CODES } })
    teardown = h.unmount
    const button = h.wrapper.find('[data-test="recovery-codes-confirm"]')
    expect(button.attributes('disabled')).toBeDefined()
  })

  it('countdown announcement uses aria-live="polite"', async () => {
    const h = await mountAuthPage(RecoveryCodesDisplay, { props: { codes: CODES } })
    teardown = h.unmount
    const announcer = h.wrapper.find('[data-test="recovery-codes-countdown"]')
    expect(announcer.attributes('aria-live')).toBe('polite')
    expect(announcer.attributes('role')).toBe('status')
  })

  it('countdown text starts at the configured seconds and decrements every second', async () => {
    const h = await mountAuthPage(RecoveryCodesDisplay, {
      props: { codes: CODES, countdownSeconds: 3 },
    })
    teardown = h.unmount

    expect(h.wrapper.find('[data-test="recovery-codes-countdown"]').text()).toContain('3 second')

    await vi.advanceTimersByTimeAsync(1000)
    await h.wrapper.vm.$nextTick()
    expect(h.wrapper.find('[data-test="recovery-codes-countdown"]').text()).toContain('2 second')

    await vi.advanceTimersByTimeAsync(1000)
    await h.wrapper.vm.$nextTick()
    expect(h.wrapper.find('[data-test="recovery-codes-countdown"]').text()).toContain('1 second')
  })

  it('confirm button enables after the countdown elapses and emits "confirmed" on click', async () => {
    const h = await mountAuthPage(RecoveryCodesDisplay, {
      props: { codes: CODES, countdownSeconds: 2 },
    })
    teardown = h.unmount
    await vi.advanceTimersByTimeAsync(2000)
    await h.wrapper.vm.$nextTick()
    expect(
      h.wrapper.find('[data-test="recovery-codes-confirm"]').attributes('disabled'),
    ).toBeUndefined()
    expect(h.wrapper.find('[data-test="recovery-codes-countdown"]').text()).toContain(
      'You can confirm now',
    )
    await h.wrapper.find('[data-test="recovery-codes-confirm"]').trigger('click')
    expect(h.wrapper.emitted('confirmed')).toHaveLength(1)
  })

  it('clicking confirm BEFORE the countdown elapses is a no-op (chunk-6.7 invariant)', async () => {
    const h = await mountAuthPage(RecoveryCodesDisplay, {
      props: { codes: CODES, countdownSeconds: 5 },
    })
    teardown = h.unmount
    const inner = h.wrapper.find('[data-test="recovery-codes-confirm"] button')
    if (inner.exists()) {
      await inner.trigger('click')
    } else {
      await h.wrapper.find('[data-test="recovery-codes-confirm"]').trigger('click')
    }
    expect(h.wrapper.emitted('confirmed')).toBeUndefined()
  })

  it('copy button writes the codes (joined by newlines) to the clipboard', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(globalThis.navigator, 'clipboard', {
      configurable: true,
      value: { writeText },
    })

    const h = await mountAuthPage(RecoveryCodesDisplay, { props: { codes: CODES } })
    teardown = h.unmount
    await h.wrapper.find('[data-test="recovery-codes-copy"]').trigger('click')
    await flushPromises()
    expect(writeText).toHaveBeenCalledWith('code-1\ncode-2\ncode-3')
  })

  it('copy button is a no-op when clipboard API is unavailable', async () => {
    Object.defineProperty(globalThis.navigator, 'clipboard', {
      configurable: true,
      value: undefined,
    })
    const h = await mountAuthPage(RecoveryCodesDisplay, { props: { codes: CODES } })
    teardown = h.unmount
    // Should not throw.
    await h.wrapper.find('[data-test="recovery-codes-copy"]').trigger('click')
    await flushPromises()
  })

  it('download button creates a Blob URL, triggers a download, and revokes the URL', async () => {
    const createObjectURL = vi.fn(() => 'blob:fake')
    const revokeObjectURL = vi.fn()
    Object.defineProperty(globalThis.URL, 'createObjectURL', {
      configurable: true,
      value: createObjectURL,
    })
    Object.defineProperty(globalThis.URL, 'revokeObjectURL', {
      configurable: true,
      value: revokeObjectURL,
    })

    const h = await mountAuthPage(RecoveryCodesDisplay, { props: { codes: CODES } })
    teardown = h.unmount
    await h.wrapper.find('[data-test="recovery-codes-download"]').trigger('click')
    expect(createObjectURL).toHaveBeenCalledTimes(1)
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:fake')
  })

  it('cleans up the interval on unmount (memory-leak guard)', async () => {
    const clearSpy = vi.spyOn(globalThis, 'clearInterval')
    const h = await mountAuthPage(RecoveryCodesDisplay, {
      props: { codes: CODES, countdownSeconds: 5 },
    })
    h.unmount()
    teardown = null
    expect(clearSpy).toHaveBeenCalled()
  })

  it('clears the interval as soon as the countdown reaches zero (no zombie timers)', async () => {
    const clearSpy = vi.spyOn(globalThis, 'clearInterval')
    const h = await mountAuthPage(RecoveryCodesDisplay, {
      props: { codes: CODES, countdownSeconds: 1 },
    })
    teardown = h.unmount
    await vi.advanceTimersByTimeAsync(1000)
    await h.wrapper.vm.$nextTick()
    expect(clearSpy).toHaveBeenCalled()
  })
})
