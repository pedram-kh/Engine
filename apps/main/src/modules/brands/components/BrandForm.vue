<script setup lang="ts">
/**
 * Shared brand form — used by both BrandCreatePage and BrandEditPage.
 *
 * All fields per the kickoff spec:
 *   - name (required, max 255)
 *   - slug (optional, auto-suggested from name on blur, max 64)
 *   - description (optional, textarea)
 *   - industry (optional, dropdown)
 *   - website_url (optional, URL validation)
 *   - default_currency (optional, ISO 4217 dropdown)
 *   - default_language (optional, en/pt/it dropdown)
 */

import type { CreateBrandPayload } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Per-field error messages, keyed by backend snake_case field name (the
 * same identifier the JSON:API envelope's `source.pointer` resolves to —
 * `/data/attributes/<field>`). Each entry is an array so a single field
 * can carry multiple violations (e.g. slug failing both `regex` and
 * `unique` in one round-trip).
 *
 * Passed in from the parent page after it inspects an ApiError; the
 * form is otherwise unaware of the network layer. See
 * `BrandCreatePage.vue` for the extraction logic.
 */
type FieldErrors = Partial<Record<keyof CreateBrandPayload, readonly string[]>>

const props = withDefaults(
  defineProps<{
    modelValue: CreateBrandPayload
    submitting: boolean
    submitLabel: string
    error: string | null
    fieldErrors?: FieldErrors
  }>(),
  {
    fieldErrors: () => ({}),
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: CreateBrandPayload]
  submit: []
}>()

const { t } = useI18n()

const local = ref<CreateBrandPayload>({ ...props.modelValue })

watch(
  () => props.modelValue,
  (v) => {
    local.value = { ...v }
  },
)

function update<K extends keyof CreateBrandPayload>(key: K, value: CreateBrandPayload[K]): void {
  local.value = { ...local.value, [key]: value }
  emit('update:modelValue', local.value)
}

function slugify(name: string): string {
  return name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 64)
}

function onNameBlur(): void {
  if (!local.value.slug && local.value.name) {
    update('slug', slugify(local.value.name))
  }
}

function onSubmit(): void {
  // Defense-in-depth slug fallback. The on-blur auto-fill above covers
  // the common path, but a user who types name then submits via Enter
  // (focus never leaves the name input) skips blur entirely — the
  // original bug fixed in sprint-3 chunk-5. Re-running slugify here
  // guarantees the payload always carries a slug when name is set.
  if (!local.value.slug && local.value.name) {
    update('slug', slugify(local.value.name))
  }
  emit('submit')
}

const fieldErrorList = (field: keyof CreateBrandPayload): readonly string[] =>
  props.fieldErrors?.[field] ?? []

const nameErrors = computed(() => fieldErrorList('name'))
const slugErrors = computed(() => fieldErrorList('slug'))
const descriptionErrors = computed(() => fieldErrorList('description'))
const industryErrors = computed(() => fieldErrorList('industry'))
const websiteUrlErrors = computed(() => fieldErrorList('website_url'))
const defaultCurrencyErrors = computed(() => fieldErrorList('default_currency'))
const defaultLanguageErrors = computed(() => fieldErrorList('default_language'))

const industryOptions = [
  'Fashion',
  'Beauty',
  'Food & Beverage',
  'Technology',
  'Travel',
  'Health & Wellness',
  'Sports',
  'Entertainment',
  'Finance',
  'Education',
  'Other',
]

const currencyOptions = [
  { title: 'USD — US Dollar', value: 'USD' },
  { title: 'EUR — Euro', value: 'EUR' },
  { title: 'BRL — Brazilian Real', value: 'BRL' },
  { title: 'GBP — British Pound', value: 'GBP' },
  { title: 'AUD — Australian Dollar', value: 'AUD' },
  { title: 'CAD — Canadian Dollar', value: 'CAD' },
  { title: 'JPY — Japanese Yen', value: 'JPY' },
]

const languageOptions = [
  { title: t('app.locale.en'), value: 'en' },
  { title: t('app.locale.pt'), value: 'pt' },
  { title: t('app.locale.it'), value: 'it' },
]
</script>

<template>
  <form novalidate data-test="brand-form" @submit.prevent="onSubmit">
    <v-text-field
      :model-value="local.name"
      :label="t('app.brands.fields.name')"
      :error-messages="nameErrors as string[]"
      required
      maxlength="255"
      autocomplete="off"
      data-test="brand-name"
      @update:model-value="update('name', $event)"
      @blur="onNameBlur"
    />

    <v-text-field
      :model-value="local.slug ?? ''"
      :label="t('app.brands.fields.slug')"
      :error-messages="slugErrors as string[]"
      maxlength="64"
      autocomplete="off"
      hint="Auto-suggested from name. Leave blank to use the suggestion."
      persistent-hint
      data-test="brand-slug"
      @update:model-value="update('slug', $event || undefined)"
    />

    <v-textarea
      :model-value="local.description ?? ''"
      :label="t('app.brands.fields.description')"
      :error-messages="descriptionErrors as string[]"
      rows="3"
      auto-grow
      data-test="brand-description"
      @update:model-value="update('description', $event || undefined)"
    />

    <v-select
      :model-value="local.industry ?? ''"
      :label="t('app.brands.fields.industry')"
      :error-messages="industryErrors as string[]"
      :items="industryOptions"
      clearable
      data-test="brand-industry"
      @update:model-value="update('industry', $event || undefined)"
    />

    <v-text-field
      :model-value="local.website_url ?? ''"
      :label="t('app.brands.fields.websiteUrl')"
      :error-messages="websiteUrlErrors as string[]"
      type="url"
      autocomplete="off"
      data-test="brand-website-url"
      @update:model-value="update('website_url', $event || undefined)"
    />

    <v-select
      :model-value="local.default_currency ?? ''"
      :label="t('app.brands.fields.defaultCurrency')"
      :error-messages="defaultCurrencyErrors as string[]"
      :items="currencyOptions"
      item-title="title"
      item-value="value"
      clearable
      data-test="brand-default-currency"
      @update:model-value="update('default_currency', $event || undefined)"
    />

    <v-select
      :model-value="local.default_language ?? ''"
      :label="t('app.brands.fields.defaultLanguage')"
      :error-messages="defaultLanguageErrors as string[]"
      :items="languageOptions"
      item-title="title"
      item-value="value"
      clearable
      data-test="brand-default-language"
      @update:model-value="update('default_language', $event || undefined)"
    />

    <div
      v-if="error"
      role="alert"
      aria-live="polite"
      class="text-error text-body-2 mb-3"
      data-test="brand-form-error"
    >
      {{ error }}
    </div>

    <div class="d-flex justify-end">
      <v-btn
        type="submit"
        color="primary"
        :loading="submitting"
        :disabled="submitting || !local.name"
        data-test="brand-form-submit"
      >
        {{ submitLabel }}
      </v-btn>
    </div>
  </form>
</template>
