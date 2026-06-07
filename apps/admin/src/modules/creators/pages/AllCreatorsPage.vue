<script setup lang="ts">
/**
 * Admin "all creators" surface (Sprint 13, D-4).
 *
 * The unfiltered roster — every creator regardless of application or KYC
 * status (the approvals queue and the KYC queue are the filtered triage
 * views). Same backend endpoint (GET /admin/creators) with no status
 * filter; paginated; click-through to the detail drill-in.
 */

import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { ApiError } from '@catalyst/api-client'

import { adminCreatorsApi, type AdminCreatorListItem } from '../api/creators.api'

const { t } = useI18n()
const router = useRouter()

const items = ref<AdminCreatorListItem[]>([])
const totalItems = ref(0)
const loading = ref(false)
const errorKey = ref<string | null>(null)

const tableOptions = ref({ page: 1, itemsPerPage: 25 })

const headers = [
  { title: t('admin.creators.list.fields.name'), key: 'attributes.display_name', sortable: false },
  { title: t('admin.creators.list.fields.email'), key: 'attributes.email', sortable: false },
  {
    title: t('admin.creators.list.fields.status'),
    key: 'attributes.application_status',
    sortable: false,
    width: 140,
  },
  {
    title: t('admin.creators.list.fields.kyc'),
    key: 'attributes.kyc_status',
    sortable: false,
    width: 120,
  },
  { title: '', key: 'actions', sortable: false, width: 80, align: 'end' as const },
]

async function load(): Promise<void> {
  loading.value = true
  errorKey.value = null
  try {
    const res = await adminCreatorsApi.list({
      page: tableOptions.value.page,
      per_page: tableOptions.value.itemsPerPage,
    })
    items.value = res.data
    totalItems.value = res.meta.total
  } catch (error) {
    errorKey.value = error instanceof ApiError ? error.code : 'admin.creators.all.load_failed'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})

function onTableUpdate(opts: { page: number; itemsPerPage: number }): void {
  tableOptions.value = opts
  void load()
}

function goToDetail(ulid: string): void {
  void router.push({ name: 'app.creators.detail', params: { ulid } })
}
</script>

<template>
  <section data-testid="admin-all-creators">
    <header class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h5 ma-0">{{ t('admin.creators.all.title') }}</h1>
    </header>

    <v-alert
      v-if="errorKey"
      type="error"
      variant="tonal"
      class="mb-4"
      data-testid="admin-all-creators-error"
    >
      {{ t(errorKey) }}
    </v-alert>

    <v-data-table-server
      :headers="headers"
      :items="items"
      :items-length="totalItems"
      :loading="loading"
      :items-per-page="tableOptions.itemsPerPage"
      :page="tableOptions.page"
      item-value="id"
      :no-data-text="t('admin.creators.all.empty')"
      data-testid="admin-all-creators-table"
      @update:options="onTableUpdate"
    >
      <template #item.attributes.display_name="{ item }">
        <button
          type="button"
          class="admin-all-creators__name-link"
          :data-testid="`admin-all-creators-name-${item.id}`"
          @click="goToDetail(item.id)"
        >
          {{ item.attributes.display_name ?? t('admin.creators.list.unnamed') }}
        </button>
      </template>

      <template #item.attributes.email="{ item }">
        {{ item.attributes.email ?? '—' }}
      </template>

      <template #item.attributes.application_status="{ item }">
        <v-chip size="small" variant="tonal">
          {{ t(`admin.creators.list.status_labels.${item.attributes.application_status}`) }}
        </v-chip>
      </template>

      <template #item.attributes.kyc_status="{ item }">
        {{ t(`creator.ui.wizard.steps.kyc.status_labels.${item.attributes.kyc_status}`) }}
      </template>

      <template #item.actions="{ item }">
        <v-btn
          icon="mdi-eye-outline"
          size="small"
          variant="text"
          :aria-label="t('admin.creators.list.view')"
          :data-testid="`admin-all-creators-view-${item.id}`"
          @click="goToDetail(item.id)"
        />
      </template>
    </v-data-table-server>
  </section>
</template>

<style scoped>
.admin-all-creators__name-link {
  background: none;
  border: none;
  padding: 0;
  color: rgb(var(--v-theme-primary));
  cursor: pointer;
  font: inherit;
  text-align: left;
}

.admin-all-creators__name-link:hover {
  text-decoration: underline;
}
</style>
