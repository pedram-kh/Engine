<script setup lang="ts">
/**
 * RejectCreatorDialog — admin reject-application modal.
 *
 * Sprint 3 Chunk 4 sub-step 10. Renders a confirm dialog with a
 * REQUIRED `rejection_reason` textarea. The 10-char minimum mirrors
 * `AdminRejectCreatorRequest::rules()` exactly — UI catches it
 * upfront rather than via a 422 round-trip. Backend remains the
 * trust boundary.
 *
 * On Save, emits `confirm` with the trimmed rejection_reason. The
 * parent owns the API call (mirror of ApproveCreatorDialog +
 * EditFieldModal).
 *
 * Idempotency-rule #6 surface: if the backend returns
 * `creator.already_rejected` (409) the parent surfaces the error
 * here via `errorKey`.
 */

import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

defineOptions({ name: 'RejectCreatorDialog' })

const props = defineProps<{
  modelValue: boolean
  isSaving: boolean
  errorKey: string | null
  creatorDisplayName: string
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'confirm', payload: { rejectionReason: string }): void
  (e: 'cancel'): void
}>()

const { t } = useI18n()

const REJECTION_REASON_MIN = 10
const REJECTION_REASON_MAX = 2000

const rejectionReason = ref('')

watch(
  () => props.modelValue,
  (open) => {
    if (open === true) {
      rejectionReason.value = ''
    }
  },
)

const trimmedLength = computed(() => rejectionReason.value.trim().length)

const canConfirm = computed<boolean>(() => {
  if (props.isSaving) return false
  if (trimmedLength.value < REJECTION_REASON_MIN) return false
  if (rejectionReason.value.length > REJECTION_REASON_MAX) return false
  return true
})

const errorText = computed(() => (props.errorKey === null ? null : t(props.errorKey)))

const minHint = computed(() =>
  t('admin.creators.detail.reject.min_hint', { count: REJECTION_REASON_MIN }),
)

function onConfirm(): void {
  if (!canConfirm.value) return
  emit('confirm', { rejectionReason: rejectionReason.value.trim() })
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
    data-testid="admin-creator-reject-dialog"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6" data-testid="admin-creator-reject-dialog-title">
        {{ t('admin.creators.detail.reject.title', { name: creatorDisplayName }) }}
      </v-card-title>

      <v-card-text>
        <p class="text-body-2 mb-2">
          {{ t('admin.creators.detail.reject.description') }}
        </p>

        <v-textarea
          v-model="rejectionReason"
          :label="t('admin.creators.detail.reject.reason_label')"
          :hint="minHint"
          persistent-hint
          rows="4"
          auto-grow
          :counter="REJECTION_REASON_MAX"
          :maxlength="REJECTION_REASON_MAX"
          data-testid="admin-creator-reject-dialog-reason"
          required
        />

        <div
          v-if="errorText !== null"
          role="alert"
          class="reject-dialog__error"
          data-testid="admin-creator-reject-dialog-error"
        >
          {{ errorText }}
        </div>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn
          variant="text"
          :disabled="isSaving"
          data-testid="admin-creator-reject-dialog-cancel"
          @click="onCancel"
        >
          {{ t('admin.creators.detail.reject.cancel') }}
        </v-btn>
        <v-btn
          color="error"
          :loading="isSaving"
          :disabled="!canConfirm"
          data-testid="admin-creator-reject-dialog-confirm"
          @click="onConfirm"
        >
          {{ t('admin.creators.detail.reject.confirm') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.reject-dialog__error {
  margin-top: 8px;
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}
</style>
