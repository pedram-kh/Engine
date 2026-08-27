<script lang="ts">
/**
 * The three operations this dialog serves. Declared in a plain script block
 * because `<script setup>` cannot carry named exports.
 */
export type MemberMfaOperation = 'reset' | 'exempt' | 'remove_exemption'
</script>

<script setup lang="ts">
/**
 * MemberMfaActionDialog — the confirm-with-reason modal for all three
 * per-member 2FA operations (AH-087).
 *
 * ONE dialog parameterized by `operation` rather than three near-identical
 * components (the AH-086b parameterization pattern): the three differ only in
 * copy and button colour, and the reason field, the min-length gate, and the
 * error slot are identical. `REASON_MIN` mirrors MemberMfaActionRequest
 * exactly so the UI catches a short reason before a 422.
 *
 * The copy for each operation states the consequence plainly — a security
 * downgrade should never be an accidental click, and neither should wiping
 * someone's authenticator.
 */

import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

defineOptions({ name: 'MemberMfaActionDialog' })

const props = defineProps<{
  modelValue: boolean
  operation: MemberMfaOperation
  memberName: string
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
const REASON_MAX = 2000

const reason = ref('')

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
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

const copyKey = computed(() => `admin.agencies.members.actions.${props.operation}`)

const confirmColor = computed(() => {
  if (props.operation === 'exempt') return 'error'
  if (props.operation === 'remove_exemption') return 'success'
  return 'warning'
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
    data-testid="admin-member-mfa-dialog"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6" data-testid="admin-member-mfa-dialog-title">
        {{ t(`${copyKey}.title`, { name: memberName }) }}
      </v-card-title>

      <v-card-text>
        <p class="text-body-2 mb-3">{{ t(`${copyKey}.description`) }}</p>

        <v-textarea
          v-model="reason"
          :label="t('admin.agencies.members.actions.reason_label')"
          :hint="t('admin.agencies.members.actions.reason_hint', { count: REASON_MIN })"
          persistent-hint
          rows="3"
          auto-grow
          variant="outlined"
          :counter="REASON_MAX"
          :maxlength="REASON_MAX"
          data-testid="admin-member-mfa-dialog-reason"
          required
        />

        <div
          v-if="errorText !== null"
          role="alert"
          class="member-mfa-dialog__error"
          data-testid="admin-member-mfa-dialog-error"
        >
          {{ errorText }}
        </div>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn
          variant="text"
          :disabled="isSaving"
          data-testid="admin-member-mfa-dialog-cancel"
          @click="onCancel"
        >
          {{ t('admin.agencies.members.actions.cancel') }}
        </v-btn>
        <v-btn
          :color="confirmColor"
          variant="flat"
          :loading="isSaving"
          :disabled="!canConfirm"
          data-testid="admin-member-mfa-dialog-confirm"
          @click="onConfirm"
        >
          {{ t(`${copyKey}.confirm`) }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.member-mfa-dialog__error {
  margin-top: 8px;
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}
</style>
