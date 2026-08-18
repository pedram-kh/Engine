/**
 * `useCreatorProfile` — resolves a creator into whichever of the two payload
 * shapes this agency is actually entitled to, for `CreatorProfileDialog`
 * (AH-080, D3).
 *
 * Two resources back it, never a new one:
 *   - FULL — the roster-detail resource (`rosterApi.show`), when a relation
 *     row exists (any status, `AgencyCreatorRelationGuard::requireExisting`).
 *   - THIN — the discover public-profile resource (`discoveryApi.show`),
 *     the truthful fallback for a creator this agency has no relation with
 *     (direct-invite is first-contact-capable — §0.1 of the inventory).
 *     Structurally carries no contact / rating / notes / blacklist keys —
 *     see `CreatorPublicProfileResource.php`.
 *
 * Mode selection is try-then-fall-back, not a pre-flight check: attempt the
 * roster fetch, and ONLY on a 404 (relation genuinely absent) try discover.
 * `assumeFull` skips the fallback branch entirely — for contexts where a
 * relation is GUARANTEED (an applicant is rostered by definition, the
 * `CampaignApplicationListItemResource` identity invariant), so a caller
 * that already knows better never pays for the fallback's existence: exactly
 * one request, and a 404 there is a genuine error, not a mode signal.
 *
 * Exactly one of the two endpoints is ever awaited per `load()` call, and a
 * fallback never re-attempts the roster endpoint. Neither branch logs to the
 * console — a no-relation creator is an expected, common case, not a fault.
 * `loading` stays true for the ENTIRE resolution (including a fallback), so
 * the dialog never flickers its skeleton off between the two attempts.
 */

import type { AgencyCreatorDetailResource, CreatorPublicProfile } from '@catalyst/api-client'
import { ApiError } from '@catalyst/api-client'
import { ref, type Ref } from 'vue'

import { discoveryApi } from '@/modules/discover/api/discovery.api'
import { rosterApi } from '@/modules/roster/api/roster.api'

export type CreatorProfileLookup =
  | { mode: 'full'; data: AgencyCreatorDetailResource }
  | { mode: 'thin'; data: CreatorPublicProfile }

export interface UseCreatorProfileOptions {
  /**
   * Skip the 404-fallback branch — the relation is guaranteed to exist, so a
   * 404 here is a real error, not a mode signal. See file docblock.
   */
  assumeFull?: boolean
}

/** Discriminated so the component owns the copy (i18n), not this composable. */
export type CreatorProfileError = 'not-found' | 'load-failed'

export interface UseCreatorProfileResult {
  loading: Ref<boolean>
  profile: Ref<CreatorProfileLookup | null>
  error: Ref<CreatorProfileError | null>
  load: (agencyId: string, creatorUlid: string, options?: UseCreatorProfileOptions) => Promise<void>
}

function classify(err: unknown): CreatorProfileError {
  return err instanceof ApiError && err.status === 404 ? 'not-found' : 'load-failed'
}

function isRelationMissing(err: unknown): boolean {
  return err instanceof ApiError && err.status === 404
}

export function useCreatorProfile(): UseCreatorProfileResult {
  const loading = ref(false)
  const profile = ref<CreatorProfileLookup | null>(null)
  const error = ref<CreatorProfileError | null>(null)

  async function load(
    agencyId: string,
    creatorUlid: string,
    options: UseCreatorProfileOptions = {},
  ): Promise<void> {
    loading.value = true
    error.value = null
    profile.value = null

    try {
      const envelope = await rosterApi.show(agencyId, creatorUlid)
      profile.value = { mode: 'full', data: envelope.data }
      return
    } catch (rosterError) {
      if (options.assumeFull === true || !isRelationMissing(rosterError)) {
        error.value = classify(rosterError)
        return
      }
      // Roster 404'd and the caller did not assert a guaranteed relation —
      // the truthful fallback (D1/D3). A second failure here is a genuine
      // error (e.g. soft-deleted / no longer discoverable), not a further
      // fallback.
      try {
        const envelope = await discoveryApi.show(agencyId, creatorUlid)
        profile.value = { mode: 'thin', data: envelope.data }
      } catch (discoverError) {
        error.value = classify(discoverError)
      }
    } finally {
      loading.value = false
    }
  }

  return { loading, profile, error, load }
}
