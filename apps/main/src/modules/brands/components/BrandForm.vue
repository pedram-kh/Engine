<script setup lang="ts">
/**
 * Shared brand form — used by both BrandCreatePage and BrandEditPage.
 *
 * Every field is REQUIRED (AH-053, D6): name, slug, description ("Monthly
 * deliverables"), industry, website URL and a logo. The API enforces this on
 * both create and edit — including on brands that predate the floor, which
 * hard-block on their next edit — so this form mirrors the requirement inline
 * and holds the submit button until the floor is met. The 422 is a backstop,
 * never the user's first notice.
 *
 * The logo is not a form value. It lives behind its own endpoint (D7), so the
 * form only surfaces the control: the parent owns what "choose a file" means
 * (immediate upload on edit; deferred until after create, since the upload
 * needs a brand to attach to).
 *
 * `default_currency` / `default_language` were removed from this form (D8).
 * The columns, their defaults and their API emission are untouched — the form
 * simply omits them, so stored values are preserved by omission.
 */

import type { CreateBrandPayload } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { brandFloorMissingFields } from '../brandFloor'

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
type FieldErrors = Partial<Record<string, readonly string[]>>

const props = withDefaults(
  defineProps<{
    modelValue: Partial<CreateBrandPayload>
    submitting: boolean
    submitLabel: string
    error: string | null
    fieldErrors?: FieldErrors
    /** Renderable logo (signed URL, or a local object URL before create). */
    logoUrl?: string | null
    /** An upload or removal is in flight. */
    logoBusy?: boolean
    logoError?: string | null
  }>(),
  {
    fieldErrors: () => ({}),
    logoUrl: null,
    logoBusy: false,
    logoError: null,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: Partial<CreateBrandPayload>]
  submit: []
  'logo-selected': [file: File]
  'logo-removed': []
}>()

const { t } = useI18n()

const local = ref<Partial<CreateBrandPayload>>({ ...props.modelValue })

watch(
  () => props.modelValue,
  (v) => {
    local.value = { ...v }
  },
)

// The value type is the PARTIAL one: the payload type requires every floor
// field (D6), but a half-filled form legitimately holds `undefined` — the
// floor mirror below is what stops it being submitted.
function update<K extends keyof CreateBrandPayload>(
  key: K,
  value: Partial<CreateBrandPayload>[K],
): void {
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

const fieldErrorList = (field: string): readonly string[] => props.fieldErrors?.[field] ?? []

const nameErrors = computed(() => fieldErrorList('name'))
const slugErrors = computed(() => fieldErrorList('slug'))
const descriptionErrors = computed(() => fieldErrorList('description'))
const industryErrors = computed(() => fieldErrorList('industry'))
const websiteUrlErrors = computed(() => fieldErrorList('website_url'))

// The server reports a missing logo against `logo_path`; the control has no
// bound value of its own, so its error line is assembled here.
const logoErrors = computed<string[]>(() => [
  ...fieldErrorList('logo_path'),
  ...(props.logoError !== null ? [props.logoError] : []),
])

// ── The D6 floor mirror ─────────────────────────────────────────────────────
const floorMissing = computed(() => brandFloorMissingFields(local.value, props.logoUrl !== null))

const floorMet = computed(() => floorMissing.value.length === 0)

const floorHint = computed(() =>
  floorMet.value
    ? null
    : t('app.brands.floor.hint', {
        fields: floorMissing.value.map((f) => t(`app.brands.floor.fields.${f}`)).join(', '),
      }),
)

const fileInput = ref<HTMLInputElement | null>(null)

function onLogoChange(event: Event): void {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (file) emit('logo-selected', file)
  // Reset so re-choosing the same file still fires a change event.
  input.value = ''
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
      required
      maxlength="64"
      autocomplete="off"
      :hint="t('app.brands.fields.slugHint')"
      persistent-hint
      data-test="brand-slug"
      @update:model-value="update('slug', $event || undefined)"
    />

    <v-textarea
      :model-value="local.description ?? ''"
      :label="t('app.brands.fields.description')"
      :hint="t('app.brands.fields.descriptionHint')"
      :error-messages="descriptionErrors as string[]"
      persistent-hint
      required
      rows="3"
      auto-grow
      class="mt-3"
      data-test="brand-description"
      @update:model-value="update('description', $event || undefined)"
    />

    <v-select
      :model-value="local.industry ?? ''"
      :label="t('app.brands.fields.industry')"
      :error-messages="industryErrors as string[]"
      :items="industryOptions"
      required
      class="mt-3"
      data-test="brand-industry"
      @update:model-value="update('industry', $event || undefined)"
    />

    <v-text-field
      :model-value="local.website_url ?? ''"
      :label="t('app.brands.fields.websiteUrl')"
      :error-messages="websiteUrlErrors as string[]"
      type="url"
      required
      autocomplete="off"
      data-test="brand-website-url"
      @update:model-value="update('website_url', $event || undefined)"
    />

    <!-- Logo (D7). Chosen here, uploaded by the parent. -->
    <div class="mb-4">
      <div class="text-subtitle-2 mb-2">{{ t('app.brands.fields.logo') }}</div>
      <div class="d-flex align-center ga-4">
        <v-avatar size="64" rounded="lg" color="surface-variant" data-test="brand-logo-preview">
          <v-img v-if="logoUrl" :src="logoUrl" :alt="t('app.brands.logo.alt')" cover />
          <v-icon v-else icon="mdi-image-outline" />
        </v-avatar>

        <input
          ref="fileInput"
          type="file"
          accept="image/jpeg,image/png,image/webp"
          class="d-none"
          data-test="brand-logo-input"
          @change="onLogoChange"
        />

        <v-btn
          variant="outlined"
          size="small"
          :loading="logoBusy"
          :disabled="logoBusy"
          data-test="brand-logo-choose"
          @click="fileInput?.click()"
        >
          {{ logoUrl ? t('app.brands.logo.replace') : t('app.brands.logo.upload') }}
        </v-btn>

        <v-btn
          v-if="logoUrl"
          variant="text"
          size="small"
          color="error"
          :disabled="logoBusy"
          data-test="brand-logo-remove"
          @click="emit('logo-removed')"
        >
          {{ t('app.brands.logo.remove') }}
        </v-btn>
      </div>

      <div class="text-caption text-medium-emphasis mt-2">{{ t('app.brands.logo.hint') }}</div>

      <div
        v-if="logoErrors.length > 0"
        role="alert"
        aria-live="polite"
        class="text-error text-caption mt-1"
        data-test="brand-logo-error"
      >
        {{ logoErrors.join(' ') }}
      </div>
    </div>

    <div
      v-if="error"
      role="alert"
      aria-live="polite"
      class="text-error text-body-2 mb-3"
      data-test="brand-form-error"
    >
      {{ error }}
    </div>

    <div class="d-flex align-center justify-end ga-4">
      <div v-if="floorHint" class="text-caption text-medium-emphasis" data-test="brand-floor-hint">
        {{ floorHint }}
      </div>
      <v-btn
        type="submit"
        color="primary"
        :loading="submitting"
        :disabled="submitting || logoBusy || !floorMet"
        data-test="brand-form-submit"
      >
        {{ submitLabel }}
      </v-btn>
    </div>
  </form>
</template>
