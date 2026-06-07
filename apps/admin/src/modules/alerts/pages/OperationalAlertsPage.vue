<script setup lang="ts">
/**
 * Admin operational-alerts surface (Sprint 13, D-12) — the non-payment
 * admin notification consumer.
 *
 * Renders the admin's own operational alerts feed (audit-activity /
 * operational notifications) from `/admin/alerts`. The PAYMENT-event
 * alerts are a discrete coming-soon block (D-13): the backend reports
 * them under `meta.payment_alerts` and we render a muted card S10 swaps
 * for the real payment-alert stream — never unpicked from elsewhere.
 *
 * The operational feed ships empty this sprint (the operational emit
 * sites land with their features); the surface + load path exist now so
 * those emits light up a finished page.
 */

import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@catalyst/api-client'

import { adminAlertsApi, type AdminAlert } from '../api/alerts.api'

const { t } = useI18n()

const items = ref<AdminAlert[]>([])
const paymentAlertsComingSoon = ref(false)
const loading = ref(false)
const errorKey = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  errorKey.value = null
  try {
    const res = await adminAlertsApi.list()
    items.value = res.data
    paymentAlertsComingSoon.value = res.meta.payment_alerts.coming_soon
  } catch (error) {
    errorKey.value = error instanceof ApiError ? error.code : 'admin.alerts.load_failed'
  } finally {
    loading.value = false
  }
}

function alertLabel(notificationType: string): string {
  const key = `admin.alerts.types.${notificationType}`
  const resolved = t(key)
  return resolved === key ? notificationType : resolved
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section data-testid="admin-operational-alerts">
    <header class="mb-4">
      <h1 class="text-h5 ma-0">{{ t('admin.alerts.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis ma-0 mt-1">
        {{ t('admin.alerts.subtitle') }}
      </p>
    </header>

    <v-alert
      v-if="errorKey"
      type="error"
      variant="tonal"
      class="mb-4"
      data-testid="admin-operational-alerts-error"
    >
      {{ t(errorKey) }}
    </v-alert>

    <v-progress-linear v-if="loading" indeterminate class="mb-4" />

    <v-card v-else variant="outlined" class="mb-4" data-testid="admin-operational-alerts-feed">
      <v-list v-if="items.length > 0" lines="two" density="comfortable">
        <v-list-item
          v-for="alert in items"
          :key="alert.id"
          :data-testid="`admin-alert-${alert.id}`"
        >
          <v-list-item-title>{{
            alertLabel(alert.attributes.notification_type)
          }}</v-list-item-title>
          <v-list-item-subtitle>{{ alert.attributes.created_at }}</v-list-item-subtitle>
        </v-list-item>
      </v-list>

      <v-card-text
        v-else
        class="text-medium-emphasis text-center py-8"
        data-testid="admin-operational-alerts-empty"
      >
        {{ t('admin.alerts.empty') }}
      </v-card-text>
    </v-card>

    <!-- Payment-event alerts — discrete coming-soon block (D-13). S10
         replaces this card with the real payment-alert stream. -->
    <v-card
      v-if="paymentAlertsComingSoon"
      variant="tonal"
      color="grey"
      data-testid="admin-payment-alerts-coming-soon"
    >
      <v-card-item>
        <template #prepend>
          <v-icon icon="mdi-cash-clock" />
        </template>
        <v-card-title class="text-body-1">{{ t('admin.alerts.paymentAlerts.title') }}</v-card-title>
        <v-card-subtitle>{{ t('admin.alerts.paymentAlerts.body') }}</v-card-subtitle>
      </v-card-item>
    </v-card>
  </section>
</template>
