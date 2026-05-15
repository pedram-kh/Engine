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

/**
 * Decoupling seam for the workspace-switch re-bootstrap. We can't
 * import `useAuthStore` at module level (it imports this store —
 * circular). The agency store calls auth via this contract; auth.ts
 * supplies the implementation by calling `setAuthRebootstrap()` from
 * inside its own factory.
 *
 * This indirection only exists because the chunk-6.4 split-store design
 * pushed identity into one store and tenancy context into another;
 * Sprint 4+ may unify them.
 */
interface AuthRebootstrapHook {
  /**
   * Force the next `bootstrap()` call to re-fetch `/me` (resets the
   * `'ready'` short-circuit). Implemented in `useAuthStore.ts` as
   * `bootstrapStatus.value = 'idle'`.
   */
  resetBootstrapStatus(): void
  /** Re-run the cold-load identity resolution. */
  bootstrap(): Promise<void>
  /** Indicates whether a re-bootstrap is currently in flight. */
  isBootstrapping(): boolean
}

let authRebootstrap: AuthRebootstrapHook | null = null

/**
 * Wire the auth-store's re-bootstrap hook into the agency store. Called
 * once from `useAuthStore.ts` after the store is constructed. Tests can
 * call this directly with a stub implementation.
 */
export function setAuthRebootstrap(hook: AuthRebootstrapHook | null): void {
  authRebootstrap = hook
}

export const useAgencyStore = defineStore('agency', () => {
  // ---------------------------------------------------------------
  // State
  // ---------------------------------------------------------------
  const memberships = ref<AgencyMembershipData[]>([])
  const currentAgencyId = ref<string | null>(null)
  /**
   * Sprint 3 Chunk 4 — workspace switching full UX.
   *
   * Flipped true while `switchAgency()` is awaiting the auth-store
   * re-bootstrap. Consumed by the AgencyLayout workspace-switcher
   * dropdown to show a brief loading state. Reset to false once the
   * bootstrap settles (success or failure).
   */
  const isSwitchingAgency = ref<boolean>(false)

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
   * Switch the active workspace.
   *
   * Sprint 3 Chunk 4 sub-step 5 (Decision D2=b — session-stored agency,
   * not URL-encoded). The action:
   *
   *   1. Validates the target agency is in the user's membership list
   *      (defensive — the dropdown only shows valid options, but the
   *      backend is the SOT and a tampered call must be a no-op).
   *   2. Updates the Pinia state + localStorage so the new selection
   *      survives a reload.
   *   3. Resets `bootstrapStatus` → `'idle'` on the auth store, then
   *      awaits a fresh `bootstrap()`. This re-runs the cold-load
   *      identity resolution which re-fetches the membership list,
   *      flags, tenant-scoped resources, etc. Routes / pages that
   *      were rendering under the old tenant context naturally
   *      re-evaluate.
   *   4. Tracks the in-flight state via `isSwitchingAgency` so the
   *      AgencyLayout dropdown can show a loading indicator.
   *
   * NO route navigation; the current route stays put. URL-encoded
   * agency for shareability is Sprint 4+ if a real need surfaces.
   *
   * Returns the resolved Promise so consumers can `await` the
   * completion (the layout's dropdown closes after the re-bootstrap
   * resolves to avoid a flash of stale data).
   */
  async function switchAgency(agencyId: string): Promise<void> {
    const found = memberships.value.find((m) => m.agency_id === agencyId)
    if (found === undefined) return
    if (agencyId === currentAgencyId.value) return

    currentAgencyId.value = agencyId
    localStorage.setItem(STORAGE_KEY, agencyId)

    if (authRebootstrap === null) {
      // Test-mode or pre-wire — caller didn't set the hook. Skip the
      // re-bootstrap; the agency-id is already persisted and the
      // (likely) next route navigation will pull fresh data.
      return
    }

    isSwitchingAgency.value = true
    try {
      authRebootstrap.resetBootstrapStatus()
      await authRebootstrap.bootstrap()
    } finally {
      isSwitchingAgency.value = false
    }
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
    isSwitchingAgency,
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
