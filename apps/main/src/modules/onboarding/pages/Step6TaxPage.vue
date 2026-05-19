<script setup lang="ts">
/**
 * Step6TaxPage — wizard Step 6 (Tax information).
 *
 * Sprint 3 Chunk 3 sub-step 8.
 *
 * Form-only step — there's no vendor bounce here. The four
 * non-PII fields the creator self-types (form type, legal name,
 * tax ID, address) are POSTed to `/wizard/tax` which stores
 * them encrypted server-side and flips
 * `tax_profile_complete=true`. The creator-self view of this
 * data is intentionally limited to the boolean flag — the form
 * is a one-time submit, not a "view your tax ID" surface (PII
 * minimization).
 *
 * a11y (F2=b): the form is a `<form>` with proper labels on
 * every input and inline error messages bound via
 * `:error-messages`. The submit button is disabled while the
 * store action is in-flight.
 *
 * Sprint 3 stabilization (May 19, 2026):
 *   - Per-field 422 rendering. The `validation.failed` envelope
 *     emitted by `ValidationExceptionRenderer` has no top-level
 *     bundle entry — feeding `error.code` straight to `t()`
 *     produced the literal "validation.failed" red banner. Now
 *     uses `extractFieldErrors` for per-input binding (same shape
 *     as SignUpPage / BrandCreatePage). The nested address fields
 *     come back as dot-notation keys (`address.country_code`, …)
 *     matching Laravel's `$validator->errors()` output.
 *   - Country picker swapped from `<v-text-field>` to `<v-select>`
 *     using the shared `COUNTRY_OPTIONS`. The backend's rule is
 *     `size:2` (ISO-3166-1 alpha-2), so a free-text input invited
 *     users to type "Spain" and bounce off the validator.
 */

import { ApiError, extractFieldErrors } from '@catalyst/api-client'
import type { CreatorTaxFormType, CreatorTaxUpdatePayload } from '@catalyst/api-client'
import { TaxProfileDisplay } from '@catalyst/ui'
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { COUNTRY_OPTIONS } from '../data/countries'
import { useOnboardingStore } from '../stores/useOnboardingStore'

const { t } = useI18n()
const router = useRouter()
const store = useOnboardingStore()

/**
 * Backend field-key union (matches `UpsertTaxProfileRequest::rules()`
 * keys after the `ValidationExceptionRenderer` flattens the pointer).
 * Nested address rules surface as dot-notation — Laravel's validator
 * reports them that way and `extractFieldErrors` preserves it.
 */
type TaxField =
  | 'tax_form_type'
  | 'legal_name'
  | 'tax_id'
  | 'address.country_code'
  | 'address.city'
  | 'address.postal_code'
  | 'address.street'

const fieldErrors = ref<Partial<Record<TaxField, readonly string[]>>>({})
const submitErrorKey = ref<string | null>(null)

const TAX_FORM_TYPES: CreatorTaxFormType[] = [
  'eu_self_employed',
  'eu_company',
  'uk_self_employed',
  'uk_company',
]

const draft = reactive<CreatorTaxUpdatePayload>({
  tax_form_type: 'eu_self_employed',
  legal_name: '',
  tax_id: '',
  address: {
    country_code: '',
    city: '',
    postal_code: '',
    street: '',
  },
})

const isComplete = computed(() => store.creator?.attributes.tax_profile_complete ?? false)
const statusLabel = computed(() =>
  t(
    isComplete.value
      ? 'creator.ui.wizard.steps.tax.status_complete'
      : 'creator.ui.wizard.steps.tax.status_incomplete',
  ),
)

const isSaveDisabled = computed(
  () =>
    store.isLoadingTax ||
    draft.legal_name.trim() === '' ||
    draft.tax_id.trim() === '' ||
    draft.address.country_code.trim() === '' ||
    draft.address.city.trim() === '' ||
    draft.address.postal_code.trim() === '' ||
    draft.address.street.trim() === '',
)

const formTypeOptions = computed(() =>
  TAX_FORM_TYPES.map((value) => ({
    value,
    title: t(`creator.ui.wizard.tax_form_types.${value}`),
  })),
)

async function save(): Promise<void> {
  submitErrorKey.value = null
  fieldErrors.value = {}
  try {
    await store.updateTax({
      tax_form_type: draft.tax_form_type,
      legal_name: draft.legal_name.trim(),
      tax_id: draft.tax_id.trim(),
      address: {
        country_code: draft.address.country_code.trim(),
        city: draft.address.city.trim(),
        postal_code: draft.address.postal_code.trim(),
        street: draft.address.street.trim(),
      },
    })
  } catch (error) {
    if (error instanceof ApiError) {
      fieldErrors.value = extractFieldErrors<TaxField>(error)
    }
    // Banner is the fallback ONLY when no field errors were extracted
    // — otherwise the per-input messages tell the user exactly what
    // to fix, and a duplicate banner would be noise.
    if (Object.keys(fieldErrors.value).length === 0) {
      submitErrorKey.value = 'creator.ui.errors.upload_failed'
    }
  }
}

async function advance(): Promise<void> {
  if (!isComplete.value) return
  await router.push({ name: 'onboarding.payout' })
}
</script>

<template>
  <section class="tax-step" data-testid="step-tax">
    <header class="tax-step__header">
      <h2 class="text-h5">{{ t('creator.ui.wizard.steps.tax.title') }}</h2>
      <p class="text-body-2 text-medium-emphasis">
        {{ t('creator.ui.wizard.steps.tax.description') }}
      </p>
    </header>

    <div class="tax-step__status-line">
      <span class="tax-step__status-label">
        {{ t('creator.ui.wizard.steps.tax.current_status') }}
      </span>
      <TaxProfileDisplay :is-complete="isComplete" :label="statusLabel" />
    </div>

    <form class="tax-step__form" data-testid="tax-form" @submit.prevent="save">
      <v-select
        v-model="draft.tax_form_type"
        :items="formTypeOptions"
        item-title="title"
        item-value="value"
        :label="t('creator.ui.wizard.fields.tax_form_type')"
        :error-messages="fieldErrors.tax_form_type"
        data-testid="tax-form-type"
        density="comfortable"
      />
      <v-text-field
        v-model="draft.legal_name"
        :label="t('creator.ui.wizard.fields.legal_name')"
        :error-messages="fieldErrors.legal_name"
        data-testid="tax-legal-name"
        density="comfortable"
      />
      <v-text-field
        v-model="draft.tax_id"
        :label="t('creator.ui.wizard.fields.tax_id')"
        :error-messages="fieldErrors.tax_id"
        data-testid="tax-id"
        density="comfortable"
      />
      <v-text-field
        v-model="draft.address.street"
        :label="t('creator.ui.wizard.fields.address_street')"
        :error-messages="fieldErrors['address.street']"
        data-testid="tax-address-street"
        density="comfortable"
      />
      <v-text-field
        v-model="draft.address.city"
        :label="t('creator.ui.wizard.fields.address_city')"
        :error-messages="fieldErrors['address.city']"
        data-testid="tax-address-city"
        density="comfortable"
      />
      <v-text-field
        v-model="draft.address.postal_code"
        :label="t('creator.ui.wizard.fields.address_postal_code')"
        :error-messages="fieldErrors['address.postal_code']"
        data-testid="tax-address-postal"
        density="comfortable"
      />
      <v-select
        v-model="draft.address.country_code"
        :items="COUNTRY_OPTIONS"
        item-title="label"
        item-value="code"
        :label="t('creator.ui.wizard.fields.address_country')"
        :error-messages="fieldErrors['address.country_code']"
        data-testid="tax-address-country"
        density="comfortable"
      />

      <div
        v-if="submitErrorKey !== null"
        role="alert"
        class="tax-step__error"
        data-testid="tax-submit-error"
      >
        {{ t(submitErrorKey) }}
      </div>

      <div class="tax-step__form-actions">
        <v-btn
          type="submit"
          color="primary"
          variant="tonal"
          :loading="store.isLoadingTax"
          :disabled="isSaveDisabled"
          data-testid="tax-save"
        >
          {{ t('creator.ui.wizard.actions.save') }}
        </v-btn>
      </div>
    </form>

    <div class="tax-step__actions">
      <v-btn color="primary" :disabled="!isComplete" data-testid="tax-advance" @click="advance">
        {{ t('creator.ui.wizard.actions.save_and_continue') }}
      </v-btn>
    </div>
  </section>
</template>

<style scoped>
.tax-step {
  display: flex;
  flex-direction: column;
  gap: 20px;
  max-width: 720px;
}

.tax-step__status-line {
  display: flex;
  align-items: center;
  gap: 12px;
}

.tax-step__status-label {
  font-weight: 500;
}

.tax-step__form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.tax-step__form-actions {
  display: flex;
  justify-content: flex-end;
}

.tax-step__error {
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}

.tax-step__actions {
  display: flex;
  justify-content: flex-end;
}
</style>
