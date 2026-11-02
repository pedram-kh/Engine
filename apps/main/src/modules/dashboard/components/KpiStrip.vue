<script setup lang="ts">
/**
 * KpiStrip — the workspace-home KPI cards (§11; Sprint 4 Chunk 1, 1b).
 *
 * Locked order (D-c1-4): Active campaigns → Creators in roster → Pending creator
 * applications → Payments due.
 *
 * Active campaigns reads the campaigns list's status counts (AH-116, the `activeCampaigns`
 * prop; a muted `—` while null). Payments due has no number behind it yet, so its card is NOT
 * DRAWN while `summary.payments_due` is null, and returns in its slot the day the API sends one
 * (the key, the label and the order are kept). A card that always reads `—` is noise.
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
  /** `meta.status_counts.active` off the campaigns list; null when unavailable. */
  activeCampaigns?: number | null
  loading: boolean
}>()

const { t } = useI18n()

const allCards = computed(() => [
  {
    key: 'activeCampaigns',
    label: t('dashboard.kpi.activeCampaigns'),
    value: props.activeCampaigns ?? null,
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

const cards = computed(() =>
  allCards.value.filter((card) => card.key !== 'paymentsDue' || card.value !== null),
)
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
