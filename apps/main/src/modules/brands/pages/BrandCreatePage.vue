<script setup lang="ts">
import { ApiError, extractFieldErrors, type CreateBrandPayload } from '@catalyst/api-client'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { brandsApi } from '../api/brands.api'
import BrandForm from '../components/BrandForm.vue'

const { t } = useI18n()
const router = useRouter()
const agencyStore = useAgencyStore()

const form = ref<CreateBrandPayload>({ name: '' })
const submitting = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Partial<Record<keyof CreateBrandPayload, readonly string[]>>>({})

async function onSubmit(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  submitting.value = true
  error.value = null
  fieldErrors.value = {}

  try {
    const res = await brandsApi.create(agencyId, form.value)
    await router.push({ name: 'brands.detail', params: { ulid: res.data.id } })
  } catch (err) {
    if (err instanceof ApiError) {
      const grouped = extractFieldErrors<keyof CreateBrandPayload>(err)
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
        @submit="onSubmit"
      />
    </v-card>
  </div>
</template>
