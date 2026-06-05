<script setup lang="ts">
/**
 * Agency re-invite dialog (re-invite UI chunk, D-4/D-5) — the agency's response
 * to a creator counter. Shaped on the creator counter fee-form: major-unit
 * input → minor on the wire, campaign currency as a read-only suffix, and
 * per-field 422 binding via extractFieldErrors.
 */

import { ApiError, extractFieldErrors, type CampaignAssignmentResource } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { campaignsApi } from '../api/campaigns.api'

type FeeField = 'agreed_fee_minor_units' | 'agreed_fee_currency'

const props = defineProps<{
  modelValue: boolean
  agencyId: string
  campaignId: string
  assignment: CampaignAssignmentResource | null
  campaignCurrency: string | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  success: []
}>()

const { t, locale } = useI18n()

const feeAmount = ref<number | null>(null)
const fieldErrors = ref<Partial<Record<FeeField, readonly string[]>>>({})
const submitting = ref(false)

const currency = computed(
  () => props.campaignCurrency ?? props.assignment?.attributes.agreed_fee_currency ?? 'EUR',
)

const counteredFeeFormatted = computed(() =>
  formatMoney(
    props.assignment?.attributes.countered_fee_minor_units ?? null,
    props.assignment?.attributes.countered_fee_currency ?? currency.value,
  ),
)

const feeValid = computed(() => feeAmount.value !== null && feeAmount.value > 0)

function formatMoney(minor: number | null, cur: string | null): string {
  if (minor === null) return '—'
  return `${(minor / 100).toLocaleString(locale.value, { minimumFractionDigits: 2 })} ${cur ?? ''}`.trim()
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      feeAmount.value = null
      fieldErrors.value = {}
    }
  },
)

function close(): void {
  emit('update:modelValue', false)
}

async function submit(): Promise<void> {
  const assignment = props.assignment
  if (assignment === null || !feeValid.value) return

  submitting.value = true
  fieldErrors.value = {}
  try {
    await campaignsApi.reinvite(props.agencyId, props.campaignId, assignment.id, {
      agreed_fee_minor_units: Math.round((feeAmount.value ?? 0) * 100),
      agreed_fee_currency: currency.value,
    })
    emit('success')
    emit('update:modelValue', false)
  } catch (err) {
    if (err instanceof ApiError) {
      fieldErrors.value = extractFieldErrors<FeeField>(err)
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
    max-width="420"
    data-test="reinvite-dialog"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6">{{ t('app.campaigns.reinvite.title') }}</v-card-title>
      <v-card-text>
        <p class="text-body-2 text-medium-emphasis mb-3" data-test="reinvite-dialog-body">
          {{ t('app.campaigns.reinvite.body', { fee: counteredFeeFormatted }) }}
        </p>
        <v-text-field
          v-model.number="feeAmount"
          type="number"
          min="0"
          step="0.01"
          density="compact"
          variant="outlined"
          :label="t('app.campaigns.reinvite.feeLabel', { currency })"
          :suffix="currency"
          :error-messages="fieldErrors.agreed_fee_minor_units as string[]"
          data-test="reinvite-fee"
        />
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" data-test="reinvite-cancel" @click="close">
          {{ t('app.campaigns.reinvite.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :disabled="!feeValid"
          :loading="submitting"
          data-test="reinvite-submit"
          @click="submit"
        >
          {{ t('app.campaigns.reinvite.submit') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
