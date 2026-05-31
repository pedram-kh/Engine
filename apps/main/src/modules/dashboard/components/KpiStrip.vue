<script setup lang="ts">
/**
 * KpiStrip — the four workspace-home KPI cards (§11; Sprint 4 Chunk 1, 1b).
 *
 * Locked order (D-c1-4): Active campaigns [placeholder] → Creators in roster
 * [real] → Pending creator applications [real] → Payments due [placeholder].
 * Placeholder cards hold their slots (campaigns / payments) and render a
 * muted `—` driven by `null`; they become real in place when those surfaces
 * ship — they are NOT removed.
 *
 * Real cards bind to the summary payload; while it loads, every card shows
 * `CKpiCard`'s skeleton. The labels are localized here (the shared
 * `CKpiCard` is i18n-free and takes pre-localized strings).
 */

import { CKpiCard } from '@catalyst/ui'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import type { DashboardSummary } from '../api/dashboard.api'

const props = defineProps<{
  summary: DashboardSummary | null
  loading: boolean
}>()

const { t } = useI18n()

const cards = computed(() => [
  {
    key: 'activeCampaigns',
    label: t('dashboard.kpi.activeCampaigns'),
    value: props.summary?.active_campaigns ?? null,
  },
  {
    key: 'creatorsInRoster',
    label: t('dashboard.kpi.creatorsInRoster'),
    value: props.summary?.creators_in_roster ?? null,
  },
  {
    key: 'pendingApplications',
    label: t('dashboard.kpi.pendingApplications'),
    value: props.summary?.pending_creator_applications ?? null,
  },
  {
    key: 'paymentsDue',
    label: t('dashboard.kpi.paymentsDue'),
    value: props.summary?.payments_due ?? null,
  },
])
</script>

<template>
  <div class="kpi-strip" data-test="kpi-strip">
    <CKpiCard
      v-for="card in cards"
      :key="card.key"
      :label="card.label"
      :value="card.value"
      :loading="loading"
      :data-test="`kpi-${card.key}`"
    />
  </div>
</template>

<style scoped>
.kpi-strip {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px;
}
</style>
