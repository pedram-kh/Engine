<script setup lang="ts">
/**
 * DashboardPage — the real agency workspace home (Sprint 4 Chunk 1),
 * replacing the chunk-6.5 `DashboardPlaceholderPage` at `/`.
 *
 * Layout (D-c1-3 — single column, adapting §11's two-column intent while
 * campaigns/deadlines don't exist):
 *   - Welcome bar (name + date + aurora edge).
 *   - KPI strip (locked order D-c1-4; the Payments due card is drawn only once the API
 *     reports a number for it, AH-116).
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

import { campaignsApi } from '@/modules/campaigns/api/campaigns.api'

import { dashboardApi, type DashboardSummary } from '../api/dashboard.api'
import ActivityFeed from '../components/ActivityFeed.vue'
import KpiStrip from '../components/KpiStrip.vue'
import WelcomeBar from '../components/WelcomeBar.vue'

const { t } = useI18n()
const agencyStore = useAgencyStore()

const summary = ref<DashboardSummary | null>(null)
// AH-116 — the Active campaigns figure comes from the campaigns list's `meta.status_counts`
// (one row asked for, only the meta is read). Null when the call fails or the API sends no
// counts; the card then draws its muted placeholder and the other cards are unaffected.
const activeCampaigns = ref<number | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

async function loadSummary(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  loading.value = true
  error.value = null

  // The two calls run side by side and fail apart: the list is only the Active campaigns card.
  const [summaryResult, campaignsResult] = await Promise.allSettled([
    dashboardApi.summary(agencyId),
    campaignsApi.list(agencyId, { per_page: 1 }),
  ])

  if (summaryResult.status === 'fulfilled') {
    summary.value = summaryResult.value.data
  } else {
    error.value = t('dashboard.errors.loadFailed')
  }
  activeCampaigns.value =
    campaignsResult.status === 'fulfilled'
      ? (campaignsResult.value.meta.status_counts?.active ?? null)
      : null

  loading.value = false
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
  <div class="agency-page" data-test="dashboard-page">
    <WelcomeBar />

    <KpiStrip :summary="summary" :active-campaigns="activeCampaigns" :loading="loading" />

    <v-alert v-if="error" type="error" variant="tonal" data-test="dashboard-error">
      {{ error }}
    </v-alert>

    <section class="agency-card" data-test="dashboard-activity">
      <h2 class="agency-card__title">{{ t('dashboard.activity.title') }}</h2>
      <ActivityFeed />
    </section>
  </div>
</template>
