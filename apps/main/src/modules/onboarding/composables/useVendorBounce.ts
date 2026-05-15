/**
 * Vendor-bounce polling composable (Sprint 3 Chunk 3 sub-step 4).
 *
 * The three vendor-gated wizard steps (KYC, contract, payout) all
 * follow the same shape:
 *
 *   1. Wizard POSTs to /initiate which returns a hosted-flow URL.
 *   2. SPA redirects the creator to the hosted URL.
 *   3. Creator completes (or cancels) at the vendor; the vendor
 *      redirects back to /wizard/{step}/return on the platform.
 *   4. The return-route + the SPA's mounted polling loop both call
 *      /wizard/{step}/status, which is idempotent and returns
 *      `{status: 'pending'|'succeeded'|'failed', transitioned: bool}`.
 *
 * This composable owns step (4): when the creator lands back on
 * the wizard after the vendor bounce, the consumer page mounts
 * this composable and the polling loop drives the visible status:
 *
 *   - 'waiting'   — polling, no terminal state yet.
 *   - 'succeeded' — backend reported the saga completed; the
 *                   consumer refreshes the store and advances.
 *   - 'failed'    — backend reported the saga failed; the consumer
 *                   surfaces the retry CTA.
 *   - 'timeout'   — polled MAX_POLLS times without a terminal
 *                   state; consumer surfaces the "still waiting"
 *                   CTA with retry + contact-support buttons.
 *
 * The poll interval doubles after each failure (exponential backoff)
 * to keep load bounded if the vendor is slow. Total wall-clock
 * is capped at ~60 s for the saga; if we hit the cap we surface
 * 'timeout' rather than block the wizard.
 *
 * Decisions:
 *   - Two-state UI (waiting + timeout) per Q-vendor-bounce-1 = (a).
 *   - The composable is poll-only — it does NOT trigger the initial
 *     /initiate call. That's the consumer's responsibility (the
 *     step page calls `store.initiateKyc()` etc on user action).
 *   - Cancellation: when the component unmounts, the poll loop
 *     terminates on the next tick. No abort signal needed.
 *
 * #40 break-revert: temporarily set MAX_POLLS=1 and confirm the
 * "completes within poll budget" spec fails with status='timeout'.
 */

import { computed, getCurrentInstance, onBeforeUnmount, ref, type ComputedRef, type Ref } from 'vue'

import { useOnboardingStore } from '../stores/useOnboardingStore'

export type VendorBounceTarget = 'kyc' | 'contract' | 'payout'

export type VendorBounceStatus = 'idle' | 'waiting' | 'succeeded' | 'failed' | 'timeout'

export interface VendorBounceHandle {
  status: Ref<VendorBounceStatus>
  pollCount: Ref<number>
  isPolling: ComputedRef<boolean>
  errorKey: Ref<string | null>
  start: () => Promise<void>
  cancel: () => void
}

export const VENDOR_BOUNCE_INITIAL_DELAY_MS = 1500
export const VENDOR_BOUNCE_MAX_DELAY_MS = 8000
export const VENDOR_BOUNCE_BACKOFF = 1.5
export const VENDOR_BOUNCE_MAX_POLLS = 12

interface PollResult {
  status: 'pending' | 'succeeded' | 'failed' | string
  transitioned?: boolean
}

export function useVendorBounce(target: VendorBounceTarget): VendorBounceHandle {
  const store = useOnboardingStore()
  const status = ref<VendorBounceStatus>('idle')
  const pollCount = ref(0)
  const errorKey = ref<string | null>(null)
  const isPolling = computed(() => status.value === 'waiting')

  let cancelled = false
  let timeoutHandle: ReturnType<typeof setTimeout> | null = null

  function pollOnce(): Promise<PollResult> {
    if (target === 'kyc') return store.pollKycStatus()
    if (target === 'contract') return store.pollContractStatus()
    return store.pollPayoutStatus()
  }

  function clearPendingPoll(): void {
    if (timeoutHandle !== null) {
      clearTimeout(timeoutHandle)
      timeoutHandle = null
    }
  }

  function cancel(): void {
    cancelled = true
    clearPendingPoll()
    if (status.value === 'waiting') {
      status.value = 'idle'
    }
  }

  async function start(): Promise<void> {
    cancelled = false
    pollCount.value = 0
    errorKey.value = null
    status.value = 'waiting'
    await loop(VENDOR_BOUNCE_INITIAL_DELAY_MS)
  }

  async function loop(delayMs: number): Promise<void> {
    if (cancelled) return

    try {
      const result = await pollOnce()
      if (cancelled) return

      pollCount.value += 1

      // Apply terminal-state transitions. The KYC status string
      // matches the backend's CreatorKycStatus enum verbatim;
      // payout + contract return 'succeeded' on success and the
      // store already refreshed creator state inside the action.
      if (result.status === 'verified' || result.status === 'succeeded') {
        status.value = 'succeeded'
        return
      }
      if (
        result.status === 'failed' ||
        result.status === 'rejected' ||
        result.status === 'expired'
      ) {
        status.value = 'failed'
        errorKey.value = 'creator.ui.wizard.vendor_bounce.timeout_description'
        return
      }
      if (pollCount.value >= VENDOR_BOUNCE_MAX_POLLS) {
        status.value = 'timeout'
        return
      }
    } catch {
      errorKey.value = 'creator.ui.wizard.vendor_bounce.timeout_description'
      // Stay in 'waiting' and retry — transient network errors
      // shouldn't terminate the saga.
    }

    // Schedule next poll with bounded exponential backoff.
    const nextDelay = Math.min(delayMs * VENDOR_BOUNCE_BACKOFF, VENDOR_BOUNCE_MAX_DELAY_MS)
    timeoutHandle = setTimeout(() => {
      void loop(nextDelay)
    }, delayMs)
  }

  if (getCurrentInstance() !== null) {
    onBeforeUnmount(() => {
      cancel()
    })
  }

  return {
    status,
    pollCount,
    isPolling,
    errorKey,
    start,
    cancel,
  }
}
