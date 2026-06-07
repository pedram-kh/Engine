/**
 * Tracks the active impersonation for the main SPA (Sprint 13, D-10).
 *
 * Source of truth is always the SERVER — this store is a thin display
 * cache that drives the persistent banner + advisory countdown. It is
 * hydrated three ways:
 *   - `setActive()` right after a successful claim, and
 *   - `hydrate()` on cold load (a refresh / fresh tab) via the status
 *     endpoint, so the banner survives reloads.
 *   - `clear()` after an explicit end (or a server 401 tearing the
 *     session down).
 *
 * The TTL shown is advisory only: the EnforceImpersonation middleware is
 * the authoritative clock and will reject an expired session server-side
 * regardless of what this countdown displays.
 */

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { ApiError } from '@catalyst/api-client'

import { impersonationApi } from '../api/impersonation.api'

export const useImpersonationStore = defineStore('impersonation', () => {
  const active = ref(false)
  const expiresAt = ref<string | null>(null)
  const isEnding = ref(false)

  const expiresAtMs = computed<number | null>(() =>
    expiresAt.value === null ? null : new Date(expiresAt.value).getTime(),
  )

  function setActive(nextExpiresAt: string): void {
    active.value = true
    expiresAt.value = nextExpiresAt
  }

  function clear(): void {
    active.value = false
    expiresAt.value = null
  }

  /**
   * Cold-load hydration. Best-effort by design: the banner is a cosmetic
   * reminder, never an authorization control (the EnforceImpersonation
   * middleware is the real gate), so a failed status probe — the
   * anonymous-session 401, a transient network error, anything — must
   * leave the banner OFF and never disturb app bootstrap. It therefore
   * swallows every error rather than propagating.
   */
  async function hydrate(): Promise<void> {
    try {
      const res = await impersonationApi.status()
      if (res.data.active && res.data.expires_at !== undefined) {
        setActive(res.data.expires_at)
      } else {
        clear()
      }
    } catch {
      clear()
    }
  }

  async function end(): Promise<void> {
    isEnding.value = true
    try {
      await impersonationApi.end()
    } catch (error) {
      // A 401 means the session was already torn down server-side — the
      // end still succeeded from the user's point of view.
      if (!(error instanceof ApiError) || error.status !== 401) {
        throw error
      }
    } finally {
      clear()
      isEnding.value = false
    }
  }

  return {
    active,
    expiresAt,
    expiresAtMs,
    isEnding,
    setActive,
    clear,
    hydrate,
    end,
  }
})
