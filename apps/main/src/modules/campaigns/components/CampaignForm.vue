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
 * Jobs board (AH-054, D4): the listing FIELDS live here, on both the create
 * and Settings surfaces, and are optional everywhere. The toggle itself is
 * Settings-only and lives on the detail page — a just-created campaign cannot
 * satisfy the listing floor anyway, so offering the switch on create would
 * only ever produce a 422.
 *
 * The posting toggle (AH-069, D1) is the opposite case and so lives HERE, on
 * both surfaces: it describes how the campaign runs, which is a question the
 * agency answers while writing the brief, not an edit-time act. The create
 * page seeds it to `false` (hand off at approval — the product default); the
 * column's own default is `true` (the safety floor for callers that omit it).
 *
 * Per-field 422 errors arrive via `fieldErrors` (the canonical
 * extractFieldErrors pattern); the parent owns the network layer.
 */

import type { CreateCampaignPayload } from '@catalyst/api-client'
import { COUNTRY_OPTIONS, euLanguageOptions } from '@catalyst/api-client'
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

const listingDurationErrors = computed(() => fieldErrorList('listing_duration'))
const listingFeeErrors = computed(() => fieldErrorList('listing_fee'))
const listingLanguagesErrors = computed(() => fieldErrorList('listing_languages'))
const listingRegionsErrors = computed(() => fieldErrorList('listing_regions'))
const listingExamplesUrlErrors = computed(() => fieldErrorList('listing_examples_url'))

// A campaign's production languages follow the operating markets, so the
// listing picker uses the 24-EU set (not the creator-side world set); regions
// use the shared ISO registry with launch markets pinned first.
const listingLanguageItems = euLanguageOptions().map((o) => ({ title: o.label, value: o.value }))
const listingRegionItems = COUNTRY_OPTIONS.map((c) => ({ title: c.label, value: c.code }))

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

    <!--
      Jobs-board listing details (AH-054, D2). Optional here; the Settings
      toggle is what requires them (D3). Duration and fee are free text on
      purpose — agencies phrase both in their own terms.
    -->
    <div class="text-subtitle-2 mt-4 mb-1">{{ t('app.campaigns.listing.sectionTitle') }}</div>
    <div class="text-caption text-medium-emphasis mb-3">
      {{ t('app.campaigns.listing.sectionHint') }}
    </div>

    <div class="d-flex ga-3">
      <v-text-field
        :model-value="local.listing_duration ?? ''"
        :label="t('app.campaigns.fields.listingDuration')"
        :hint="t('app.campaigns.fields.listingDurationHint')"
        :error-messages="listingDurationErrors as string[]"
        persistent-hint
        maxlength="120"
        autocomplete="off"
        data-test="campaign-listing-duration"
        @update:model-value="update('listing_duration', $event || undefined)"
      />
      <v-text-field
        :model-value="local.listing_fee ?? ''"
        :label="t('app.campaigns.fields.listingFee')"
        :hint="t('app.campaigns.fields.listingFeeHint')"
        :error-messages="listingFeeErrors as string[]"
        persistent-hint
        maxlength="120"
        autocomplete="off"
        data-test="campaign-listing-fee"
        @update:model-value="update('listing_fee', $event || undefined)"
      />
    </div>

    <v-select
      :model-value="local.listing_languages ?? []"
      :label="t('app.campaigns.fields.listingLanguages')"
      :error-messages="listingLanguagesErrors as string[]"
      :items="listingLanguageItems"
      item-title="title"
      item-value="value"
      multiple
      chips
      closable-chips
      class="mt-3"
      data-test="campaign-listing-languages"
      @update:model-value="update('listing_languages', $event)"
    />

    <v-select
      :model-value="local.listing_regions ?? []"
      :label="t('app.campaigns.fields.listingRegions')"
      :error-messages="listingRegionsErrors as string[]"
      :items="listingRegionItems"
      item-title="title"
      item-value="value"
      multiple
      chips
      closable-chips
      data-test="campaign-listing-regions"
      @update:model-value="update('listing_regions', $event)"
    />

    <v-text-field
      :model-value="local.listing_examples_url ?? ''"
      :label="t('app.campaigns.fields.listingExamplesUrl')"
      :hint="t('app.campaigns.fields.listingExamplesUrlHint')"
      :error-messages="listingExamplesUrlErrors as string[]"
      persistent-hint
      type="url"
      maxlength="2048"
      autocomplete="off"
      class="mb-3"
      data-test="campaign-listing-examples-url"
      @update:model-value="update('listing_examples_url', $event || undefined)"
    />

    <v-switch
      :model-value="local.requires_per_campaign_contract ?? false"
      :label="t('app.campaigns.fields.requiresContract')"
      color="primary"
      density="compact"
      data-test="campaign-requires-contract"
      @update:model-value="update('requires_per_campaign_contract', $event ?? false)"
    />

    <!--
      AH-069 D1 — the posting toggle. Read in the POSITIVE direction ("posted by
      creators" = ON), so nobody has to invert it. The `?? true` fallback is the
      safety floor showing through: a payload without the key describes a
      campaign that expects posting.
    -->
    <v-switch
      :model-value="local.creator_posts_content ?? true"
      :label="t('app.campaigns.fields.creatorPostsContent')"
      :hint="t('app.campaigns.fields.creatorPostsContentHint')"
      persistent-hint
      color="primary"
      density="compact"
      class="mb-3"
      data-test="campaign-creator-posts-content"
      @update:model-value="update('creator_posts_content', $event ?? true)"
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
