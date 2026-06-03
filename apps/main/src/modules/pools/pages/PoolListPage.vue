<script setup lang="ts">
/**
 * Talent-pool list page (Sprint 6 Chunk 2b) — mirrors BrandListPage. A
 * server-paginated table showing each pool's name, brand label, and
 * membership COUNT (D-2b-7: counts, not member previews), with a status
 * filter (active / archived / all) and inline archive + restore.
 */

import type { TalentPoolResource } from '@catalyst/api-client'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { CEmptyState } from '@catalyst/ui'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { talentPoolsApi } from '../api/talentPools.api'

const { t } = useI18n()

const agencyStore = useAgencyStore()

type StatusFilter = 'active' | 'archived' | 'all'

const statusFilter = ref<StatusFilter>('active')
const items = ref<TalentPoolResource[]>([])
const totalItems = ref(0)
const loading = ref(false)
const error = ref<string | null>(null)

const archiveDialog = ref(false)
const poolToArchive = ref<TalentPoolResource | null>(null)
const archiving = ref(false)
const archiveError = ref<string | null>(null)

const restoreDialog = ref(false)
const poolToRestore = ref<TalentPoolResource | null>(null)
const restoring = ref(false)
const restoreError = ref<string | null>(null)
const restoreSuccessMessage = ref<string | null>(null)

const canWrite = computed(() => agencyStore.isAdmin || agencyStore.currentRole === 'agency_manager')

const tableOptions = ref({ page: 1, itemsPerPage: 25 })

const headers = [
  { title: t('app.pools.fields.name'), key: 'attributes.name', sortable: false },
  { title: t('app.pools.fields.brand'), key: 'attributes.brand_name', sortable: false },
  {
    title: t('app.pools.fields.members'),
    key: 'attributes.creators_count',
    sortable: false,
    width: 120,
  },
  { title: '', key: 'actions', sortable: false, width: 120, align: 'end' as const },
]

const statusFilterItems: { label: string; value: StatusFilter }[] = [
  { label: t('app.pools.status.active'), value: 'active' },
  { label: t('app.pools.status.all'), value: 'all' },
  { label: t('app.pools.status.archived'), value: 'archived' },
]

async function loadPools(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  loading.value = true
  error.value = null

  try {
    const res = await talentPoolsApi.list(agencyId, {
      page: tableOptions.value.page,
      per_page: tableOptions.value.itemsPerPage,
      status: statusFilter.value,
    })
    items.value = res.data
    totalItems.value = res.meta.total
  } catch {
    error.value = t('app.pools.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void loadPools()
})

watch(
  () => agencyStore.currentAgencyId,
  (id) => {
    if (id !== null) void loadPools()
  },
)

watch(statusFilter, () => {
  tableOptions.value.page = 1
  void loadPools()
})

function onTableUpdate(opts: { page: number; itemsPerPage: number }): void {
  tableOptions.value = opts
  void loadPools()
}

function openArchiveDialog(pool: TalentPoolResource): void {
  poolToArchive.value = pool
  archiveDialog.value = true
  archiveError.value = null
}

function closeArchiveDialog(): void {
  archiveDialog.value = false
  poolToArchive.value = null
  archiveError.value = null
}

async function confirmArchive(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null || poolToArchive.value === null) return

  archiving.value = true
  archiveError.value = null

  try {
    await talentPoolsApi.archive(agencyId, poolToArchive.value.id)
    closeArchiveDialog()
    void loadPools()
  } catch {
    archiveError.value = t('app.pools.errors.archiveFailed')
  } finally {
    archiving.value = false
  }
}

function openRestoreDialog(pool: TalentPoolResource): void {
  poolToRestore.value = pool
  restoreDialog.value = true
  restoreError.value = null
}

function closeRestoreDialog(): void {
  restoreDialog.value = false
  poolToRestore.value = null
  restoreError.value = null
}

async function confirmRestore(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null || poolToRestore.value === null) return

  const poolName = poolToRestore.value.attributes.name

  restoring.value = true
  restoreError.value = null

  try {
    await talentPoolsApi.restore(agencyId, poolToRestore.value.id)
    closeRestoreDialog()
    restoreSuccessMessage.value = t('app.pools.restore.success', { name: poolName })
    void loadPools()
  } catch {
    restoreError.value = t('app.pools.errors.restoreFailed')
  } finally {
    restoring.value = false
  }
}
</script>

<template>
  <div data-test="pool-list-page">
    <div class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h5 ma-0" data-test="pool-list-heading">{{ t('app.pools.title') }}</h1>
      <v-btn
        v-if="canWrite"
        color="primary"
        prepend-icon="mdi-plus"
        :to="{ name: 'pools.create' }"
        data-test="pool-create-btn"
      >
        {{ t('app.pools.actions.create') }}
      </v-btn>
    </div>

    <v-chip-group v-model="statusFilter" mandatory class="mb-4" data-test="pool-status-filter">
      <v-chip
        v-for="item in statusFilterItems"
        :key="item.value"
        :value="item.value"
        filter
        variant="outlined"
        :data-test="`pool-filter-${item.value}`"
      >
        {{ item.label }}
      </v-chip>
    </v-chip-group>

    <v-alert v-if="error" type="error" variant="tonal" class="mb-4" data-test="pool-list-error">
      {{ error }}
    </v-alert>

    <template v-if="loading && items.length === 0">
      <v-skeleton-loader type="table" data-test="pool-list-skeleton" />
    </template>

    <template v-else-if="!loading && items.length === 0 && !error">
      <CEmptyState
        v-if="statusFilter === 'active'"
        data-test="pool-empty-state"
        title-tag="h2"
        :title="t('app.pools.empty.heading')"
        :body="t('app.pools.empty.body')"
      >
        <template #icon>
          <v-icon icon="mdi-account-multiple-plus-outline" size="64" color="medium-emphasis" />
        </template>
        <template v-if="canWrite" #action>
          <v-btn color="primary" :to="{ name: 'pools.create' }" data-test="pool-empty-cta">
            {{ t('app.pools.empty.cta') }}
          </v-btn>
        </template>
      </CEmptyState>
      <CEmptyState
        v-else
        data-test="pool-empty-filtered"
        title-tag="h2"
        :title="t('app.pools.emptyFiltered.heading')"
        :body="t('app.pools.emptyFiltered.body')"
      >
        <template #icon>
          <v-icon icon="mdi-filter-remove-outline" size="48" color="medium-emphasis" />
        </template>
      </CEmptyState>
    </template>

    <v-data-table-server
      v-else
      :headers="headers"
      :items="items"
      :items-length="totalItems"
      :loading="loading"
      :items-per-page="tableOptions.itemsPerPage"
      :page="tableOptions.page"
      item-value="id"
      data-test="pool-table"
      @update:options="onTableUpdate"
    >
      <template #item.attributes.brand_name="{ item }">
        <v-chip v-if="item.attributes.brand_name" size="small" variant="tonal">
          {{ item.attributes.brand_name }}
        </v-chip>
        <span v-else class="text-medium-emphasis text-caption">
          {{ t('app.pools.agencyWide') }}
        </span>
      </template>

      <template #item.attributes.creators_count="{ item }">
        <span :data-test="`pool-count-${item.id}`">{{ item.attributes.creators_count }}</span>
      </template>

      <template #item.actions="{ item }">
        <div class="d-flex justify-end ga-1">
          <v-btn
            icon="mdi-eye-outline"
            size="small"
            variant="text"
            :to="{ name: 'pools.detail', params: { ulid: item.id } }"
            :aria-label="t('app.pools.detail.title')"
            :data-test="`pool-view-${item.id}`"
          />
          <v-btn
            v-if="canWrite && !item.attributes.is_archived"
            icon="mdi-pencil-outline"
            size="small"
            variant="text"
            :to="{ name: 'pools.edit', params: { ulid: item.id } }"
            :aria-label="t('app.pools.actions.edit')"
            :data-test="`pool-edit-${item.id}`"
          />
          <v-btn
            v-if="canWrite && !item.attributes.is_archived"
            icon="mdi-archive-outline"
            size="small"
            variant="text"
            color="warning"
            :aria-label="t('app.pools.actions.archive')"
            :data-test="`pool-archive-${item.id}`"
            @click="openArchiveDialog(item)"
          />
          <v-btn
            v-if="canWrite && item.attributes.is_archived"
            icon="mdi-restore"
            size="small"
            variant="text"
            color="primary"
            :aria-label="t('app.pools.actions.restore')"
            :data-test="`pool-restore-${item.id}`"
            @click="openRestoreDialog(item)"
          />
        </div>
      </template>
    </v-data-table-server>

    <v-dialog v-model="restoreDialog" max-width="440" data-test="pool-restore-dialog">
      <v-card v-if="poolToRestore">
        <v-card-title class="text-h6 pa-4">{{ t('app.pools.restore.confirmTitle') }}</v-card-title>
        <v-card-text>
          <p data-test="pool-restore-dialog-message">
            {{ t('app.pools.restore.confirmMessage', { name: poolToRestore.attributes.name }) }}
          </p>
          <v-alert v-if="restoreError" type="error" variant="tonal" class="mt-2">
            {{ restoreError }}
          </v-alert>
        </v-card-text>
        <v-card-actions class="px-4 pb-4">
          <v-spacer />
          <v-btn variant="text" :disabled="restoring" @click="closeRestoreDialog">
            {{ t('app.pools.restore.cancel') }}
          </v-btn>
          <v-btn
            color="primary"
            variant="flat"
            :loading="restoring"
            :disabled="restoring"
            data-test="pool-restore-dialog-confirm"
            @click="confirmRestore"
          >
            {{ t('app.pools.restore.confirm') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar
      :model-value="restoreSuccessMessage !== null"
      :timeout="4000"
      color="success"
      data-test="pool-restore-success-toast"
      @update:model-value="
        (v) => {
          if (!v) restoreSuccessMessage = null
        }
      "
    >
      {{ restoreSuccessMessage }}
    </v-snackbar>

    <v-dialog v-model="archiveDialog" max-width="440" data-test="pool-archive-dialog">
      <v-card v-if="poolToArchive">
        <v-card-title class="text-h6 pa-4">{{ t('app.pools.archive.confirmTitle') }}</v-card-title>
        <v-card-text>
          <p data-test="pool-archive-dialog-message">
            {{ t('app.pools.archive.confirmMessage', { name: poolToArchive.attributes.name }) }}
          </p>
          <v-alert v-if="archiveError" type="error" variant="tonal" class="mt-2">
            {{ archiveError }}
          </v-alert>
        </v-card-text>
        <v-card-actions class="px-4 pb-4">
          <v-spacer />
          <v-btn variant="text" :disabled="archiving" @click="closeArchiveDialog">
            {{ t('app.pools.archive.cancel') }}
          </v-btn>
          <v-btn
            color="warning"
            variant="flat"
            :loading="archiving"
            :disabled="archiving"
            data-test="pool-archive-dialog-confirm"
            @click="confirmArchive"
          >
            {{ t('app.pools.archive.confirm') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>
