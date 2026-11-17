<script setup lang="ts">
/**
 * PL-1.1 — the Client files panel on the brand page: a drop zone, an Upload button, and a table of the
 * brand's files (name, type, uploaded by, date, a row menu with Download and Delete). Rendered only while
 * `features.waves` is on.
 *
 * Staff see the list and can download; Upload, the drop zone and Delete are for an admin or manager on an
 * active brand. Downloads use the signed URL the list carries (60 minutes, forced attachment).
 */
import { formatDateTime, formatFileSize } from '@catalyst/api-client'
import type { BrandFileResource } from '@catalyst/api-client'
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { brandFilesApi } from '../api/brandFiles.api'
import BrandFileUploadDialog from './BrandFileUploadDialog.vue'

const props = defineProps<{
  agencyId: string
  brandId: string
  /** Admin or manager, and the brand is active. */
  canWrite: boolean
}>()

const { t, locale } = useI18n()

const files = ref<BrandFileResource[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const actionError = ref<string | null>(null)

const uploadOpen = ref(false)
const droppedFiles = ref<File[]>([])
const dragging = ref(false)

const deleteTarget = ref<BrandFileResource | null>(null)
const deleting = ref(false)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await brandFilesApi.list(props.agencyId, props.brandId, { per_page: 100 })
    files.value = res.data
  } catch {
    error.value = t('app.brandFiles.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

function openUpload(): void {
  droppedFiles.value = []
  uploadOpen.value = true
}

function onDrop(event: DragEvent): void {
  dragging.value = false
  if (!props.canWrite) return
  const dropped = Array.from(event.dataTransfer?.files ?? [])
  if (dropped.length === 0) return
  droppedFiles.value = dropped
  uploadOpen.value = true
}

function onUploaded(file: BrandFileResource): void {
  files.value = [file, ...files.value]
}

function download(file: BrandFileResource): void {
  actionError.value = null
  const url = file.attributes.download_url
  if (url === null) {
    actionError.value = t('app.brandFiles.errors.downloadFailed')
    return
  }
  window.open(url, '_blank', 'noopener')
}

async function confirmDelete(): Promise<void> {
  const target = deleteTarget.value
  if (target === null) return
  deleting.value = true
  actionError.value = null
  try {
    await brandFilesApi.delete(props.agencyId, props.brandId, target.id)
    files.value = files.value.filter((f) => f.id !== target.id)
    deleteTarget.value = null
  } catch {
    actionError.value = t('app.brandFiles.errors.deleteFailed')
    deleteTarget.value = null
  } finally {
    deleting.value = false
  }
}

onMounted(load)
defineExpose({ load })
</script>

<template>
  <section class="agency-card" data-test="brand-files-panel">
    <div class="agency-panel__head">
      <div>
        <h2 class="agency-card__title">{{ t('app.brandFiles.title') }}</h2>
        <p class="agency-panel__subtitle">{{ t('app.brandFiles.subtitle') }}</p>
      </div>
      <v-btn
        v-if="canWrite"
        variant="outlined"
        prepend-icon="mdi-upload"
        data-test="brand-files-upload-btn"
        @click="openUpload"
      >
        {{ t('app.brandFiles.upload') }}
      </v-btn>
    </div>

    <div
      v-if="canWrite"
      class="brand-files-dropzone pa-4 text-center text-body-2 text-medium-emphasis rounded-lg"
      :class="{ 'brand-files-dropzone--over': dragging }"
      data-test="brand-files-dropzone"
      @dragover.prevent="dragging = true"
      @dragleave="dragging = false"
      @drop.prevent="onDrop"
    >
      {{ t('app.brandFiles.dropZone') }}
    </div>

    <v-alert v-if="actionError" type="error" variant="tonal" data-test="brand-files-action-error">
      {{ actionError }}
    </v-alert>

    <v-skeleton-loader v-if="loading" type="table-row@2" data-test="brand-files-loading" />
    <v-alert v-else-if="error" type="error" variant="tonal" data-test="brand-files-error">
      {{ error }}
    </v-alert>
    <p v-else-if="files.length === 0" class="agency-panel__empty" data-test="brand-files-empty">
      {{ t('app.brandFiles.empty') }}
    </p>
    <table v-else class="agency-panel__table" data-test="brand-files-table">
      <thead>
        <tr>
          <th>{{ t('app.files.fields.name') }}</th>
          <th>{{ t('app.brandFiles.type') }}</th>
          <th>{{ t('app.files.fields.uploadedBy') }}</th>
          <th>{{ t('app.files.fields.uploadedAt') }}</th>
          <th />
        </tr>
      </thead>
      <tbody>
        <tr v-for="f in files" :key="f.id" :data-test="`brand-file-row-${f.id}`">
          <td>
            <div class="font-weight-medium">{{ f.attributes.original_filename }}</div>
            <div class="text-caption text-medium-emphasis">
              {{ formatFileSize(f.attributes.size_bytes) }}
            </div>
          </td>
          <td>{{ t(`app.brandFiles.types.${f.attributes.file_type}`) }}</td>
          <td>
            {{
              f.relationships.uploaded_by?.data.attributes.name ?? t('app.files.unknownUploader')
            }}
          </td>
          <td>{{ formatDateTime(f.attributes.created_at, locale, { dateStyle: 'medium' }) }}</td>
          <td class="text-right">
            <v-menu>
              <template #activator="{ props: menuProps }">
                <v-btn
                  v-bind="menuProps"
                  icon="mdi-dots-vertical"
                  variant="text"
                  size="small"
                  :aria-label="t('app.brandFiles.actions')"
                  :data-test="`brand-file-menu-${f.id}`"
                />
              </template>
              <v-list density="compact">
                <v-list-item
                  :title="t('app.files.actions.download')"
                  :data-test="`brand-file-download-${f.id}`"
                  @click="download(f)"
                />
                <v-list-item
                  v-if="canWrite"
                  :title="t('app.files.actions.delete')"
                  :data-test="`brand-file-delete-${f.id}`"
                  @click="deleteTarget = f"
                />
              </v-list>
            </v-menu>
          </td>
        </tr>
      </tbody>
    </table>

    <BrandFileUploadDialog
      v-model="uploadOpen"
      :agency-id="agencyId"
      :brand-id="brandId"
      :initial-files="droppedFiles"
      @uploaded="onUploaded"
    />

    <v-dialog
      :model-value="deleteTarget !== null"
      max-width="440"
      data-test="brand-file-delete-dialog"
      @update:model-value="(v: boolean) => !v && !deleting && (deleteTarget = null)"
    >
      <v-card v-if="deleteTarget">
        <v-card-title class="text-h6 pa-4">{{ t('app.files.delete.confirmTitle') }}</v-card-title>
        <v-card-text>
          {{
            t('app.files.delete.confirmMessage', {
              name: deleteTarget.attributes.original_filename,
            })
          }}
        </v-card-text>
        <v-card-actions class="px-4 pb-4">
          <v-spacer />
          <v-btn variant="text" :disabled="deleting" @click="deleteTarget = null">
            {{ t('app.files.delete.cancel') }}
          </v-btn>
          <v-btn
            color="error"
            variant="flat"
            :loading="deleting"
            data-test="brand-file-delete-confirm"
            @click="confirmDelete"
          >
            {{ t('app.files.delete.confirm') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </section>
</template>

<style scoped>
.brand-files-dropzone {
  border: 1px dashed rgb(var(--v-theme-border-color));
}

.brand-files-dropzone--over {
  border-color: rgb(var(--v-theme-primary));
}
</style>
