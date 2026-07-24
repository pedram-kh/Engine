<script setup lang="ts">
/**
 * DisconnectRelationDialog — admin relation-termination modal (AH-051 D-6/D-9).
 *
 * The platform's FIRST relation-termination surface. Renders a confirm dialog
 * with a REQUIRED `reason` textarea (min 10 — mirrors `AdminDisconnectRequest`
 * exactly so the UI catches it before a 422). The copy states the consequences
 * plainly (contact + messaging close, pools emptied, in-flight campaign work
 * survives, both parties notified) so a disconnect is never an accidental click.
 *
 * On confirm, emits `confirm` with the trimmed reason. The parent owns the API
 * call + reloads the connection list (mirror of RejectCreatorDialog).
 */

import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

defineOptions({ name: 'DisconnectRelationDialog' })

const props = defineProps<{
  modelValue: boolean
  isSaving: boolean
  errorKey: string | null
  creatorDisplayName: string
  agencyName: string
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'confirm', payload: { reason: string }): void
  (e: 'cancel'): void
}>()

const { t } = useI18n()

const REASON_MIN = 10
const REASON_MAX = 2000

const reason = ref('')

watch(
  () => props.modelValue,
  (open) => {
    if (open === true) {
      reason.value = ''
    }
  },
)

const trimmedLength = computed(() => reason.value.trim().length)

const canConfirm = computed<boolean>(() => {
  if (props.isSaving) return false
  if (trimmedLength.value < REASON_MIN) return false
  if (reason.value.length > REASON_MAX) return false
  return true
})

const errorText = computed(() => (props.errorKey === null ? null : t(props.errorKey)))

const reasonHint = computed(() =>
  t('admin.creators.detail.connections.disconnect.reason_hint', { count: REASON_MIN }),
)

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
    data-testid="admin-creator-disconnect-dialog"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6" data-testid="admin-creator-disconnect-dialog-title">
        {{
          t('admin.creators.detail.connections.disconnect.title', {
            name: creatorDisplayName,
            agency: agencyName,
          })
        }}
      </v-card-title>

      <v-card-text>
        <p class="text-body-2 mb-2">
          {{ t('admin.creators.detail.connections.disconnect.description') }}
        </p>

        <v-textarea
          v-model="reason"
          :label="t('admin.creators.detail.connections.disconnect.reason_label')"
          :hint="reasonHint"
          persistent-hint
          rows="4"
          auto-grow
          :counter="REASON_MAX"
          :maxlength="REASON_MAX"
          data-testid="admin-creator-disconnect-dialog-reason"
          required
        />

        <div
          v-if="errorText !== null"
          role="alert"
          class="disconnect-dialog__error"
          data-testid="admin-creator-disconnect-dialog-error"
        >
          {{ errorText }}
        </div>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn
          variant="text"
          :disabled="isSaving"
          data-testid="admin-creator-disconnect-dialog-cancel"
          @click="onCancel"
        >
          {{ t('admin.creators.detail.connections.disconnect.cancel') }}
        </v-btn>
        <v-btn
          color="error"
          :loading="isSaving"
          :disabled="!canConfirm"
          data-testid="admin-creator-disconnect-dialog-confirm"
          @click="onConfirm"
        >
          {{ t('admin.creators.detail.connections.disconnect.confirm') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.disconnect-dialog__error {
  margin-top: 8px;
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}
</style>
