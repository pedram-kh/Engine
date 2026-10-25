<script setup lang="ts">
/**
 * ErasureHoldDialog — place or release a legal hold on an account (AH-104c C2).
 *
 * A reason is MANDATORY for both verbs (the 10-character minimum mirrors
 * `ErasureHoldRequest::rules()`; the backend stays the trust boundary). The reason is kept in
 * the audit log for good, so the dialog says, above the field, that it must carry no personal
 * details. The parent owns the API call; this emits `confirm` with the trimmed reason.
 */

import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

defineOptions({ name: 'ErasureHoldDialog' })

const props = defineProps<{
  modelValue: boolean
  mode: 'hold' | 'release'
  isSaving: boolean
  errorKey: string | null
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'confirm', payload: { reason: string }): void
  (e: 'cancel'): void
}>()

const { t } = useI18n()

const REASON_MIN = 10
const REASON_MAX = 1000

const reason = ref('')

watch(
  () => props.modelValue,
  (open) => {
    if (open === true) reason.value = ''
  },
)

const canConfirm = computed<boolean>(() => {
  if (props.isSaving) return false
  const length = reason.value.trim().length
  return length >= REASON_MIN && reason.value.length <= REASON_MAX
})

const errorText = computed(() => (props.errorKey === null ? null : t(props.errorKey)))

function onConfirm(): void {
  if (!canConfirm.value) return
  emit('confirm', { reason: reason.value.trim() })
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
    data-testid="admin-erasure-hold-dialog"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6" data-testid="admin-erasure-hold-dialog-title">
        {{ t(`admin.compliance.erasures.dialog.${mode}.title`) }}
      </v-card-title>

      <v-card-text>
        <p class="text-body-2 mb-3">
          {{ t(`admin.compliance.erasures.dialog.${mode}.description`) }}
        </p>

        <p class="text-body-2 font-weight-medium mb-2" data-testid="admin-erasure-hold-dialog-pii">
          {{ t('admin.compliance.erasures.dialog.no_personal_details') }}
        </p>

        <v-textarea
          v-model="reason"
          :label="t('admin.compliance.erasures.dialog.reason_label')"
          :hint="t('admin.compliance.erasures.dialog.reason_hint', { count: REASON_MIN })"
          persistent-hint
          rows="3"
          auto-grow
          :counter="REASON_MAX"
          :maxlength="REASON_MAX"
          data-testid="admin-erasure-hold-dialog-reason"
          required
        />

        <div
          v-if="errorText !== null"
          role="alert"
          class="hold-dialog__error"
          data-testid="admin-erasure-hold-dialog-error"
        >
          {{ errorText }}
        </div>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn
          variant="text"
          :disabled="isSaving"
          data-testid="admin-erasure-hold-dialog-cancel"
          @click="onCancel"
        >
          {{ t('admin.compliance.erasures.dialog.cancel') }}
        </v-btn>
        <v-btn
          :color="mode === 'hold' ? 'warning' : 'primary'"
          :loading="isSaving"
          :disabled="!canConfirm"
          data-testid="admin-erasure-hold-dialog-confirm"
          @click="onConfirm"
        >
          {{ t(`admin.compliance.erasures.dialog.${mode}.confirm`) }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.hold-dialog__error {
  margin-top: 8px;
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}
</style>
