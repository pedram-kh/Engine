/**
 * Sidebar badge counts (Sprint 13, D-1 / D-4 / D-7).
 *
 * The sidebar shows live counts on the creator-approvals and KYC-queue
 * leaves (`docs/09-ADMIN-PANEL.md` § 5.3). Those counts come from the
 * dashboard stats endpoint (D-7), so the store is the single shared
 * source the layout reads and the dashboard refreshes — no prop-drilling
 * a count down through the nav model.
 *
 * Counts default to `0` (no badge rendered) until the first refresh.
 * Sprint 13 wires the refresh from the dashboard load; the store stays
 * decoupled from any specific page so future surfaces can refresh it too.
 */

import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useNavBadges = defineStore('navBadges', () => {
  const creatorApprovals = ref(0)
  const kycQueue = ref(0)

  function setCounts(counts: { creatorApprovals?: number; kycQueue?: number }): void {
    if (counts.creatorApprovals !== undefined) {
      creatorApprovals.value = counts.creatorApprovals
    }
    if (counts.kycQueue !== undefined) {
      kycQueue.value = counts.kycQueue
    }
  }

  function reset(): void {
    creatorApprovals.value = 0
    kycQueue.value = 0
  }

  return { creatorApprovals, kycQueue, setCounts, reset }
})
