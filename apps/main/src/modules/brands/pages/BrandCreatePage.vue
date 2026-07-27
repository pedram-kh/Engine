<script setup lang="ts">
/**
 * Brand creation.
 *
 * Two-step by necessity (AH-053, D7): the logo endpoint needs a brand row to
 * attach the object to, so the page creates the brand and THEN uploads the
 * chosen logo. The form holds the file and requires one before submit, so the
 * user experiences a single act.
 *
 * The honest consequence: between the two calls the brand exists without a
 * logo, and a failed upload leaves it floor-incomplete. That is surfaced —
 * the page lands on the brand detail with an explicit "logo upload failed"
 * message rather than pretending the create succeeded cleanly — and the D6
 * edit gate will demand a logo before the next edit can be saved.
 */

import { ApiError, extractFieldErrors, type CreateBrandPayload } from '@catalyst/api-client'
import { onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { brandsApi } from '../api/brands.api'
import BrandForm from '../components/BrandForm.vue'

const { t } = useI18n()
const router = useRouter()
const agencyStore = useAgencyStore()

const form = ref<Partial<CreateBrandPayload>>({ name: '' })
const submitting = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Partial<Record<string, readonly string[]>>>({})

// The logo is held locally until the brand row exists. `logoPreview` is an
// object URL, revoked on replace/unmount so the blob is not leaked.
const logoFile = ref<File | null>(null)
const logoPreview = ref<string | null>(null)
const logoError = ref<string | null>(null)

function setLogo(file: File | null): void {
  if (logoPreview.value !== null) URL.revokeObjectURL(logoPreview.value)
  logoFile.value = file
  logoPreview.value = file === null ? null : URL.createObjectURL(file)
  logoError.value = null
}

onBeforeUnmount(() => {
  if (logoPreview.value !== null) URL.revokeObjectURL(logoPreview.value)
})

async function onSubmit(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  submitting.value = true
  error.value = null
  logoError.value = null
  fieldErrors.value = {}

  try {
    const res = await brandsApi.create(agencyId, form.value as CreateBrandPayload)
    const brandId = res.data.id

    if (logoFile.value !== null) {
      try {
        await brandsApi.uploadLogo(agencyId, brandId, logoFile.value)
      } catch (uploadErr) {
        // The brand IS created. Say so, and say what is still missing.
        console.error('[BrandCreatePage] logo upload failed after create', uploadErr)
        await router.push({
          name: 'brands.detail',
          params: { ulid: brandId },
          query: { logo_failed: '1' },
        })
        return
      }
    }

    await router.push({ name: 'brands.detail', params: { ulid: brandId } })
  } catch (err) {
    if (err instanceof ApiError) {
      const grouped = extractFieldErrors<string>(err)
      fieldErrors.value = grouped

      // Per-field rendering owns the validation case; surface a top-level
      // banner only for non-validation failures (auth, tenancy, 5xx, etc.)
      // so the user gets a single signal source per error class.
      if (Object.keys(grouped).length === 0) {
        error.value = `[${err.code}] ${err.message}`
      }

      console.error('[BrandCreatePage] save failed', {
        status: err.status,
        code: err.code,
        details: err.details,
        requestId: err.requestId,
      })
    } else {
      error.value = t('app.brands.errors.saveFailed')
      console.error('[BrandCreatePage] save failed (non-ApiError)', err)
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div data-test="brand-create-page">
    <div class="d-flex align-center mb-6">
      <v-btn
        icon="mdi-arrow-left"
        variant="text"
        size="small"
        :to="{ name: 'brands.list' }"
        class="mr-2"
        :aria-label="t('app.brands.actions.backToList')"
      />
      <h1 class="text-h5 ma-0" data-test="brand-create-heading">
        {{ t('app.brands.create.title') }}
      </h1>
    </div>

    <v-card class="pa-6" max-width="640">
      <BrandForm
        v-model="form"
        :submitting="submitting"
        :submit-label="t('app.brands.actions.save')"
        :error="error"
        :field-errors="fieldErrors"
        :logo-url="logoPreview"
        :logo-error="logoError"
        @submit="onSubmit"
        @logo-selected="setLogo"
        @logo-removed="setLogo(null)"
      />
    </v-card>
  </div>
</template>
