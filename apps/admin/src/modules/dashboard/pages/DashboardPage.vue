<script setup lang="ts">
/**
 * Admin operational dashboard (Sprint 13, D-7).
 *
 * A KPI strip of NON-payment operational counts (agency totals, the
 * creator-approval backlog, the KYC queue depth, queue health) + the recent
 * cross-agency audit activity feed. The payment/dispute cards are
 * coming-soon (D-13) — rendered via CKpiCard with a `null` value (a muted
 * dash) so they hold their slot and light up in place when payments ship
 * (Sprint 10). The summary's approval/KYC counts also feed the nav badges.
 */

import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { formatDateTime, ApiError } from '@catalyst/api-client'
import { CKpiCard } from '@catalyst/ui'

import { useNavBadges } from '@/core/stores/useNavBadges'

import {
  adminDashboardApi,
  type AdminDashboardActivityRow,
  type AdminDashboardSummary,
} from '../api/dashboard.api'

const { t, locale } = useI18n()
const navBadges = useNavBadges()

const summary = ref<AdminDashboardSummary | null>(null)
const activity = ref<AdminDashboardActivityRow[]>([])
const loading = ref(false)
const errorKey = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  errorKey.value = null
  try {
    const [summaryRes, activityRes] = await Promise.all([
      adminDashboardApi.summary(),
      adminDashboardApi.activity(),
    ])
    summary.value = summaryRes.data
    activity.value = activityRes.data
    navBadges.setCounts({
      creatorApprovals: summaryRes.data.creators_pending_approval,
      kycQueue: summaryRes.data.creators_pending_kyc,
    })
  } catch (error) {
    errorKey.value = error instanceof ApiError ? error.code : 'admin.dashboard.load_failed'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})

function formatTimestamp(iso: string): string {
  return formatDateTime(iso, locale.value)
}
</script>

<template>
  <section data-testid="admin-dashboard">
    <h1 class="text-h5 mb-4">{{ t('admin.dashboard.title') }}</h1>

    <v-alert
      v-if="errorKey"
      type="error"
      variant="tonal"
      class="mb-4"
      data-testid="admin-dashboard-error"
    >
      {{ t(errorKey) }}
    </v-alert>

    <v-row dense class="mb-2">
      <v-col cols="12" sm="6" md="3">
        <CKpiCard
          :label="t('admin.dashboard.kpi.agencies_total')"
          :value="summary?.agencies_total ?? null"
          :loading="loading"
          data-test="admin-kpi-agencies-total"
        />
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <CKpiCard
          :label="t('admin.dashboard.kpi.agencies_suspended')"
          :value="summary?.agencies_suspended ?? null"
          :loading="loading"
          data-test="admin-kpi-agencies-suspended"
        />
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <CKpiCard
          :label="t('admin.dashboard.kpi.creators_pending_approval')"
          :value="summary?.creators_pending_approval ?? null"
          :loading="loading"
          data-test="admin-kpi-creator-approvals"
        />
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <CKpiCard
          :label="t('admin.dashboard.kpi.creators_pending_kyc')"
          :value="summary?.creators_pending_kyc ?? null"
          :loading="loading"
          data-test="admin-kpi-kyc-queue"
        />
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <CKpiCard
          :label="t('admin.dashboard.kpi.queue_pending')"
          :value="summary?.queue_pending ?? null"
          :loading="loading"
          data-test="admin-kpi-queue-pending"
        />
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <CKpiCard
          :label="t('admin.dashboard.kpi.queue_failed')"
          :value="summary?.queue_failed ?? null"
          :loading="loading"
          data-test="admin-kpi-queue-failed"
        />
      </v-col>
      <!-- Coming-soon payment cards (D-13): null value → muted dash. -->
      <v-col cols="12" sm="6" md="3">
        <CKpiCard
          :label="t('admin.dashboard.kpi.open_disputes')"
          :value="summary?.open_disputes ?? null"
          :loading="loading"
          data-test="admin-kpi-open-disputes"
        />
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <CKpiCard
          :label="t('admin.dashboard.kpi.failed_payments_today')"
          :value="summary?.failed_payments_today ?? null"
          :loading="loading"
          data-test="admin-kpi-failed-payments"
        />
      </v-col>
    </v-row>

    <v-card variant="outlined" class="mt-4" data-testid="admin-dashboard-activity">
      <v-card-title class="text-subtitle-1">{{
        t('admin.dashboard.activity.heading')
      }}</v-card-title>
      <v-list lines="two">
        <v-list-item
          v-if="activity.length === 0 && !loading"
          data-testid="admin-dashboard-activity-empty"
        >
          <v-list-item-subtitle>{{ t('admin.dashboard.activity.empty') }}</v-list-item-subtitle>
        </v-list-item>
        <v-list-item
          v-for="row in activity"
          :key="row.id"
          :data-testid="`admin-dashboard-activity-${row.id}`"
        >
          <v-list-item-title>
            <v-chip size="x-small" variant="tonal" class="mr-2">{{ row.attributes.action }}</v-chip>
            {{ row.attributes.actor_name ?? row.attributes.actor_email ?? '—' }}
          </v-list-item-title>
          <v-list-item-subtitle>
            {{ formatTimestamp(row.attributes.created_at) }}
            <template v-if="row.attributes.reason"> — {{ row.attributes.reason }}</template>
          </v-list-item-subtitle>
        </v-list-item>
      </v-list>
    </v-card>
  </section>
</template>
