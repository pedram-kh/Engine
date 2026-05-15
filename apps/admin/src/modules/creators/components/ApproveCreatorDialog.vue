<script setup lang="ts">
/**
 * ApproveCreatorDialog — admin approve-application modal.
 *
 * Sprint 3 Chunk 4 sub-step 10. Renders a confirm dialog with an
 * optional `welcome_message` textarea. On Save, emits `confirm` with
 * the (trimmed) welcome message or null. The parent owns the actual
 * `adminCreatorsApi.approve()` call (mirror of EditFieldModal —
 * keeps the component decoupled from the API singleton so unit tests
 * can stub interactions cleanly).
 *
 * Backend contract: `welcome_message` ⟶ `creators.welcome_message`,
 * optional (sometimes / nullable / max 1000). Cf.
 * `AdminApproveCreatorRequest::rules()`.
 *
 * Idempotency-rule #6 surface: if the backend returns
 * `creator.already_approved` (409) the parent surfaces the error
 * here via `errorKey`.
 */

import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

defineOptions({ name: 'ApproveCreatorDialog' })

const props = defineProps<{
  modelValue: boolean
  isSaving: boolean
  errorKey: string | null
  creatorDisplayName: string
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'confirm', payload: { welcomeMessage: string | null }): void
  (e: 'cancel'): void
}>()

const { t } = useI18n()

const welcomeMessage = ref('')

watch(
  () => props.modelValue,
  (open) => {
    if (open === true) {
      welcomeMessage.value = ''
    }
  },
)

const canConfirm = computed<boolean>(() => {
  if (props.isSaving) return false
  return welcomeMessage.value.length <= 1000
})

const errorText = computed(() => (props.errorKey === null ? null : t(props.errorKey)))

function onConfirm(): void {
  if (!canConfirm.value) return
  const trimmed = welcomeMessage.value.trim()
  emit('confirm', { welcomeMessage: trimmed === '' ? null : trimmed })
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
    data-testid="admin-creator-approve-dialog"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6" data-testid="admin-creator-approve-dialog-title">
        {{ t('admin.creators.detail.approve.title', { name: creatorDisplayName }) }}
      </v-card-title>

      <v-card-text>
        <p class="text-body-2 mb-2">
          {{ t('admin.creators.detail.approve.description') }}
        </p>

        <v-textarea
          v-model="welcomeMessage"
          :label="t('admin.creators.detail.approve.welcome_label')"
          :hint="t('admin.creators.detail.approve.welcome_hint')"
          persistent-hint
          rows="4"
          auto-grow
          :counter="1000"
          :maxlength="1000"
          data-testid="admin-creator-approve-dialog-welcome"
        />

        <div
          v-if="errorText !== null"
          role="alert"
          class="approve-dialog__error"
          data-testid="admin-creator-approve-dialog-error"
        >
          {{ errorText }}
        </div>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn
          variant="text"
          :disabled="isSaving"
          data-testid="admin-creator-approve-dialog-cancel"
          @click="onCancel"
        >
          {{ t('admin.creators.detail.approve.cancel') }}
        </v-btn>
        <v-btn
          color="success"
          :loading="isSaving"
          :disabled="!canConfirm"
          data-testid="admin-creator-approve-dialog-confirm"
          @click="onConfirm"
        >
          {{ t('admin.creators.detail.approve.confirm') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.approve-dialog__error {
  margin-top: 8px;
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}
</style>
