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
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  modelValue: CreateBrandPayload
  submitting: boolean
  submitLabel: string
  error: string | null
}>()

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

function onNameBlur(): void {
  if (!local.value.slug && local.value.name) {
    const suggested = local.value.name
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-|-$/g, '')
      .slice(0, 64)
    update('slug', suggested)
  }
}

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
  <form novalidate data-test="brand-form" @submit.prevent="emit('submit')">
    <v-text-field
      :model-value="local.name"
      :label="t('app.brands.fields.name')"
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
      rows="3"
      auto-grow
      data-test="brand-description"
      @update:model-value="update('description', $event || undefined)"
    />

    <v-select
      :model-value="local.industry ?? ''"
      :label="t('app.brands.fields.industry')"
      :items="industryOptions"
      clearable
      data-test="brand-industry"
      @update:model-value="update('industry', $event || undefined)"
    />

    <v-text-field
      :model-value="local.website_url ?? ''"
      :label="t('app.brands.fields.websiteUrl')"
      type="url"
      autocomplete="off"
      data-test="brand-website-url"
      @update:model-value="update('website_url', $event || undefined)"
    />

    <v-select
      :model-value="local.default_currency ?? ''"
      :label="t('app.brands.fields.defaultCurrency')"
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
