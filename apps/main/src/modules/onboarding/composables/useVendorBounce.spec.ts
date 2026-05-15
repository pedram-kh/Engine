import { setActivePinia, createPinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    pollKycStatus: vi.fn(),
    pollContractStatus: vi.fn(),
    pollPayoutStatus: vi.fn(),
  },
}))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import { useVendorBounce, VENDOR_BOUNCE_MAX_POLLS } from './useVendorBounce'

beforeEach(() => {
  setActivePinia(createPinia())
  vi.useFakeTimers()
  vi.clearAllMocks()
  // Default bootstrap mock for the store's terminal-state refresh.
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue({
    data: {} as never,
  })
})

afterEach(() => {
  vi.useRealTimers()
})

async function flushTimersOnce(ms: number): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms)
}

describe('useVendorBounce', () => {
  it('starts in idle and flips to waiting on start()', async () => {
    vi.mocked(onboardingApi.pollKycStatus).mockImplementation(
      () => new Promise(() => {}), // never resolves; keep status=waiting
    )
    const bounce = useVendorBounce('kyc')
    expect(bounce.status.value).toBe('idle')
    void bounce.start()
    expect(bounce.status.value).toBe('waiting')
    bounce.cancel()
  })

  it('transitions to succeeded when the saga reaches a terminal success', async () => {
    vi.mocked(onboardingApi.pollKycStatus).mockResolvedValue({
      data: { status: 'verified', transitioned: true },
    })

    const bounce = useVendorBounce('kyc')
    await bounce.start()
    await vi.runAllTimersAsync()

    expect(bounce.status.value).toBe('succeeded')
  })

  it('transitions to failed on a terminal failure', async () => {
    vi.mocked(onboardingApi.pollKycStatus).mockResolvedValue({
      data: { status: 'rejected', transitioned: true },
    })

    const bounce = useVendorBounce('kyc')
    await bounce.start()
    await vi.runAllTimersAsync()

    expect(bounce.status.value).toBe('failed')
    expect(bounce.errorKey.value).toBe('creator.ui.wizard.vendor_bounce.timeout_description')
  })

  it('hits the poll cap and surfaces timeout', async () => {
    vi.mocked(onboardingApi.pollKycStatus).mockResolvedValue({
      data: { status: 'pending', transitioned: false },
    })

    const bounce = useVendorBounce('kyc')
    await bounce.start()
    // Let the scheduler run through every poll iteration. The
    // backoff is exponential up to ~8s per tick; runAllTimersAsync
    // drains every queued timer until the queue is empty.
    await vi.runAllTimersAsync()

    expect(bounce.status.value).toBe('timeout')
    expect(bounce.pollCount.value).toBe(VENDOR_BOUNCE_MAX_POLLS)
  })

  it('routes contract polls through pollContractStatus', async () => {
    vi.mocked(onboardingApi.pollContractStatus).mockResolvedValue({
      data: { status: 'succeeded', transitioned: true },
    })

    const bounce = useVendorBounce('contract')
    await bounce.start()
    await vi.runAllTimersAsync()

    expect(onboardingApi.pollContractStatus).toHaveBeenCalled()
    expect(bounce.status.value).toBe('succeeded')
  })

  it('routes payout polls through pollPayoutStatus', async () => {
    vi.mocked(onboardingApi.pollPayoutStatus).mockResolvedValue({
      data: { status: 'succeeded', transitioned: true },
    })

    const bounce = useVendorBounce('payout')
    await bounce.start()
    await vi.runAllTimersAsync()

    expect(onboardingApi.pollPayoutStatus).toHaveBeenCalled()
    expect(bounce.status.value).toBe('succeeded')
  })

  it('cancel() stops the polling loop', async () => {
    vi.mocked(onboardingApi.pollKycStatus).mockResolvedValue({
      data: { status: 'pending', transitioned: false },
    })

    const bounce = useVendorBounce('kyc')
    await bounce.start()
    await flushTimersOnce(0)
    bounce.cancel()

    const callCountBefore = vi.mocked(onboardingApi.pollKycStatus).mock.calls.length
    await vi.runAllTimersAsync()
    const callCountAfter = vi.mocked(onboardingApi.pollKycStatus).mock.calls.length
    expect(callCountAfter).toBe(callCountBefore)
    expect(bounce.status.value).toBe('idle')
  })

  it('handles transient errors by staying in waiting and retrying', async () => {
    // First call rejects; second resolves to a terminal success.
    vi.mocked(onboardingApi.pollKycStatus)
      .mockRejectedValueOnce(new Error('transient'))
      .mockResolvedValueOnce({
        data: { status: 'verified', transitioned: true },
      })

    const bounce = useVendorBounce('kyc')
    await bounce.start()
    await vi.runAllTimersAsync()

    expect(bounce.status.value).toBe('succeeded')
  })

  it('exposes isPolling as a computed mirror of status', async () => {
    vi.mocked(onboardingApi.pollKycStatus).mockImplementation(() => new Promise(() => {}))
    const bounce = useVendorBounce('kyc')
    expect(bounce.isPolling.value).toBe(false)
    void bounce.start()
    expect(bounce.isPolling.value).toBe(true)
    bounce.cancel()
  })

  it('does not refresh the store again — the store action already refreshed on transition', async () => {
    vi.mocked(onboardingApi.pollKycStatus).mockResolvedValue({
      data: { status: 'verified', transitioned: true },
    })
    const store = useOnboardingStore()
    const bootstrapSpy = vi.spyOn(store, 'bootstrap')

    const bounce = useVendorBounce('kyc')
    await bounce.start()
    await vi.runAllTimersAsync()

    // The store's pollKycStatus action internally calls bootstrap
    // exactly once on the transition edge; useVendorBounce does not
    // call it again. (Both calls happen inside the store action.)
    expect(bootstrapSpy.mock.calls.length).toBeLessThanOrEqual(1)
  })
})
