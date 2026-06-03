<script setup lang="ts">
/**
 * Shared talent-pool form — used by both PoolCreatePage and PoolEditPage
 * (Sprint 6 Chunk 2b). Mirrors BrandForm's controlled-input + field-error
 * shape.
 *
 * Fields:
 *   - name        (required, max 160)
 *   - description (optional, textarea)
 *   - brand_id    (optional brand LABEL, D-2b-4 — a clearable select; empty =
 *                  "Agency-wide". Brand-scope adds NO eligibility constraint.)
 */

import type { CreateTalentPoolPayload } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

type FieldErrors = Partial<Record<keyof CreateTalentPoolPayload, readonly string[]>>

export interface BrandOption {
  value: string
  title: string
}

const props = withDefaults(
  defineProps<{
    modelValue: CreateTalentPoolPayload
    submitting: boolean
    submitLabel: string
    error: string | null
    brandOptions?: ReadonlyArray<BrandOption>
    fieldErrors?: FieldErrors
  }>(),
  {
    brandOptions: () => [],
    fieldErrors: () => ({}),
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: CreateTalentPoolPayload]
  submit: []
}>()

const { t } = useI18n()

const local = ref<CreateTalentPoolPayload>({ ...props.modelValue })

watch(
  () => props.modelValue,
  (v) => {
    local.value = { ...v }
  },
)

function update<K extends keyof CreateTalentPoolPayload>(
  key: K,
  value: CreateTalentPoolPayload[K],
): void {
  local.value = { ...local.value, [key]: value }
  emit('update:modelValue', local.value)
}

function onSubmit(): void {
  emit('submit')
}

const fieldErrorList = (field: keyof CreateTalentPoolPayload): readonly string[] =>
  props.fieldErrors?.[field] ?? []

const nameErrors = computed(() => fieldErrorList('name'))
const descriptionErrors = computed(() => fieldErrorList('description'))
const brandErrors = computed(() => fieldErrorList('brand_id'))
</script>

<template>
  <form novalidate data-test="pool-form" @submit.prevent="onSubmit">
    <v-text-field
      :model-value="local.name"
      :label="t('app.pools.fields.name')"
      :error-messages="nameErrors as string[]"
      required
      maxlength="160"
      autocomplete="off"
      data-test="pool-name"
      @update:model-value="update('name', $event)"
    />

    <v-textarea
      :model-value="local.description ?? ''"
      :label="t('app.pools.fields.description')"
      :error-messages="descriptionErrors as string[]"
      rows="3"
      auto-grow
      data-test="pool-description"
      @update:model-value="update('description', $event || null)"
    />

    <v-select
      :model-value="local.brand_id ?? null"
      :label="t('app.pools.fields.brand')"
      :error-messages="brandErrors as string[]"
      :items="brandOptions"
      item-title="title"
      item-value="value"
      clearable
      :hint="t('app.pools.fields.brandHint')"
      persistent-hint
      data-test="pool-brand"
      @update:model-value="update('brand_id', $event || null)"
    />

    <div
      v-if="error"
      role="alert"
      aria-live="polite"
      class="text-error text-body-2 my-3"
      data-test="pool-form-error"
    >
      {{ error }}
    </div>

    <div class="d-flex justify-end mt-2">
      <v-btn
        type="submit"
        color="primary"
        :loading="submitting"
        :disabled="submitting || !local.name"
        data-test="pool-form-submit"
      >
        {{ submitLabel }}
      </v-btn>
    </div>
  </form>
</template>
