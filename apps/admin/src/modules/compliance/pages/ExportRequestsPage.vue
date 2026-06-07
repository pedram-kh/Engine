<script setup lang="ts">
/**
 * Admin GDPR data-subject EXPORT queue (Sprint 13, D-11) — SHELL.
 *
 * Art. 15/20 export-request operator surface. Ships empty this sprint:
 * the page fetches `/admin/compliance/export-requests`, which returns
 * `data: []` + `meta.shell: true` until S14 lands the backing table. The
 * surface, the table, and the load path all exist now so S14 fills data
 * into a finished page. We render the shell-state copy (not a neutral
 * "no results") so the empty list reads as "not built yet", truthfully.
 */

import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@catalyst/api-client'

import { adminComplianceApi, type ComplianceRequest } from '../api/compliance.api'

const { t } = useI18n()

const items = ref<ComplianceRequest[]>([])
const isShell = ref(false)
const loading = ref(false)
const errorKey = ref<string | null>(null)

const headers = [
  { title: t('admin.compliance.fields.subject'), key: 'attributes.subject_email', sortable: false },
  {
    title: t('admin.compliance.fields.status'),
    key: 'attributes.status',
    sortable: false,
    width: 160,
  },
  {
    title: t('admin.compliance.fields.requested'),
    key: 'attributes.requested_at',
    sortable: false,
    width: 200,
  },
]

async function load(): Promise<void> {
  loading.value = true
  errorKey.value = null
  try {
    const res = await adminComplianceApi.listExports()
    items.value = res.data
    isShell.value = res.meta.shell
  } catch (error) {
    errorKey.value = error instanceof ApiError ? error.code : 'admin.compliance.load_failed'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section data-testid="admin-export-requests">
    <header class="mb-4">
      <h1 class="text-h5 ma-0">{{ t('admin.compliance.exports.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis ma-0 mt-1">
        {{ t('admin.compliance.exports.subtitle') }}
      </p>
    </header>

    <v-alert
      v-if="errorKey"
      type="error"
      variant="tonal"
      class="mb-4"
      data-testid="admin-export-requests-error"
    >
      {{ t(errorKey) }}
    </v-alert>

    <v-alert
      v-else-if="isShell"
      type="info"
      variant="tonal"
      class="mb-4"
      data-testid="admin-export-requests-shell"
    >
      {{ t('admin.compliance.shellNotice') }}
    </v-alert>

    <v-data-table
      :headers="headers"
      :items="items"
      :loading="loading"
      item-value="id"
      :no-data-text="t('admin.compliance.exports.empty')"
      data-testid="admin-export-requests-table"
    >
      <template #item.attributes.subject_email="{ item }">
        {{ item.attributes.subject_email ?? '—' }}
      </template>
    </v-data-table>
  </section>
</template>
