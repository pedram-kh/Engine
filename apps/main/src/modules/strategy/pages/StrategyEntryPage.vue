<script setup lang="ts">
/**
 * PL-2.5 — "Strategy & Scripts" lands here and goes on to the first step that is not done, and for a per-market step
 * the first market that is not done; with all four steps done it opens Concepts. The server computes that from the REAL
 * states (`landing` in the summary). A direct address to a step (`/strategy/personas?market=…`) does not come through
 * here and opens exactly that step. The same flag rule as the workspace applies, and nothing is requested before it.
 */
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { strategyApi } from '../api/strategy.api'
import { landingRoute } from '../completionLogic'
import { useStrategyContext } from '../composables/useStrategyContext'

const router = useRouter()
const authStore = useAuthStore()
const { brandId, waveId, scope } = useStrategyContext()

onMounted(async () => {
  const f = authStore.user?.attributes.features
  if (f?.waves !== true) {
    await router.replace({ name: 'brands.detail', params: { ulid: brandId } })
    return
  }
  if (f.strategy !== true) {
    await router.replace({ name: 'waves.detail', params: { ulid: brandId, waveUlid: waveId } })
    return
  }
  const params = { ulid: brandId, waveUlid: waveId }
  const s = scope.value
  try {
    if (s === null) throw new Error('no scope')
    const r = landingRoute((await strategyApi.summary(s)).data.attributes.landing)
    await router.replace({ name: r.name, params, query: r.query })
  } catch {
    // The workspace shows its own error for a wave that is gone; USPs is where it always opened before.
    await router.replace({ name: 'strategy.usps', params })
  }
})
</script>

<template>
  <div class="agency-page agency-page--snug" data-test="strategy-entry">
    <v-skeleton-loader type="article" />
  </div>
</template>
