/**
 * PL-2.1 — where a strategy page is: the agency from the store, the brand and wave from the route. Every step and
 * dialog builds its API scope from this one place, so no component reads an id from anywhere else.
 */
import { computed, type ComputedRef } from 'vue'
import { useRoute } from 'vue-router'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import type { WaveScope } from '../api/strategy.api'

export interface StrategyContext {
  brandId: string
  waveId: string
  scope: ComputedRef<WaveScope | null>
  /** Admin or manager. Staff can do everything except delete a USP that personas use. */
  canDeleteInUse: ComputedRef<boolean>
  /** PL-2.3: admin or manager. Records a client's decision and deletes a concept that is Approved or Changes requested. */
  canDecide: ComputedRef<boolean>
}

export function useStrategyContext(): StrategyContext {
  const route = useRoute()
  const agencyStore = useAgencyStore()
  const brandId = route.params.ulid as string
  const waveId = route.params.waveUlid as string

  const scope = computed<WaveScope | null>(() =>
    agencyStore.currentAgencyId === null
      ? null
      : { agencyId: agencyStore.currentAgencyId, brandId, waveId },
  )
  const canDeleteInUse = computed(
    () =>
      agencyStore.currentRole === 'agency_admin' || agencyStore.currentRole === 'agency_manager',
  )
  return { brandId, waveId, scope, canDeleteInUse, canDecide: canDeleteInUse }
}
