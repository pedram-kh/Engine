<script setup lang="ts">
/**
 * Agency "Edit offer" dialog (AH-118, item 2) — change an assignment's offer
 * amount until a contract is sent or signed, or payment starts.
 *
 * Shaped on ReinviteDialog: major-unit input → minor on the wire, the currency
 * a read-only suffix (the API never accepts one, Q12), per-field 422 binding.
 * There is no creator confirmation step: the creator is notified and a history
 * line lands in the conversation.
 *
 * The lock is SERVER-computed (`offer_edit_locked` + a reason). The dialog opens
 * in the locked state with the reason sentence and a disabled form, so a
 * disabled button never has to explain itself (a tooltip is not reachable on a
 * touch screen). A 422 `assignment.offer_locked` from a stale list flips the
 * open dialog into the same state, by the reason the server names.
 */

import { ApiError, extractFieldErrors, type OfferEditLockReason } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { campaignsApi } from '../api/campaigns.api'

type FieldKey = 'agreed_fee_minor_units'

const props = defineProps<{
  modelValue: boolean
  agencyId: string
  campaignId: string
  assignmentId: string | null
  /** The amount on the assignment now (minor units). */
  currentMinor: number | null
  currency: string | null
  /** Server verdict: the reason the offer is locked, or null while it is editable. */
  lockReason: OfferEditLockReason | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  success: []
}>()

const { t } = useI18n()

const amount = ref<number | null>(null)
const fieldErrors = ref<Partial<Record<FieldKey, readonly string[]>>>({})
const submitting = ref(false)
const failed = ref(false)
// A reason learned from a 422 while the dialog was open, ahead of the list.
const raceReason = ref<OfferEditLockReason | null>(null)

const currencyCode = computed(() => props.currency ?? 'EUR')
const effectiveReason = computed(() => raceReason.value ?? props.lockReason)
const locked = computed(() => effectiveReason.value !== null)

// `feature_disabled` is never rendered (the entry point is hidden while the flag
// is off), so it falls back to the generic stage sentence rather than a blank.
const lockedText = computed(() => {
  const reason = effectiveReason.value
  if (reason === null) return ''
  const key = reason === 'feature_disabled' ? 'status' : reason
  return t(`app.campaigns.offerEdit.locked.${key}`)
})

const minorValue = computed(() =>
  amount.value === null ? null : Math.round((amount.value ?? 0) * 100),
)
const unchanged = computed(
  () => minorValue.value !== null && minorValue.value === props.currentMinor,
)
const valid = computed(() => minorValue.value !== null && minorValue.value > 0)

const amountErrors = computed<string[]>(() => {
  const server = fieldErrors.value.agreed_fee_minor_units
  if (server !== undefined && server.length > 0) return [...server]
  return unchanged.value ? [t('app.campaigns.offerEdit.unchanged')] : []
})

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    amount.value = props.currentMinor === null ? null : props.currentMinor / 100
    fieldErrors.value = {}
    failed.value = false
    raceReason.value = null
  },
  { immediate: true },
)

function close(): void {
  emit('update:modelValue', false)
}

async function submit(): Promise<void> {
  if (props.assignmentId === null || locked.value || !valid.value || unchanged.value) return

  submitting.value = true
  fieldErrors.value = {}
  failed.value = false
  try {
    await campaignsApi.editOffer(props.agencyId, props.campaignId, props.assignmentId, {
      agreed_fee_minor_units: minorValue.value ?? 0,
    })
    emit('success')
    emit('update:modelValue', false)
  } catch (err) {
    if (err instanceof ApiError && err.code === 'assignment.offer_locked') {
      const reason = err.details[0]?.meta?.reason
      raceReason.value = typeof reason === 'string' ? (reason as OfferEditLockReason) : 'status'
    } else if (err instanceof ApiError && err.code === 'assignment.offer_unchanged') {
      fieldErrors.value = { agreed_fee_minor_units: [t('app.campaigns.offerEdit.unchanged')] }
    } else {
      if (err instanceof ApiError) fieldErrors.value = extractFieldErrors<FieldKey>(err)
      if (Object.keys(fieldErrors.value).length === 0) failed.value = true
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="420"
    data-test="edit-offer-dialog"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6">{{ t('app.campaigns.offerEdit.title') }}</v-card-title>
      <v-card-text>
        <v-alert
          v-if="locked"
          type="warning"
          variant="tonal"
          density="compact"
          class="mb-3"
          data-test="edit-offer-locked"
          :data-reason="effectiveReason"
        >
          {{ lockedText }}
        </v-alert>
        <p v-else class="text-body-2 text-medium-emphasis mb-3" data-test="edit-offer-body">
          {{ t('app.campaigns.offerEdit.body') }}
        </p>
        <v-text-field
          v-model.number="amount"
          type="number"
          min="0"
          step="0.01"
          density="compact"
          variant="outlined"
          :label="t('app.campaigns.offerEdit.amountLabel', { currency: currencyCode })"
          :suffix="currencyCode"
          :disabled="locked"
          :error-messages="amountErrors"
          data-test="edit-offer-amount"
        />
        <v-alert
          v-if="failed"
          type="error"
          variant="tonal"
          density="compact"
          data-test="edit-offer-error"
        >
          {{ t('app.campaigns.offerEdit.error') }}
        </v-alert>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" data-test="edit-offer-cancel" @click="close">
          {{ t('app.campaigns.offerEdit.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :disabled="locked || !valid || unchanged"
          :loading="submitting"
          data-test="edit-offer-submit"
          @click="submit"
        >
          {{ t('app.campaigns.offerEdit.submit') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
