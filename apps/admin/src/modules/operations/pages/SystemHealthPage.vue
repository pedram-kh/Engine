<script setup lang="ts">
/**
 * Admin system-health page (Sprint 13, D-8).
 *
 * Renders the cheap DB + cache liveness probe. Queues / failed jobs live
 * in the gated Horizon embed (linked from the Operations nav), so this
 * page is the dependency-reachability surface only.
 */

import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@catalyst/api-client'

import { adminOperationsApi, type AdminHealth } from '../api/operations.api'

const { t } = useI18n()

const health = ref<AdminHealth | null>(null)
const loading = ref(false)
const errorKey = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  errorKey.value = null
  try {
    const res = await adminOperationsApi.health()
    health.value = res.data
  } catch (error) {
    errorKey.value = error instanceof ApiError ? error.code : 'admin.operations.health.load_failed'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section data-testid="admin-system-health">
    <h1 class="text-h5 mb-4">{{ t('admin.operations.health.title') }}</h1>

    <v-alert
      v-if="errorKey"
      type="error"
      variant="tonal"
      class="mb-4"
      data-testid="admin-system-health-error"
    >
      {{ t(errorKey) }}
    </v-alert>

    <v-card v-if="health" variant="outlined">
      <v-card-title class="d-flex align-center ga-3">
        <span class="text-subtitle-1">{{ t('admin.operations.health.heading') }}</span>
        <v-chip
          size="small"
          :color="health.status === 'ok' ? 'success' : 'error'"
          variant="tonal"
          data-testid="admin-system-health-status"
        >
          {{ t(`admin.operations.health.status.${health.status}`) }}
        </v-chip>
      </v-card-title>
      <v-list density="compact">
        <v-list-item
          v-for="(status, name) in health.checks"
          :key="name"
          :data-testid="`admin-system-health-check-${name}`"
        >
          <v-list-item-title>{{ t(`admin.operations.health.checks.${name}`) }}</v-list-item-title>
          <template #append>
            <v-icon :color="status === 'ok' ? 'success' : 'error'">
              {{ status === 'ok' ? 'mdi-check-circle' : 'mdi-alert-circle' }}
            </v-icon>
          </template>
        </v-list-item>
      </v-list>
    </v-card>
  </section>
</template>
