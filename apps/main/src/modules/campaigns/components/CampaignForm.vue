<script setup lang="ts">
/**
 * Shared campaign form (Sprint 8 Chunk 1; simplified in the campaign-form
 * simplification pass, D-1..D-4). Used by CampaignCreatePage and the detail
 * page's Settings tab.
 *
 * Money UX: the user types a major-unit amount (e.g. 2500.00) + a currency;
 * the form converts to integer minor units (the wire contract, D-3) on every
 * change.
 *
 * The `objective`, `target_creator_count`, and structured `brief` sub-fields
 * (deliverables / hashtags / usage_rights) were removed from this form. The
 * form no longer sends `objective` (server defaults to `ugc`), and never sends
 * `brief` / `target_creator_count` — so on edit their stored values are
 * preserved by omission (backend `sometimes` rules). Free-text deliverables
 * and usage terms now live in `description`.
 *
 * Per-field 422 errors arrive via `fieldErrors` (the canonical
 * extractFieldErrors pattern); the parent owns the network layer.
 */

import type { CreateCampaignPayload } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

interface BrandOption {
  id: string
  name: string
}

type FieldErrors = Partial<Record<string, readonly string[]>>

const props = withDefaults(
  defineProps<{
    modelValue: CreateCampaignPayload
    brands: BrandOption[]
    submitting: boolean
    submitLabel: string
    error: string | null
    fieldErrors?: FieldErrors
    /** Hide the brand picker on the Settings edit (brand is immutable). */
    hideBrand?: boolean
  }>(),
  {
    fieldErrors: () => ({}),
    hideBrand: false,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: CreateCampaignPayload]
  submit: []
}>()

const { t } = useI18n()

const local = ref<CreateCampaignPayload>({ ...props.modelValue })

// Major-unit budget mirror — kept in sync with local.budget_minor_units.
const budgetMajor = ref<string>(
  props.modelValue.budget_minor_units != null
    ? String(props.modelValue.budget_minor_units / 100)
    : '',
)

watch(
  () => props.modelValue,
  (v) => {
    local.value = { ...v }
    budgetMajor.value = v.budget_minor_units != null ? String(v.budget_minor_units / 100) : ''
  },
)

function emitUpdate(): void {
  emit('update:modelValue', { ...local.value })
}

function update<K extends keyof CreateCampaignPayload>(
  key: K,
  value: CreateCampaignPayload[K],
): void {
  local.value = { ...local.value, [key]: value }
  emitUpdate()
}

function onBudgetChange(value: string): void {
  budgetMajor.value = value
  const parsed = Number.parseFloat(value)
  local.value = {
    ...local.value,
    budget_minor_units: Number.isFinite(parsed) ? Math.round(parsed * 100) : 0,
  }
  emitUpdate()
}

function onSubmit(): void {
  emit('submit')
}

const fieldErrorList = (field: string): readonly string[] => props.fieldErrors?.[field] ?? []

const nameErrors = computed(() => fieldErrorList('name'))
const brandErrors = computed(() => fieldErrorList('brand_id'))
const budgetErrors = computed(() => [
  ...fieldErrorList('budget_minor_units'),
  ...fieldErrorList('budget_currency'),
])

const currencyOptions = [
  { title: 'EUR — Euro', value: 'EUR' },
  { title: 'USD — US Dollar', value: 'USD' },
  { title: 'GBP — British Pound', value: 'GBP' },
  { title: 'BRL — Brazilian Real', value: 'BRL' },
]

const brandSelectItems = computed(() => props.brands.map((b) => ({ title: b.name, value: b.id })))
</script>

<template>
  <form novalidate data-test="campaign-form" @submit.prevent="onSubmit">
    <v-select
      v-if="!hideBrand"
      :model-value="local.brand_id || null"
      :label="t('app.campaigns.fields.brand')"
      :error-messages="brandErrors as string[]"
      :items="brandSelectItems"
      item-title="title"
      item-value="value"
      required
      data-test="campaign-brand"
      @update:model-value="update('brand_id', $event)"
    />

    <v-text-field
      :model-value="local.name"
      :label="t('app.campaigns.fields.name')"
      :error-messages="nameErrors as string[]"
      required
      maxlength="255"
      autocomplete="off"
      data-test="campaign-name"
      @update:model-value="update('name', $event)"
    />

    <v-textarea
      :model-value="local.description ?? ''"
      :label="t('app.campaigns.fields.description')"
      :hint="t('app.campaigns.fields.descriptionHint')"
      persistent-hint
      rows="3"
      auto-grow
      class="mb-3"
      data-test="campaign-description"
      @update:model-value="update('description', $event || undefined)"
    />

    <div class="d-flex ga-3">
      <v-text-field
        :model-value="budgetMajor"
        :label="t('app.campaigns.fields.budget')"
        :error-messages="budgetErrors as string[]"
        type="number"
        min="0"
        step="0.01"
        data-test="campaign-budget"
        @update:model-value="onBudgetChange"
      />
      <v-select
        :model-value="local.budget_currency ?? 'EUR'"
        :label="t('app.campaigns.fields.currency')"
        :items="currencyOptions"
        item-title="title"
        item-value="value"
        style="max-width: 200px"
        data-test="campaign-currency"
        @update:model-value="update('budget_currency', $event)"
      />
    </div>

    <div class="d-flex ga-3">
      <v-text-field
        :model-value="local.starts_at ?? ''"
        :label="t('app.campaigns.fields.startsAt')"
        type="date"
        data-test="campaign-starts-at"
        @update:model-value="update('starts_at', $event || undefined)"
      />
      <v-text-field
        :model-value="local.ends_at ?? ''"
        :label="t('app.campaigns.fields.endsAt')"
        type="date"
        data-test="campaign-ends-at"
        @update:model-value="update('ends_at', $event || undefined)"
      />
    </div>

    <v-switch
      :model-value="local.requires_per_campaign_contract ?? false"
      :label="t('app.campaigns.fields.requiresContract')"
      color="primary"
      density="compact"
      data-test="campaign-requires-contract"
      @update:model-value="update('requires_per_campaign_contract', $event ?? false)"
    />

    <div
      v-if="error"
      role="alert"
      aria-live="polite"
      class="text-error text-body-2 mb-3"
      data-test="campaign-form-error"
    >
      {{ error }}
    </div>

    <div class="d-flex justify-end">
      <v-btn
        type="submit"
        color="primary"
        :loading="submitting"
        :disabled="submitting || !local.name || (!hideBrand && !local.brand_id)"
        data-test="campaign-form-submit"
      >
        {{ submitLabel }}
      </v-btn>
    </div>
  </form>
</template>
