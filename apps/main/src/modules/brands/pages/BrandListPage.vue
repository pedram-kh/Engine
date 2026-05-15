<script setup lang="ts">
/**
 * Brand list page — server-side paginated table with status filter.
 *
 * Features:
 *   - v-data-table-server consuming GET /api/v1/agencies/{agency}/brands
 *   - Status filter chip group (active / archived / all)
 *   - Empty state when no brands exist
 *   - Loading skeleton while fetching
 *   - Archive confirmation modal (inline)
 */

import type { BrandResource } from '@catalyst/api-client'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { brandsApi } from '../api/brands.api'

const { t } = useI18n()

const agencyStore = useAgencyStore()

type StatusFilter = 'active' | 'archived' | 'all'

const statusFilter = ref<StatusFilter>('active')
const items = ref<BrandResource[]>([])
const totalItems = ref(0)
const loading = ref(false)
const error = ref<string | null>(null)

// Archive confirmation
const archiveDialog = ref(false)
const brandToArchive = ref<BrandResource | null>(null)
const archiving = ref(false)
const archiveError = ref<string | null>(null)

// Restore confirmation — Sprint 3 Chunk 4 sub-step 6.
//
// Per-archived-row restore action, gated by agency_admin role on the
// client (the backend policy is broader — agency_manager can also
// restore — but the kickoff's intent is to keep the manage-the-archive
// action to admins on the UI). A toast notification surfaces after a
// successful restore; the dialog itself is borrowed from the archive
// confirmation pattern above to keep the two flows visually consistent.
const restoreDialog = ref(false)
const brandToRestore = ref<BrandResource | null>(null)
const restoring = ref(false)
const restoreError = ref<string | null>(null)
const restoreSuccessMessage = ref<string | null>(null)

const canRestore = computed(() => agencyStore.isAdmin)

const tableOptions = ref({ page: 1, itemsPerPage: 25 })

const headers = [
  { title: t('app.brands.fields.name'), key: 'attributes.name', sortable: false },
  { title: t('app.brands.fields.status'), key: 'attributes.status', sortable: false, width: 120 },
  { title: 'Created', key: 'attributes.created_at', sortable: false, width: 160 },
  { title: '', key: 'actions', sortable: false, width: 120, align: 'end' as const },
]

const statusFilterItems: { label: string; value: StatusFilter }[] = [
  { label: t('app.brands.status.active'), value: 'active' },
  { label: t('app.brands.status.all'), value: 'all' },
  { label: t('app.brands.status.archived'), value: 'archived' },
]

async function loadBrands(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  loading.value = true
  error.value = null

  try {
    // Send the filter value verbatim: the backend handles 'active' | 'archived' | 'all'.
    // 'all' returns both active and archived (soft-deleted) brands so the unified
    // view can render archived rows alongside active ones.
    const res = await brandsApi.list(agencyId, {
      page: tableOptions.value.page,
      per_page: tableOptions.value.itemsPerPage,
      status: statusFilter.value,
    })
    items.value = res.data
    totalItems.value = res.meta.total
  } catch {
    error.value = t('app.brands.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

// Load on mount. The v-data-table-server is inside a v-else that only
// renders once items are populated, so @update:options cannot trigger the
// initial fetch. We must call loadBrands() explicitly on mount.
onMounted(() => {
  void loadBrands()
})

// Re-load whenever the active agency changes (e.g. workspace switch or
// async store init — currentAgencyId may be null on first mount).
watch(
  () => agencyStore.currentAgencyId,
  (id) => {
    if (id !== null) void loadBrands()
  },
)

watch(statusFilter, () => {
  tableOptions.value.page = 1
  void loadBrands()
})

function onTableUpdate(opts: { page: number; itemsPerPage: number }): void {
  tableOptions.value = opts
  void loadBrands()
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString()
}

function openArchiveDialog(brand: BrandResource): void {
  brandToArchive.value = brand
  archiveDialog.value = true
  archiveError.value = null
}

function closeArchiveDialog(): void {
  archiveDialog.value = false
  brandToArchive.value = null
  archiveError.value = null
}

async function confirmArchive(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null || brandToArchive.value === null) return

  archiving.value = true
  archiveError.value = null

  try {
    await brandsApi.archive(agencyId, brandToArchive.value.id)
    closeArchiveDialog()
    void loadBrands()
  } catch {
    archiveError.value = t('app.brands.errors.archiveFailed')
  } finally {
    archiving.value = false
  }
}

// Sprint 3 Chunk 4 sub-step 6 — restore handlers mirror the archive
// shape (open dialog → confirm → close + reload + toast). The toast
// uses a transient ref + v-snackbar (timeout-driven) rather than a
// shared notification store, which is Sprint 4+'s when a third surface
// needs the same pattern.

function openRestoreDialog(brand: BrandResource): void {
  brandToRestore.value = brand
  restoreDialog.value = true
  restoreError.value = null
}

function closeRestoreDialog(): void {
  restoreDialog.value = false
  brandToRestore.value = null
  restoreError.value = null
}

async function confirmRestore(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null || brandToRestore.value === null) return

  const brandName = brandToRestore.value.attributes.name

  restoring.value = true
  restoreError.value = null

  try {
    await brandsApi.restore(agencyId, brandToRestore.value.id)
    closeRestoreDialog()
    restoreSuccessMessage.value = t('app.brands.restore.success', { name: brandName })
    void loadBrands()
  } catch {
    restoreError.value = t('app.brands.errors.restoreFailed')
  } finally {
    restoring.value = false
  }
}
</script>

<template>
  <div data-test="brand-list-page">
    <div class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h5 ma-0" data-test="brand-list-heading">{{ t('app.brands.title') }}</h1>
      <v-btn
        color="primary"
        prepend-icon="mdi-plus"
        :to="{ name: 'brands.create' }"
        data-test="brand-create-btn"
      >
        {{ t('app.brands.actions.create') }}
      </v-btn>
    </div>

    <!-- Status filter chips -->
    <v-chip-group v-model="statusFilter" mandatory class="mb-4" data-test="brand-status-filter">
      <v-chip
        v-for="item in statusFilterItems"
        :key="item.value"
        :value="item.value"
        filter
        variant="outlined"
        :data-test="`brand-filter-${item.value}`"
      >
        {{ item.label }}
      </v-chip>
    </v-chip-group>

    <!-- Error alert -->
    <v-alert v-if="error" type="error" variant="tonal" class="mb-4" data-test="brand-list-error">
      {{ error }}
    </v-alert>

    <!-- Loading skeleton -->
    <template v-if="loading && items.length === 0">
      <v-skeleton-loader type="table" data-test="brand-list-skeleton" />
    </template>

    <!-- Empty state -->
    <template v-else-if="!loading && items.length === 0 && !error">
      <div
        v-if="statusFilter === 'active'"
        class="d-flex flex-column align-center justify-center pa-12"
        data-test="brand-empty-state"
      >
        <v-icon icon="mdi-tag-outline" size="64" color="medium-emphasis" class="mb-4" />
        <h2 class="text-h6 mb-2">{{ t('app.brands.empty.heading') }}</h2>
        <p class="text-body-2 text-medium-emphasis mb-6">{{ t('app.brands.empty.body') }}</p>
        <v-btn color="primary" :to="{ name: 'brands.create' }" data-test="brand-empty-cta">
          {{ t('app.brands.empty.cta') }}
        </v-btn>
      </div>
      <div
        v-else
        class="d-flex flex-column align-center justify-center pa-12"
        data-test="brand-empty-filtered"
      >
        <v-icon icon="mdi-filter-remove-outline" size="48" color="medium-emphasis" class="mb-3" />
        <h2 class="text-h6 mb-2">{{ t('app.brands.emptyFiltered.heading') }}</h2>
        <p class="text-body-2 text-medium-emphasis">{{ t('app.brands.emptyFiltered.body') }}</p>
      </div>
    </template>

    <!-- Data table -->
    <v-data-table-server
      v-else
      :headers="headers"
      :items="items"
      :items-length="totalItems"
      :loading="loading"
      :items-per-page="tableOptions.itemsPerPage"
      :page="tableOptions.page"
      item-value="id"
      data-test="brand-table"
      @update:options="onTableUpdate"
    >
      <template #item.attributes.status="{ item }">
        <v-chip
          :color="item.attributes.status === 'active' ? 'success' : 'default'"
          size="small"
          variant="tonal"
          :data-test="`brand-status-${item.id}`"
        >
          {{ t(`app.brands.status.${item.attributes.status}`) }}
        </v-chip>
      </template>

      <template #item.attributes.created_at="{ item }">
        {{ formatDate(item.attributes.created_at) }}
      </template>

      <template #item.actions="{ item }">
        <div class="d-flex justify-end ga-1">
          <v-btn
            icon="mdi-eye-outline"
            size="small"
            variant="text"
            :to="{ name: 'brands.detail', params: { ulid: item.id } }"
            :aria-label="t('app.brands.detail.title')"
            :data-test="`brand-view-${item.id}`"
          />
          <v-btn
            icon="mdi-pencil-outline"
            size="small"
            variant="text"
            :to="{ name: 'brands.edit', params: { ulid: item.id } }"
            :aria-label="t('app.brands.actions.edit')"
            :data-test="`brand-edit-${item.id}`"
          />
          <v-btn
            v-if="item.attributes.status === 'active'"
            icon="mdi-archive-outline"
            size="small"
            variant="text"
            color="warning"
            :aria-label="t('app.brands.actions.archive')"
            :data-test="`brand-archive-${item.id}`"
            @click="openArchiveDialog(item)"
          />
          <v-btn
            v-if="item.attributes.status === 'archived' && canRestore"
            icon="mdi-restore"
            size="small"
            variant="text"
            color="primary"
            :aria-label="t('app.brands.actions.restore')"
            :data-test="`brand-restore-${item.id}`"
            @click="openRestoreDialog(item)"
          />
        </div>
      </template>
    </v-data-table-server>

    <!-- Restore confirmation dialog — Sprint 3 Chunk 4 sub-step 6 -->
    <v-dialog v-model="restoreDialog" max-width="440" data-test="restore-dialog">
      <v-card v-if="brandToRestore">
        <v-card-title class="text-h6 pa-4" data-test="restore-dialog-title">
          {{ t('app.brands.restore.confirmTitle') }}
        </v-card-title>
        <v-card-text>
          <p data-test="restore-dialog-message">
            {{ t('app.brands.restore.confirmMessage', { name: brandToRestore.attributes.name }) }}
          </p>
          <v-alert
            v-if="restoreError"
            type="error"
            variant="tonal"
            class="mt-2"
            data-test="restore-dialog-error"
          >
            {{ restoreError }}
          </v-alert>
        </v-card-text>
        <v-card-actions class="px-4 pb-4">
          <v-spacer />
          <v-btn
            variant="text"
            :disabled="restoring"
            data-test="restore-dialog-cancel"
            @click="closeRestoreDialog"
          >
            {{ t('app.brands.restore.cancel') }}
          </v-btn>
          <v-btn
            color="primary"
            variant="flat"
            :loading="restoring"
            :disabled="restoring"
            data-test="restore-dialog-confirm"
            @click="confirmRestore"
          >
            {{ t('app.brands.restore.confirm') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Restore success snackbar — auto-dismisses after 4s. Bound to the
         transient `restoreSuccessMessage` ref via `model-value` so the
         snackbar's own dismiss flips the ref back to null without an
         extra handler. -->
    <v-snackbar
      :model-value="restoreSuccessMessage !== null"
      :timeout="4000"
      color="success"
      data-test="restore-success-toast"
      @update:model-value="
        (v) => {
          if (!v) restoreSuccessMessage = null
        }
      "
    >
      {{ restoreSuccessMessage }}
    </v-snackbar>

    <!-- Archive confirmation dialog -->
    <v-dialog v-model="archiveDialog" max-width="440" data-test="archive-dialog">
      <v-card v-if="brandToArchive">
        <v-card-title class="text-h6 pa-4" data-test="archive-dialog-title">
          {{ t('app.brands.archive.confirmTitle') }}
        </v-card-title>
        <v-card-text>
          <p data-test="archive-dialog-message">
            {{ t('app.brands.archive.confirmMessage', { name: brandToArchive.attributes.name }) }}
          </p>
          <v-alert
            v-if="archiveError"
            type="error"
            variant="tonal"
            class="mt-2"
            data-test="archive-dialog-error"
          >
            {{ archiveError }}
          </v-alert>
        </v-card-text>
        <v-card-actions class="px-4 pb-4">
          <v-spacer />
          <v-btn
            variant="text"
            :disabled="archiving"
            data-test="archive-dialog-cancel"
            @click="closeArchiveDialog"
          >
            {{ t('app.brands.archive.cancel') }}
          </v-btn>
          <v-btn
            color="warning"
            variant="flat"
            :loading="archiving"
            :disabled="archiving"
            data-test="archive-dialog-confirm"
            @click="confirmArchive"
          >
            {{ t('app.brands.archive.confirm') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>
