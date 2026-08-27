<script setup lang="ts">
/**
 * Agency Files upload dialog (AH-091, chunk A) — mirrors
 * {@see AttachContractDialog}'s `v-file-input` precedent: no drag-drop, a
 * single file selection auto-starts the three-call presigned flow
 * (init → PUT → complete), with a determinate progress bar and inline error
 * surfacing.
 *
 * Unlike the contract dialog, there is no surrounding form — selecting a
 * file IS the submit action. A validation-shaped failure (unsupported MIME,
 * over the 25 MB cap) surfaces on `init`, before any bytes move; a
 * completion failure surfaces after the PUT lands. Both render as the same
 * inline `v-file-input` error rather than a field-scoped map, since the
 * backend's `agency_file.*` codes are single top-level errors, not
 * per-field 422s (D2's service is not a form).
 */

import { ApiError, uploadToPresignedUrl, type AgencyFileResource } from '@catalyst/api-client'
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { filesApi } from '../api/files.api'

/** {@see AttachmentMimeTypes::NON_VIDEO} on the backend — images + documents, no video (D3). */
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
].join(',')

const props = defineProps<{
  modelValue: boolean
  agencyId: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  uploaded: [file: AgencyFileResource]
}>()

const { t } = useI18n()

const uploading = ref(false)
const uploadProgress = ref(0)
const errorMessage = ref<string | null>(null)

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      uploading.value = false
      uploadProgress.value = 0
      errorMessage.value = null
    }
  },
)

function close(): void {
  if (uploading.value) return
  emit('update:modelValue', false)
}

async function onFileSelected(files: File[] | File | null): Promise<void> {
  const file = files === null ? null : Array.isArray(files) ? (files[0] ?? null) : files
  if (file === null) return

  uploading.value = true
  uploadProgress.value = 0
  errorMessage.value = null

  try {
    const init = await filesApi.initUpload(props.agencyId, {
      mime_type: file.type,
      declared_bytes: file.size,
    })

    await uploadToPresignedUrl(init.data.upload_url, file, {
      contentType: file.type,
      onProgress: (percent) => {
        uploadProgress.value = percent
      },
    })

    const complete = await filesApi.completeUpload(props.agencyId, {
      upload_id: init.data.upload_id,
      original_filename: file.name,
    })

    emit('uploaded', complete.data)
    emit('update:modelValue', false)
  } catch (err) {
    if (err instanceof ApiError && err.code === 'agency_file.presign_failed') {
      errorMessage.value = t('app.files.errors.unsupportedOrTooLarge')
    } else {
      errorMessage.value = t('app.files.errors.uploadFailed')
    }
  } finally {
    uploading.value = false
  }
}

// Exposed for unit tests — Vuetify's VFileInput is hostile to programmatic
// file selection in JSDOM (the BulkInvitePage precedent). Driving this
// handler directly keeps the test focused on the upload dance itself.
defineExpose({ onFileSelected })
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="480"
    persistent
    data-test="file-upload-dialog"
    @update:model-value="
      (v: boolean) => {
        if (!uploading) emit('update:modelValue', v)
      }
    "
  >
    <v-card>
      <v-card-title class="text-h6">{{ t('app.files.upload.title') }}</v-card-title>
      <v-card-text class="d-flex flex-column ga-3">
        <p class="text-body-2 text-medium-emphasis" data-test="file-upload-body">
          {{ t('app.files.upload.body') }}
        </p>
        <v-file-input
          :accept="ACCEPTED_MIME_TYPES"
          density="compact"
          variant="outlined"
          prepend-icon="mdi-file-upload-outline"
          :label="t('app.files.upload.label')"
          :loading="uploading"
          :disabled="uploading"
          :error-messages="errorMessage ? [errorMessage] : []"
          data-test="file-upload-input"
          @update:model-value="onFileSelected"
        />
        <v-progress-linear
          v-if="uploading"
          :model-value="uploadProgress"
          color="primary"
          height="6"
          rounded
          data-test="file-upload-progress"
        />
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" :disabled="uploading" data-test="file-upload-cancel" @click="close">
          {{ t('app.files.upload.cancel') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
