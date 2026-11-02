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
 * the skeleton. The cards are the agency `AgencyKpiCard` (AH-114 L4; the shared `CKpiCard` stays
 * untouched): an icon tile each, and a link where the card has a number and a page behind it
 * (the roster). The labels are localized here (the card is i18n-free and takes pre-localized
 * strings).
 */

import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import AgencyKpiCard from '@/modules/agency/components/AgencyKpiCard.vue'

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
    icon: 'mdi-bullhorn-outline',
    tileTone: 'ai' as const,
  },
  {
    key: 'creatorsInRoster',
    label: t('dashboard.kpi.creatorsInRoster'),
    value: props.summary?.creators_in_roster ?? null,
    icon: 'mdi-account-group-outline',
    tileTone: 'success' as const,
    to: { name: 'roster.list' },
  },
  {
    key: 'pendingApplications',
    label: t('dashboard.kpi.pendingApplications'),
    value: props.summary?.pending_creator_applications ?? null,
    icon: 'mdi-account-clock-outline',
    tileTone: 'info' as const,
  },
  {
    key: 'paymentsDue',
    label: t('dashboard.kpi.paymentsDue'),
    value: props.summary?.payments_due ?? null,
    icon: 'mdi-cash-multiple',
    tileTone: 'warning' as const,
  },
])
</script>

<template>
  <div class="kpi-strip" data-test="kpi-strip">
    <AgencyKpiCard
      v-for="card in cards"
      :key="card.key"
      :label="card.label"
      :value="card.value"
      :loading="loading"
      :icon="card.icon"
      :tile-tone="card.tileTone"
      :to="card.to"
      :data-test="`kpi-${card.key}`"
    />
  </div>
</template>

<style scoped>
.kpi-strip {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 20px;
}
</style>
