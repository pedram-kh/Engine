<script setup lang="ts">
/**
 * PL-1.1 — the client-files upload dialog. No design frame exists for it; it is built from the design
 * system's dialog and field styles, on the AgencyFileUploadDialog pattern (presigned init, PUT, complete).
 *
 * Several files can be chosen (or dropped on the panel, which opens the dialog with them). They go one at a
 * time, each with its own outcome line. A file that is over 100 MB or of a type the server will not take is
 * refused here, before any bytes move; the server checks again on init.
 *
 * Uploads here have not been run against real object storage yet (see the PL-1.1 review); the unit tests
 * run the three calls against mocks.
 */
import { ApiError, uploadToPresignedUrl } from '@catalyst/api-client'
import type { BrandFileResource, BrandFileType } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { brandFilesApi } from '../api/brandFiles.api'

/** Mirrors `BrandFileUploadService`: non-video documents and images, plus ZIP. */
const ACCEPTED_MIME_TYPES = [
  'image/jpeg',
  'image/png',
  'image/webp',
  'image/gif',
  'application/pdf',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.ms-excel',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  'text/plain',
  'text/csv',
  'application/zip',
]
const MAX_BYTES = 100 * 1024 * 1024
const FILE_TYPES: BrandFileType[] = ['brief', 'research', 'guidelines', 'assets', 'other']

const props = defineProps<{
  modelValue: boolean
  agencyId: string
  brandId: string
  /** Files handed over by a drop on the panel. */
  initialFiles?: File[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  uploaded: [file: BrandFileResource]
}>()

const { t } = useI18n()

interface Outcome {
  name: string
  state: 'uploading' | 'done' | 'failed'
  message: string | null
  progress: number
}

const fileType = ref<BrandFileType>('other')
const outcomes = ref<Outcome[]>([])
const busy = ref(false)

const typeItems = computed(() =>
  FILE_TYPES.map((v) => ({ value: v, title: t(`app.brandFiles.types.${v}`) })),
)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    outcomes.value = []
    busy.value = false
    fileType.value = 'other'
    if (props.initialFiles && props.initialFiles.length > 0) {
      void uploadFiles(props.initialFiles)
    }
  },
  { immediate: true },
)

function refusal(file: File): string | null {
  if (file.size > MAX_BYTES) return t('app.brandFiles.errors.tooLarge', { name: file.name })
  if (!ACCEPTED_MIME_TYPES.includes(file.type)) {
    return t('app.brandFiles.errors.unsupported', { name: file.name })
  }
  return null
}

async function uploadOne(file: File, type: BrandFileType, outcome: Outcome): Promise<void> {
  const refused = refusal(file)
  if (refused !== null) {
    outcome.state = 'failed'
    outcome.message = refused
    return
  }
  try {
    const init = await brandFilesApi.initUpload(props.agencyId, props.brandId, {
      mime_type: file.type,
      declared_bytes: file.size,
      file_type: type,
    })
    await uploadToPresignedUrl(init.data.upload_url, file, {
      contentType: file.type,
      onProgress: (percent) => {
        outcome.progress = percent
      },
    })
    const complete = await brandFilesApi.completeUpload(props.agencyId, props.brandId, {
      upload_id: init.data.upload_id,
      original_filename: file.name,
      file_type: type,
    })
    outcome.state = 'done'
    outcome.message = t('app.brandFiles.uploadDialog.success', { name: file.name })
    emit('uploaded', complete.data)
  } catch (err) {
    outcome.state = 'failed'
    if (err instanceof ApiError && err.code === 'brand_file.brand_archived') {
      outcome.message = t('app.brandFiles.errors.brandArchived')
    } else if (err instanceof ApiError && err.code === 'brand_file.presign_failed') {
      outcome.message = t('app.brandFiles.errors.unsupported', { name: file.name })
    } else {
      outcome.message = t('app.brandFiles.errors.uploadFailed', { name: file.name })
    }
  }
}

async function uploadFiles(files: File[], type: BrandFileType = fileType.value): Promise<void> {
  if (files.length === 0 || busy.value) return
  busy.value = true
  const rows = files.map<Outcome>((f) => ({
    name: f.name,
    state: 'uploading',
    message: null,
    progress: 0,
  }))
  outcomes.value = rows
  for (const [i, file] of files.entries()) {
    const row = outcomes.value[i]
    if (row) await uploadOne(file, type, row)
  }
  busy.value = false
}

function onSelected(files: File[] | File | null): void {
  const list = files === null ? [] : Array.isArray(files) ? files : [files]
  void uploadFiles(list)
}

function close(): void {
  if (busy.value) return
  emit('update:modelValue', false)
}

// Exposed for unit tests: VFileInput is hostile to programmatic selection in JSDOM.
defineExpose({ uploadFiles })
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="520"
    persistent
    data-test="brand-file-upload-dialog"
    @update:model-value="
      (v: boolean) => {
        if (!busy) emit('update:modelValue', v)
      }
    "
  >
    <v-card>
      <v-card-title class="text-h6 pt-4 px-6">{{
        t('app.brandFiles.uploadDialog.title')
      }}</v-card-title>
      <v-card-text class="d-flex flex-column ga-3 px-6">
        <p class="text-body-2 text-medium-emphasis ma-0">
          {{ t('app.brandFiles.uploadDialog.body') }}
        </p>
        <v-select
          v-model="fileType"
          :items="typeItems"
          item-title="title"
          item-value="value"
          :label="t('app.brandFiles.uploadDialog.typeLabel')"
          variant="outlined"
          density="compact"
          hide-details
          :disabled="busy"
          data-test="brand-file-type"
        />
        <v-file-input
          multiple
          :accept="ACCEPTED_MIME_TYPES.join(',')"
          density="compact"
          variant="outlined"
          prepend-icon="mdi-file-upload-outline"
          :label="t('app.brandFiles.uploadDialog.choose')"
          :disabled="busy"
          hide-details
          data-test="brand-file-input"
          @update:model-value="onSelected"
        />
        <ul v-if="outcomes.length > 0" class="ma-0 pa-0" style="list-style: none">
          <li
            v-for="(o, i) in outcomes"
            :key="i"
            class="mb-2"
            :data-test="`brand-file-outcome-${o.state}`"
          >
            <template v-if="o.state === 'uploading'">
              <div class="text-body-2">
                {{ t('app.brandFiles.uploadDialog.uploading', { name: o.name }) }}
              </div>
              <v-progress-linear :model-value="o.progress" color="primary" height="6" rounded />
            </template>
            <div
              v-else
              class="text-body-2"
              :class="o.state === 'failed' ? 'text-error' : 'text-success'"
            >
              {{ o.message }}
            </div>
          </li>
        </ul>
      </v-card-text>
      <v-card-actions class="px-6 pb-4">
        <v-spacer />
        <v-btn variant="text" :disabled="busy" data-test="brand-file-upload-close" @click="close">
          {{ t('app.brandFiles.uploadDialog.done') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
