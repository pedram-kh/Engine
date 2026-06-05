<script setup lang="ts">
/**
 * Agency attach-contract dialog (contract-bridge chunk) — issue a per-campaign
 * contract to an accepted assignment. Mirrors {@see ReinviteDialog}: narrow
 * dialog, per-field 422 binding, presigned PDF upload optional.
 */

import {
  ApiError,
  extractFieldErrors,
  uploadToPresignedUrl,
  type CampaignAssignmentResource,
} from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { campaignsApi } from '../api/campaigns.api'

type AttachField = 'title' | 'body_markdown' | 'body_pdf_path'

const props = defineProps<{
  modelValue: boolean
  agencyId: string
  campaignId: string
  assignment: CampaignAssignmentResource | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  success: []
}>()

const { t } = useI18n()

const title = ref('')
const bodyMarkdown = ref('')
const pdfPath = ref<string | null>(null)
const pdfFileName = ref<string | null>(null)
const pdfUploading = ref(false)
const fieldErrors = ref<Partial<Record<AttachField, readonly string[]>>>({})
const submitting = ref(false)

const canSubmit = computed(
  () =>
    title.value.trim() !== '' &&
    (bodyMarkdown.value.trim() !== '' || pdfPath.value !== null) &&
    !pdfUploading.value,
)

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      title.value = ''
      bodyMarkdown.value = ''
      pdfPath.value = null
      pdfFileName.value = null
      fieldErrors.value = {}
    }
  },
)

function close(): void {
  emit('update:modelValue', false)
}

async function onPdfSelected(files: File[] | File | null): Promise<void> {
  const file = files === null ? null : Array.isArray(files) ? (files[0] ?? null) : files
  const assignment = props.assignment
  if (file === null || assignment === null) return

  pdfUploading.value = true
  fieldErrors.value = {}
  try {
    const init = await campaignsApi.initContractMedia(
      props.agencyId,
      props.campaignId,
      assignment.id,
      { mime_type: file.type, declared_bytes: file.size },
    )
    await uploadToPresignedUrl(init.data.upload_url, file, { contentType: file.type })
    const complete = await campaignsApi.completeContractMedia(
      props.agencyId,
      props.campaignId,
      assignment.id,
      { upload_id: init.data.upload_id },
    )
    pdfPath.value = complete.data.storage_path
    pdfFileName.value = file.name
  } catch (err) {
    pdfPath.value = null
    pdfFileName.value = null
    if (err instanceof ApiError) {
      fieldErrors.value = extractFieldErrors<AttachField>(err)
    }
  } finally {
    pdfUploading.value = false
  }
}

async function submit(): Promise<void> {
  const assignment = props.assignment
  if (assignment === null || !canSubmit.value) return

  submitting.value = true
  fieldErrors.value = {}
  try {
    await campaignsApi.attachContract(props.agencyId, props.campaignId, assignment.id, {
      title: title.value.trim(),
      body_markdown: bodyMarkdown.value.trim() === '' ? null : bodyMarkdown.value.trim(),
      body_pdf_path: pdfPath.value,
    })
    emit('success')
    emit('update:modelValue', false)
  } catch (err) {
    if (err instanceof ApiError) {
      fieldErrors.value = extractFieldErrors<AttachField>(err)
    }
    if (Object.keys(fieldErrors.value).length === 0) {
      emit('update:modelValue', false)
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="520"
    data-test="attach-contract-dialog"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6">{{ t('app.campaigns.contract.attach.title') }}</v-card-title>
      <v-card-text class="d-flex flex-column ga-3">
        <p class="text-body-2 text-medium-emphasis" data-test="attach-contract-dialog-body">
          {{ t('app.campaigns.contract.attach.body') }}
        </p>
        <v-text-field
          v-model="title"
          density="compact"
          variant="outlined"
          :label="t('app.campaigns.contract.attach.titleLabel')"
          :error-messages="fieldErrors.title as string[]"
          data-test="attach-contract-title"
        />
        <v-textarea
          v-model="bodyMarkdown"
          density="compact"
          variant="outlined"
          rows="4"
          auto-grow
          :label="t('app.campaigns.contract.attach.termsLabel')"
          :hint="t('app.campaigns.contract.attach.termsHint')"
          persistent-hint
          :error-messages="fieldErrors.body_markdown as string[]"
          data-test="attach-contract-terms"
        />
        <v-file-input
          accept="application/pdf"
          density="compact"
          variant="outlined"
          prepend-icon="mdi-file-pdf-box"
          :label="t('app.campaigns.contract.attach.pdfLabel')"
          :loading="pdfUploading"
          :error-messages="fieldErrors.body_pdf_path as string[]"
          data-test="attach-contract-pdf"
          @update:model-value="onPdfSelected"
        />
        <p
          v-if="pdfFileName"
          class="text-caption text-medium-emphasis"
          data-test="attach-contract-pdf-name"
        >
          {{ t('app.campaigns.contract.attach.pdfReady', { name: pdfFileName }) }}
        </p>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" data-test="attach-contract-cancel" @click="close">
          {{ t('app.campaigns.contract.attach.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :disabled="!canSubmit"
          :loading="submitting"
          data-test="attach-contract-submit"
          @click="submit"
        >
          {{ t('app.campaigns.contract.attach.submit') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
