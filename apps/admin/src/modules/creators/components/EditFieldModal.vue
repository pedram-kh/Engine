<script setup lang="ts">
/**
 * EditFieldModal — admin per-field edit dialog (Sprint 3 Chunk 4 sub-step 9).
 *
 * One generic modal handles all 7 editable fields. The parent passes
 * the current value + the field's {@link EditFieldConfig}; the modal
 * renders the matching control (text / textarea / select / multi-select),
 * optionally requires a `reason` audit-metadata entry, and emits a
 * single `save` event with the new value (+ optional reason). The
 * parent owns the actual PATCH call so this component stays free of
 * `adminCreatorsApi` coupling — easier to unit-test, easier to reuse.
 *
 * Form lives in `apps/main` per Decision C1 (form-main, display-shared).
 * The admin SPA is the second form surface and renders its own input
 * shells via Vuetify directly here — the wizard's controls are inside
 * `Step2ProfileBasicsPage.vue` and not yet extracted as shared
 * components (deferred to a later sprint with the broader "creator
 * forms" surface package).
 *
 * The 422 error path renders the resolved i18n key from `useErrorMessage`
 * inline above the action row. We don't close on error.
 */

import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import type { AdminEditableField } from '../api/creators.api'
import type { EditFieldConfig } from '../config/field-edit.ts'

defineOptions({ name: 'EditFieldModal' })

const props = defineProps<{
  modelValue: boolean
  config: EditFieldConfig
  currentValue: unknown
  errorKey: string | null
  isSaving: boolean
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'save', payload: { field: AdminEditableField; value: unknown; reason: string | null }): void
  (e: 'cancel'): void
}>()

const { t } = useI18n()

const localText = ref<string>('')
const localSelect = ref<string | null>(null)
const localMulti = ref<string[]>([])
const localReason = ref<string>('')
const localError = ref<string | null>(null)

function hydrate(): void {
  localError.value = null
  localReason.value = ''
  const control = props.config.control
  const current = props.currentValue
  if (control.kind === 'text' || control.kind === 'textarea' || control.kind === 'region-text') {
    localText.value = typeof current === 'string' ? current : ''
  } else if (control.kind === 'select') {
    localSelect.value = typeof current === 'string' ? current : null
  } else if (control.kind === 'multi-select') {
    localMulti.value = Array.isArray(current) ? current.map(String) : []
  }
}

watch(
  () => [props.modelValue, props.config.field, props.currentValue],
  ([open]) => {
    if (open === true) {
      hydrate()
    }
  },
  { immediate: true },
)

const reasonRequired = computed(() => props.config.reasonRequired)
const reasonProvided = computed(() => localReason.value.trim() !== '')

const canSave = computed<boolean>(() => {
  if (props.isSaving) return false
  if (reasonRequired.value && !reasonProvided.value) return false

  const control = props.config.control
  if (control.kind === 'text') {
    return localText.value.trim() !== '' && localText.value.length <= control.maxLength
  }
  if (control.kind === 'textarea') {
    return localText.value.length <= control.maxLength
  }
  if (control.kind === 'region-text') {
    return localText.value.length <= control.maxLength
  }
  if (control.kind === 'select') {
    if (localSelect.value === null) return false
    if (localSelect.value.length !== 2) return false
    return true
  }
  if (control.kind === 'multi-select') {
    const n = localMulti.value.length
    if (n < control.minItems) return false
    if (control.maxItems !== null && n > control.maxItems) return false
    return true
  }
  return false
})

function buildPayload(): { value: unknown } | null {
  const control = props.config.control
  if (control.kind === 'text') {
    return { value: localText.value.trim() }
  }
  if (control.kind === 'textarea') {
    const v = localText.value
    if (v.trim() === '' && control.nullable) {
      return { value: null }
    }
    return { value: v }
  }
  if (control.kind === 'region-text') {
    const v = localText.value
    if (v.trim() === '' && control.nullable) {
      return { value: null }
    }
    return { value: v }
  }
  if (control.kind === 'select') {
    if (localSelect.value === null) return null
    return { value: localSelect.value }
  }
  if (control.kind === 'multi-select') {
    return { value: [...localMulti.value] }
  }
  return null
}

function onSave(): void {
  localError.value = null
  if (!canSave.value) return
  const payload = buildPayload()
  if (payload === null) return
  emit('save', {
    field: props.config.field,
    value: payload.value,
    reason: reasonProvided.value ? localReason.value.trim() : null,
  })
}

function onCancel(): void {
  emit('cancel')
  emit('update:modelValue', false)
}

const titleText = computed(() =>
  t('admin.creators.detail.edit.title', { field: t(props.config.labelKey) }),
)

const errorText = computed(() => {
  const key = localError.value ?? props.errorKey
  if (key === null) return null
  return t(key)
})

watch(
  () => props.errorKey,
  (value) => {
    if (value !== null) {
      localError.value = null
    }
  },
)
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="560"
    persistent
    data-testid="admin-creator-edit-modal"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6" data-testid="admin-creator-edit-modal-title">
        {{ titleText }}
      </v-card-title>

      <v-card-text>
        <v-text-field
          v-if="config.control.kind === 'text'"
          v-model="localText"
          :label="t(config.labelKey)"
          :counter="config.control.maxLength"
          :maxlength="config.control.maxLength"
          data-testid="admin-creator-edit-modal-text"
          required
        />

        <v-textarea
          v-else-if="config.control.kind === 'textarea'"
          v-model="localText"
          :label="t(config.labelKey)"
          :counter="config.control.maxLength"
          :maxlength="config.control.maxLength"
          :rows="config.control.rows"
          auto-grow
          data-testid="admin-creator-edit-modal-textarea"
        />

        <v-text-field
          v-else-if="config.control.kind === 'region-text'"
          v-model="localText"
          :label="t(config.labelKey)"
          :counter="config.control.maxLength"
          :maxlength="config.control.maxLength"
          data-testid="admin-creator-edit-modal-region"
        />

        <v-combobox
          v-else-if="config.control.kind === 'select'"
          v-model="localSelect"
          :items="config.control.options"
          item-title="label"
          item-value="value"
          :return-object="false"
          :label="t(config.labelKey)"
          :hint="
            config.control.allowCustomCode
              ? t('admin.creators.detail.edit.custom_code_hint')
              : undefined
          "
          persistent-hint
          data-testid="admin-creator-edit-modal-select"
        />

        <v-select
          v-else-if="config.control.kind === 'multi-select'"
          v-model="localMulti"
          :items="config.control.options"
          item-title="label"
          item-value="value"
          multiple
          chips
          :label="t(config.labelKey)"
          data-testid="admin-creator-edit-modal-multi"
        />

        <v-textarea
          v-if="config.reasonRequired"
          v-model="localReason"
          :label="t('admin.creators.detail.edit.reason_label')"
          :hint="t('admin.creators.detail.edit.reason_hint')"
          persistent-hint
          :counter="2000"
          :maxlength="2000"
          rows="3"
          data-testid="admin-creator-edit-modal-reason"
          required
        />

        <div
          v-if="errorText !== null"
          role="alert"
          class="edit-field-modal__error"
          data-testid="admin-creator-edit-modal-error"
        >
          {{ errorText }}
        </div>
      </v-card-text>

      <v-card-actions class="edit-field-modal__actions">
        <v-spacer />
        <v-btn
          variant="text"
          :disabled="isSaving"
          data-testid="admin-creator-edit-modal-cancel"
          @click="onCancel"
        >
          {{ t('admin.creators.detail.edit.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          :loading="isSaving"
          :disabled="!canSave"
          data-testid="admin-creator-edit-modal-save"
          @click="onSave"
        >
          {{ t('admin.creators.detail.edit.save') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.edit-field-modal__error {
  margin-top: 8px;
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}

.edit-field-modal__actions {
  padding: 12px 16px 16px;
}
</style>
