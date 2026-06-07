<script setup lang="ts">
/**
 * Admin GDPR data-subject ERASURE queue (Sprint 13, D-11) — SHELL.
 *
 * Art. 17 ("right to be forgotten") operator surface. Ships empty this
 * sprint: the page fetches `/admin/compliance/erasure-queue`, which
 * returns `data: []` + `meta.shell: true` until S14 lands the backing
 * table + the erasure machinery. The surface, the table, and the load
 * path all exist now so S14 fills data into a finished page. We render
 * the shell-state copy (not a neutral "no results") so the empty list
 * reads as "not built yet", truthfully.
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
    const res = await adminComplianceApi.listErasures()
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
  <section data-testid="admin-erasure-queue">
    <header class="mb-4">
      <h1 class="text-h5 ma-0">{{ t('admin.compliance.erasures.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis ma-0 mt-1">
        {{ t('admin.compliance.erasures.subtitle') }}
      </p>
    </header>

    <v-alert
      v-if="errorKey"
      type="error"
      variant="tonal"
      class="mb-4"
      data-testid="admin-erasure-queue-error"
    >
      {{ t(errorKey) }}
    </v-alert>

    <v-alert
      v-else-if="isShell"
      type="info"
      variant="tonal"
      class="mb-4"
      data-testid="admin-erasure-queue-shell"
    >
      {{ t('admin.compliance.shellNotice') }}
    </v-alert>

    <v-data-table
      :headers="headers"
      :items="items"
      :loading="loading"
      item-value="id"
      :no-data-text="t('admin.compliance.erasures.empty')"
      data-testid="admin-erasure-queue-table"
    >
      <template #item.attributes.subject_email="{ item }">
        {{ item.attributes.subject_email ?? '—' }}
      </template>
    </v-data-table>
  </section>
</template>
