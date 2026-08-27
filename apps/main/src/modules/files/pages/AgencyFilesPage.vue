<script setup lang="ts">
/**
 * Agency Files library list page (AH-091, chunk A) — mirrors
 * {@see BrandListPage}'s server-paginated table shape. No detail page, no
 * preview, no folders (the "not-Drive" fence, D6/D7): upload, list,
 * download, delete — that's the whole surface this chunk.
 *
 * Every agency member may list/download/upload; delete is admin + manager
 * only (mirrors {@see PoolListPage}'s `canWrite` gate) — the backend
 * `AgencyFilePolicy` is the actual enforcement, this only hides the
 * affordance.
 */

import { formatDateTime, type AgencyFileResource } from '@catalyst/api-client'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { CEmptyState } from '@catalyst/ui'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { filesApi } from '../api/files.api'
import AgencyFileUploadDialog from '../components/AgencyFileUploadDialog.vue'

const { t, locale } = useI18n()

const agencyStore = useAgencyStore()

const items = ref<AgencyFileResource[]>([])
const totalItems = ref(0)
const loading = ref(false)
const error = ref<string | null>(null)

const uploadDialog = ref(false)

const deleteDialog = ref(false)
const fileToDelete = ref<AgencyFileResource | null>(null)
const deleting = ref(false)
const deleteError = ref<string | null>(null)

const canDelete = computed(
  () => agencyStore.isAdmin || agencyStore.currentRole === 'agency_manager',
)

const tableOptions = ref({ page: 1, itemsPerPage: 25 })

const headers = [
  { title: t('app.files.fields.name'), key: 'attributes.original_filename', sortable: false },
  { title: t('app.files.fields.size'), key: 'attributes.size_bytes', sortable: false, width: 110 },
  {
    title: t('app.files.fields.uploadedBy'),
    key: 'relationships.uploaded_by',
    sortable: false,
    width: 180,
  },
  {
    title: t('app.files.fields.uploadedAt'),
    key: 'attributes.created_at',
    sortable: false,
    width: 160,
  },
  { title: '', key: 'actions', sortable: false, width: 100, align: 'end' as const },
]

/** No shared byte-formatter exists yet (AH-091 is the first UI surface to
 * render `size_bytes`) — a local pure function, not a new package export,
 * mirrors the rest of this codebase's "no abstraction before a second
 * consumer" convention. */
function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  const units = ['KB', 'MB', 'GB']
  let value = bytes / 1024
  let unitIndex = 0
  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024
    unitIndex += 1
  }
  return `${value.toFixed(value < 10 ? 1 : 0)} ${units[unitIndex]}`
}

async function loadFiles(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  loading.value = true
  error.value = null

  try {
    const res = await filesApi.list(agencyId, {
      page: tableOptions.value.page,
      per_page: tableOptions.value.itemsPerPage,
    })
    items.value = res.data
    totalItems.value = res.meta.total
  } catch {
    error.value = t('app.files.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void loadFiles()
})

watch(
  () => agencyStore.currentAgencyId,
  (id) => {
    if (id !== null) void loadFiles()
  },
)

function onTableUpdate(opts: { page: number; itemsPerPage: number }): void {
  tableOptions.value = opts
  void loadFiles()
}

function onUploaded(): void {
  uploadDialog.value = false
  tableOptions.value.page = 1
  void loadFiles()
}

function openDeleteDialog(file: AgencyFileResource): void {
  fileToDelete.value = file
  deleteDialog.value = true
  deleteError.value = null
}

function closeDeleteDialog(): void {
  deleteDialog.value = false
  fileToDelete.value = null
  deleteError.value = null
}

async function confirmDelete(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null || fileToDelete.value === null) return

  deleting.value = true
  deleteError.value = null

  try {
    await filesApi.delete(agencyId, fileToDelete.value.id)
    closeDeleteDialog()
    void loadFiles()
  } catch {
    // Covers both `agency_file.attached` (D5's future guard) and
    // `agency_file.delete_failed` (a failed object delete, object-first —
    // the row stays fully visible, matching the backend's failure shape).
    deleteError.value = t('app.files.errors.deleteFailed')
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <div data-test="agency-files-page">
    <div class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h5 ma-0" data-test="files-heading">{{ t('app.files.title') }}</h1>
      <v-btn
        color="primary"
        prepend-icon="mdi-upload"
        data-test="files-upload-btn"
        @click="uploadDialog = true"
      >
        {{ t('app.files.actions.upload') }}
      </v-btn>
    </div>

    <v-alert v-if="error" type="error" variant="tonal" class="mb-4" data-test="files-list-error">
      {{ error }}
    </v-alert>

    <template v-if="loading && items.length === 0">
      <v-skeleton-loader type="table" data-test="files-list-skeleton" />
    </template>

    <template v-else-if="!loading && items.length === 0 && !error">
      <CEmptyState
        data-test="files-empty-state"
        title-tag="h2"
        :title="t('app.files.empty.heading')"
        :body="t('app.files.empty.body')"
      >
        <template #icon>
          <v-icon icon="mdi-file-outline" size="64" color="medium-emphasis" />
        </template>
        <template #action>
          <v-btn color="primary" data-test="files-empty-cta" @click="uploadDialog = true">
            {{ t('app.files.empty.cta') }}
          </v-btn>
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
      data-test="files-table"
      @update:options="onTableUpdate"
    >
      <template #item.attributes.original_filename="{ item }">
        <div class="d-flex align-center ga-2">
          <v-icon icon="mdi-file-outline" size="20" color="medium-emphasis" />
          <span :data-test="`file-name-${item.id}`">{{ item.attributes.original_filename }}</span>
        </div>
      </template>

      <template #item.attributes.size_bytes="{ item }">
        <span :data-test="`file-size-${item.id}`">
          {{ formatFileSize(item.attributes.size_bytes) }}
        </span>
      </template>

      <template #item.relationships.uploaded_by="{ item }">
        <span :data-test="`file-uploaded-by-${item.id}`">
          {{
            item.relationships.uploaded_by?.data.attributes.name ?? t('app.files.unknownUploader')
          }}
        </span>
      </template>

      <template #item.attributes.created_at="{ item }">
        {{ formatDateTime(item.attributes.created_at, locale, { dateStyle: 'medium' }) }}
      </template>

      <template #item.actions="{ item }">
        <div class="d-flex justify-end ga-1">
          <v-btn
            icon="mdi-download-outline"
            size="small"
            variant="text"
            :disabled="item.attributes.download_url === null"
            :href="item.attributes.download_url ?? undefined"
            target="_blank"
            rel="noopener noreferrer"
            :aria-label="t('app.files.actions.download')"
            :data-test="`file-download-${item.id}`"
          />
          <v-btn
            v-if="canDelete"
            icon="mdi-delete-outline"
            size="small"
            variant="text"
            color="error"
            :aria-label="t('app.files.actions.delete')"
            :data-test="`file-delete-${item.id}`"
            @click="openDeleteDialog(item)"
          />
        </div>
      </template>
    </v-data-table-server>

    <AgencyFileUploadDialog
      v-model="uploadDialog"
      :agency-id="agencyStore.currentAgencyId ?? ''"
      @uploaded="onUploaded"
    />

    <v-dialog v-model="deleteDialog" max-width="440" data-test="file-delete-dialog">
      <v-card v-if="fileToDelete">
        <v-card-title class="text-h6 pa-4" data-test="file-delete-dialog-title">
          {{ t('app.files.delete.confirmTitle') }}
        </v-card-title>
        <v-card-text>
          <p data-test="file-delete-dialog-message">
            {{
              t('app.files.delete.confirmMessage', {
                name: fileToDelete.attributes.original_filename,
              })
            }}
          </p>
          <v-alert
            v-if="deleteError"
            type="error"
            variant="tonal"
            class="mt-2"
            data-test="file-delete-dialog-error"
          >
            {{ deleteError }}
          </v-alert>
        </v-card-text>
        <v-card-actions class="px-4 pb-4">
          <v-spacer />
          <v-btn
            variant="text"
            :disabled="deleting"
            data-test="file-delete-dialog-cancel"
            @click="closeDeleteDialog"
          >
            {{ t('app.files.delete.cancel') }}
          </v-btn>
          <v-btn
            color="error"
            variant="flat"
            :loading="deleting"
            :disabled="deleting"
            data-test="file-delete-dialog-confirm"
            @click="confirmDelete"
          >
            {{ t('app.files.delete.confirm') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>
