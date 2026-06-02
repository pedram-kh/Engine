<script setup lang="ts">
/**
 * VerifyIdentityDialog — admin manual-KYC-clearance modal.
 *
 * Sprint 4 Chunk 3 (D-c3-3, Cluster 4). Mirrors ApproveCreatorDialog:
 * a confirm dialog with an optional `note` textarea. On Save, emits
 * `confirm` with the (trimmed) note or null; the parent owns the
 * `adminCreatorsApi.verifyIdentity()` call. The note is recorded in the
 * audit metadata, not persisted to a column.
 *
 * This is a permanent, compliance-sensitive identity override — the copy
 * makes the audit/attribution consequence explicit.
 *
 * Idempotency-rule #6 surface: if the backend returns
 * `creator.kyc_already_verified` (409) the parent surfaces it via
 * `errorKey`.
 */

import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

defineOptions({ name: 'VerifyIdentityDialog' })

const props = defineProps<{
  modelValue: boolean
  isSaving: boolean
  errorKey: string | null
  creatorDisplayName: string
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'confirm', payload: { note: string | null }): void
  (e: 'cancel'): void
}>()

const { t } = useI18n()

const note = ref('')

watch(
  () => props.modelValue,
  (open) => {
    if (open === true) {
      note.value = ''
    }
  },
)

const canConfirm = computed<boolean>(() => {
  if (props.isSaving) return false
  return note.value.length <= 1000
})

const errorText = computed(() => (props.errorKey === null ? null : t(props.errorKey)))

function onConfirm(): void {
  if (!canConfirm.value) return
  const trimmed = note.value.trim()
  emit('confirm', { note: trimmed === '' ? null : trimmed })
}

function onCancel(): void {
  emit('cancel')
  emit('update:modelValue', false)
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="560"
    persistent
    data-testid="admin-creator-verify-dialog"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6" data-testid="admin-creator-verify-dialog-title">
        {{ t('admin.creators.detail.verify.title', { name: creatorDisplayName }) }}
      </v-card-title>

      <v-card-text>
        <p class="text-body-2 mb-2">
          {{ t('admin.creators.detail.verify.description') }}
        </p>

        <v-textarea
          v-model="note"
          :label="t('admin.creators.detail.verify.note_label')"
          :hint="t('admin.creators.detail.verify.note_hint')"
          persistent-hint
          rows="3"
          auto-grow
          :counter="1000"
          :maxlength="1000"
          data-testid="admin-creator-verify-dialog-note"
        />

        <div
          v-if="errorText !== null"
          role="alert"
          class="verify-dialog__error"
          data-testid="admin-creator-verify-dialog-error"
        >
          {{ errorText }}
        </div>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn
          variant="text"
          :disabled="isSaving"
          data-testid="admin-creator-verify-dialog-cancel"
          @click="onCancel"
        >
          {{ t('admin.creators.detail.verify.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          :loading="isSaving"
          :disabled="!canConfirm"
          data-testid="admin-creator-verify-dialog-confirm"
          @click="onConfirm"
        >
          {{ t('admin.creators.detail.verify.confirm') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.verify-dialog__error {
  margin-top: 8px;
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}
</style>
