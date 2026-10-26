<script setup lang="ts">
/**
 * ErasureEraseDialog — the Erase click (AH-104c C3).
 *
 * The admin sees the plan first: how many rows, files and assignments the erasure will touch,
 * as numbers (nothing in the plan names a person). Then three confirmations stand between the
 * click and the run: the typed account id, a reason, and a fresh two-factor code. The danger
 * button stays disabled until the plan is loaded and ready and all three are filled in. The
 * parent owns every API call; this emits `confirm` and shows what it is given.
 *
 * The 2FA code is cleared whenever the dialog opens and after every attempt: a code is typed
 * for one click. The digest sent is the one of the plan on screen, so a plan that changed
 * under the admin is refused by the job rather than erased on stale numbers.
 */

import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import type { ErasurePlan } from '../api/compliance.api'
import AccountIdCopy from './AccountIdCopy.vue'

defineOptions({ name: 'ErasureEraseDialog' })

const props = defineProps<{
  modelValue: boolean
  /** The account being erased: its ulid is what must be typed. */
  userUlid: string | null
  plan: ErasurePlan | null
  planLoading: boolean
  planErrorKey: string | null
  isSaving: boolean
  errorKey: string | null
  /** The codes of a refused click, so the reasons can be read out. */
  errorCodes: string[]
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'confirm', payload: { ulid: string; reason: string; digest: string; mfaCode: string }): void
  (e: 'cancel'): void
}>()

const { t, te } = useI18n()

const REASON_MIN = 10
const REASON_MAX = 1000
const DIGEST_SHOWN = 12
/** Not an object count: the plan reports the keys it will leave alone under this name. */
const SKIPPED_KEY = 'skipped_outside_own_prefix'

const typedUlid = ref('')
const reason = ref('')
const mfaCode = ref('')

watch(
  () => props.modelValue,
  (open) => {
    if (open === true) {
      typedUlid.value = ''
      reason.value = ''
      mfaCode.value = ''
    }
  },
)

// A code is for one click: whatever the outcome, the field is emptied once an attempt ends.
watch(
  () => props.isSaving,
  (saving, was) => {
    if (was === true && saving === false) mfaCode.value = ''
  },
)

const attributes = computed(() => props.plan?.attributes ?? null)

const summary = computed(() => {
  const a = attributes.value
  if (a === null) return null
  const lines = a.database.filter((line) => line.count > 0)
  return {
    rows: lines.reduce((sum, line) => sum + line.count, 0),
    tables: new Set(lines.map((line) => line.table)).size,
    files: Object.entries(a.files)
      .filter(([key]) => key !== SKIPPED_KEY)
      .reduce((sum, [, n]) => sum + n, 0),
    assignments: a.assignments_to_cancel,
    read: a.cost.rows,
    reference: a.digest.slice(0, DIGEST_SHOWN),
  }
})

function reasonText(code: string): string {
  for (const group of ['reason', 'blocker']) {
    const key = `admin.compliance.erasures.${group}.${code}`
    if (te(key)) return t(key)
  }
  return t('admin.compliance.erasures.reason.not_available')
}

const notReadyReasons = computed<string | null>(() => {
  const a = attributes.value
  if (a === null || a.ready) return null
  const codes = [...a.gate, ...a.blockers]
  return codes.map(reasonText).join('; ')
})

const refusedReasons = computed<string | null>(() =>
  props.errorCodes.length === 0 ? null : props.errorCodes.map(reasonText).join('; '),
)

const isRetry = computed<boolean>(() => attributes.value?.run?.can_retry === true)

const ulidMatches = computed<boolean>(
  () =>
    props.userUlid !== null &&
    typedUlid.value.trim().toUpperCase() === props.userUlid.toUpperCase(),
)

const canConfirm = computed<boolean>(() => {
  const a = attributes.value
  if (props.isSaving || props.planLoading || a === null || !a.ready) return false
  if (!ulidMatches.value) return false
  const length = reason.value.trim().length
  if (length < REASON_MIN || reason.value.length > REASON_MAX) return false
  return mfaCode.value.trim().length > 0
})

const planErrorText = computed(() => (props.planErrorKey === null ? null : t(props.planErrorKey)))
const errorText = computed(() => (props.errorKey === null ? null : t(props.errorKey)))

function onConfirm(): void {
  const a = attributes.value
  if (!canConfirm.value || a === null) return
  emit('confirm', {
    ulid: typedUlid.value.trim().toUpperCase(),
    reason: reason.value.trim(),
    digest: a.digest,
    mfaCode: mfaCode.value.trim(),
  })
}

function onCancel(): void {
  emit('cancel')
  emit('update:modelValue', false)
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="640"
    persistent
    scrollable
    data-testid="admin-erasure-erase-dialog"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6" data-testid="admin-erasure-erase-dialog-title">
        {{ t('admin.compliance.erasures.dialog.erase.title') }}
      </v-card-title>

      <v-card-text>
        <p class="text-body-2 mb-3" data-testid="admin-erasure-erase-dialog-warning">
          {{ t('admin.compliance.erasures.dialog.erase.warning') }}
        </p>

        <p
          v-if="isRetry"
          class="text-body-2 font-weight-medium mb-3"
          data-testid="admin-erasure-erase-dialog-retry"
        >
          {{ t('admin.compliance.erasures.dialog.erase.retry_note') }}
        </p>

        <p
          v-if="planLoading"
          class="text-body-2 mb-3"
          data-testid="admin-erasure-erase-dialog-loading"
        >
          {{ t('admin.compliance.erasures.dialog.erase.loading') }}
        </p>

        <div
          v-if="planErrorText !== null"
          role="alert"
          class="erase-dialog__error"
          data-testid="admin-erasure-erase-dialog-plan-error"
        >
          {{ planErrorText }}
        </div>

        <div
          v-if="notReadyReasons !== null"
          role="alert"
          class="erase-dialog__error"
          data-testid="admin-erasure-erase-dialog-not-ready"
        >
          {{ t('admin.compliance.erasures.dialog.erase.not_ready', { reasons: notReadyReasons }) }}
        </div>

        <ul
          v-if="summary !== null"
          class="erase-dialog__plan"
          data-testid="admin-erasure-erase-plan"
        >
          <li data-testid="admin-erasure-erase-plan-rows">
            {{
              t('admin.compliance.erasures.dialog.erase.summary.rows', {
                rows: summary.rows,
                tables: summary.tables,
              })
            }}
          </li>
          <li data-testid="admin-erasure-erase-plan-files">
            {{
              t('admin.compliance.erasures.dialog.erase.summary.files', { files: summary.files })
            }}
          </li>
          <li data-testid="admin-erasure-erase-plan-assignments">
            {{
              t('admin.compliance.erasures.dialog.erase.summary.assignments', {
                count: summary.assignments,
              })
            }}
          </li>
          <li data-testid="admin-erasure-erase-plan-read">
            {{ t('admin.compliance.erasures.dialog.erase.summary.read', { rows: summary.read }) }}
          </li>
          <li data-testid="admin-erasure-erase-plan-reference">
            {{
              t('admin.compliance.erasures.dialog.erase.summary.reference', {
                digest: summary.reference,
              })
            }}
          </li>
        </ul>

        <p
          v-if="userUlid !== null"
          class="text-body-2 mt-4 mb-1"
          data-testid="admin-erasure-erase-dialog-account-id"
        >
          <span class="font-weight-medium">{{ t('admin.compliance.erasures.id.label') }}:</span>
          <AccountIdCopy :ulid="userUlid" testid="admin-erasure-erase-dialog-id" />
        </p>

        <v-text-field
          v-model="typedUlid"
          :label="t('admin.compliance.erasures.dialog.erase.ulid_label')"
          autocomplete="off"
          spellcheck="false"
          class="mt-1"
          data-testid="admin-erasure-erase-dialog-ulid"
        />

        <p class="text-body-2 font-weight-medium mt-4 mb-2">
          {{ t('admin.compliance.erasures.dialog.no_personal_details') }}
        </p>
        <v-textarea
          v-model="reason"
          :label="t('admin.compliance.erasures.dialog.reason_label')"
          :hint="t('admin.compliance.erasures.dialog.reason_hint', { count: REASON_MIN })"
          persistent-hint
          rows="2"
          auto-grow
          :counter="REASON_MAX"
          :maxlength="REASON_MAX"
          data-testid="admin-erasure-erase-dialog-reason"
        />

        <v-text-field
          v-model="mfaCode"
          :label="t('admin.compliance.erasures.dialog.erase.mfa_label')"
          :hint="t('admin.compliance.erasures.dialog.erase.mfa_hint')"
          persistent-hint
          autocomplete="one-time-code"
          spellcheck="false"
          class="mt-4"
          data-testid="admin-erasure-erase-dialog-mfa"
        />

        <div
          v-if="errorText !== null"
          role="alert"
          class="erase-dialog__error"
          data-testid="admin-erasure-erase-dialog-error"
        >
          {{ errorText }}
        </div>
        <div
          v-if="refusedReasons !== null"
          class="erase-dialog__error"
          data-testid="admin-erasure-erase-dialog-refused"
        >
          {{ t('admin.compliance.erasures.dialog.erase.not_ready', { reasons: refusedReasons }) }}
        </div>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn
          variant="text"
          :disabled="isSaving"
          data-testid="admin-erasure-erase-dialog-cancel"
          @click="onCancel"
        >
          {{ t('admin.compliance.erasures.dialog.cancel') }}
        </v-btn>
        <v-btn
          color="error"
          variant="flat"
          :loading="isSaving"
          :disabled="!canConfirm"
          data-testid="admin-erasure-erase-dialog-confirm"
          @click="onConfirm"
        >
          {{ t('admin.compliance.erasures.dialog.erase.confirm') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.erase-dialog__plan {
  margin: 0 0 8px;
  padding-left: 20px;
  font-size: 0.875rem;
  line-height: 1.6;
}

.erase-dialog__error {
  margin-top: 8px;
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}
</style>
