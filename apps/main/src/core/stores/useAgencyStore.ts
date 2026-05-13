/**
 * Agency context store — the source of truth for "which agency the
 * authenticated user is currently operating in."
 *
 * Seeded from `useAuthStore().user.relationships.agency_memberships`
 * after a successful bootstrap. Each membership carries
 * `{agency_id, agency_name, role}`.
 *
 * Persistence contract:
 *   - `currentAgencyId` is persisted to `localStorage` under the key
 *     `catalyst.agency.current`. On re-boot, the previously selected
 *     agency is preferred (if still in the membership list).
 *   - If the stored agency is no longer in the membership list (e.g.,
 *     membership was revoked), the first available membership is used.
 *   - If there are zero memberships (creator or platform-admin user),
 *     `currentAgencyId` stays `null`.
 *
 * API surface for pages:
 *   - `currentAgencyId` — the ULID of the active agency (pass to all
 *     tenant-scoped API calls as `{agency}` path segment).
 *   - `currentAgencyName` — display name for the workspace switcher.
 *   - `currentRole` — the authenticated user's role in the active agency.
 *   - `isAdmin` — true when `currentRole === 'agency_admin'`.
 *   - `memberships` — full list (for the workspace switcher dropdown).
 *   - `initFromUser(user)` — called by the auth store / bootstrap.
 *   - `switchAgency(agencyId)` — switches the active workspace.
 */

import type { AgencyMembershipData } from '@catalyst/api-client'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

const STORAGE_KEY = 'catalyst.agency.current'

export const useAgencyStore = defineStore('agency', () => {
  // ---------------------------------------------------------------
  // State
  // ---------------------------------------------------------------
  const memberships = ref<AgencyMembershipData[]>([])
  const currentAgencyId = ref<string | null>(null)

  // ---------------------------------------------------------------
  // Getters
  // ---------------------------------------------------------------
  const currentMembership = computed(
    () => memberships.value.find((m) => m.agency_id === currentAgencyId.value) ?? null,
  )

  const currentAgencyName = computed(() => currentMembership.value?.agency_name ?? '')

  const currentRole = computed(() => currentMembership.value?.role ?? null)

  const isAdmin = computed(() => currentRole.value === 'agency_admin')

  // ---------------------------------------------------------------
  // Actions
  // ---------------------------------------------------------------

  /**
   * Seed or re-seed the store from the `/me` response.
   * Called immediately after a successful bootstrap or login.
   */
  function initFromUser(userMemberships: AgencyMembershipData[]): void {
    memberships.value = userMemberships

    if (userMemberships.length === 0) {
      currentAgencyId.value = null
      return
    }

    // Re-hydrate the previously selected agency from localStorage.
    const stored = localStorage.getItem(STORAGE_KEY)
    const isStoredStillValid =
      stored !== null && userMemberships.some((m) => m.agency_id === stored)

    if (isStoredStillValid) {
      currentAgencyId.value = stored
    } else {
      // Fall back to the first membership and persist it.
      const first = userMemberships[0]
      if (first !== undefined) {
        currentAgencyId.value = first.agency_id
        localStorage.setItem(STORAGE_KEY, first.agency_id)
      }
    }
  }

  /**
   * Switch the active workspace. Persists the new selection.
   * No-op if `agencyId` is not in the current membership list.
   */
  function switchAgency(agencyId: string): void {
    const found = memberships.value.find((m) => m.agency_id === agencyId)
    if (found === undefined) return
    currentAgencyId.value = agencyId
    localStorage.setItem(STORAGE_KEY, agencyId)
  }

  /** Reset to empty state (called on logout). */
  function reset(): void {
    memberships.value = []
    currentAgencyId.value = null
    localStorage.removeItem(STORAGE_KEY)
  }

  return {
    // state
    memberships,
    currentAgencyId,
    // getters
    currentMembership,
    currentAgencyName,
    currentRole,
    isAdmin,
    // actions
    initFromUser,
    switchAgency,
    reset,
  }
})
