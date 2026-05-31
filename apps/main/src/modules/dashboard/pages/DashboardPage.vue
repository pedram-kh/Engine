<script setup lang="ts">
/**
 * DashboardPage — the real agency workspace home (Sprint 4 Chunk 1),
 * replacing the chunk-6.5 `DashboardPlaceholderPage` at `/`.
 *
 * Layout (D-c1-3 — single column, adapting §11's two-column intent while
 * campaigns/deadlines don't exist):
 *   - Welcome bar (name + date + aurora edge).
 *   - KPI strip (four cards, locked order D-c1-4).
 *   - Activity feed region (single column). 1b renders a `CEmptyState`
 *     placeholder; 1c replaces it with the real `ActivityFeed` (which keeps
 *     `CEmptyState` as its own zero-rows fallback).
 *
 * No FAB (D-c1-2), no right column (D-c1-3).
 *
 * Data pattern (A8 house pattern): component-local refs + the thin
 * `dashboard.api.ts` — there is NO data Pinia store. Tenancy comes from
 * `useAgencyStore.currentAgencyId`; `onMounted` + `watch(currentAgencyId)`
 * (re)load, mirroring `BrandListPage`.
 */

import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { useAgencyStore } from '@/core/stores/useAgencyStore'

import { dashboardApi, type DashboardSummary } from '../api/dashboard.api'
import ActivityFeed from '../components/ActivityFeed.vue'
import KpiStrip from '../components/KpiStrip.vue'
import WelcomeBar from '../components/WelcomeBar.vue'

const { t } = useI18n()
const agencyStore = useAgencyStore()

const summary = ref<DashboardSummary | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

async function loadSummary(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  loading.value = true
  error.value = null

  try {
    const res = await dashboardApi.summary(agencyId)
    summary.value = res.data
  } catch {
    error.value = t('dashboard.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void loadSummary()
})

// Reload whenever the active agency changes (workspace switch, or async
// store init where currentAgencyId is null on first mount).
watch(
  () => agencyStore.currentAgencyId,
  (id) => {
    if (id !== null) void loadSummary()
  },
)
</script>

<template>
  <div data-test="dashboard-page">
    <WelcomeBar />

    <KpiStrip :summary="summary" :loading="loading" class="mt-6" />

    <v-alert v-if="error" type="error" variant="tonal" class="mt-6" data-test="dashboard-error">
      {{ error }}
    </v-alert>

    <section class="mt-8" data-test="dashboard-activity">
      <h2 class="text-h6 mb-3">{{ t('dashboard.activity.title') }}</h2>
      <ActivityFeed />
    </section>
  </div>
</template>
