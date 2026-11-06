/**
 * AH-119 Stage B — whether message edit and delete are on for this session: the server-resolved
 * `/me` switch `features.messages_v2_edit_search`. Off (or no Pinia, as in a bare component test)
 * the thread views render exactly what they rendered before Stage B.
 */

import { getActivePinia } from 'pinia'
import { computed, type ComputedRef } from 'vue'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

export function useMessagesEditSearchFlag(): ComputedRef<boolean> {
  if (getActivePinia() === undefined) {
    return computed(() => false)
  }
  const authStore = useAuthStore()
  return computed(() => authStore.user?.attributes.features?.messages_v2_edit_search === true)
}
