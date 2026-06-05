<script setup lang="ts">
import {
  ApiError,
  extractFieldErrors,
  type BrandResource,
  type CreateCampaignPayload,
} from '@catalyst/api-client'
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { brandsApi } from '@/modules/brands/api/brands.api'
import { campaignsApi } from '../api/campaigns.api'
import CampaignForm from '../components/CampaignForm.vue'

const { t } = useI18n()
const router = useRouter()
const agencyStore = useAgencyStore()

function emptyForm(): CreateCampaignPayload {
  return {
    brand_id: '',
    name: '',
    objective: 'awareness',
    budget_minor_units: 0,
    budget_currency: 'EUR',
  }
}

const form = ref<CreateCampaignPayload>(emptyForm())
const brands = ref<{ id: string; name: string }[]>([])
const submitting = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Partial<Record<string, readonly string[]>>>({})

async function loadBrands(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return
  try {
    const res = await brandsApi.list(agencyId, { per_page: 100, status: 'active' })
    brands.value = res.data.map((b: BrandResource) => ({ id: b.id, name: b.attributes.name }))
  } catch {
    brands.value = []
  }
}

onMounted(() => {
  void loadBrands()
})

async function onSubmit(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  submitting.value = true
  error.value = null
  fieldErrors.value = {}

  try {
    const res = await campaignsApi.create(agencyId, form.value)
    await router.push({ name: 'campaigns.detail', params: { ulid: res.data.id } })
  } catch (err) {
    if (err instanceof ApiError) {
      const grouped = extractFieldErrors<string>(err)
      fieldErrors.value = grouped
      if (Object.keys(grouped).length === 0) {
        error.value = `[${err.code}] ${err.message}`
      }
      console.error('[CampaignCreatePage] save failed', {
        status: err.status,
        code: err.code,
        details: err.details,
        requestId: err.requestId,
      })
    } else {
      error.value = t('app.campaigns.errors.saveFailed')
      console.error('[CampaignCreatePage] save failed (non-ApiError)', err)
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div data-test="campaign-create-page">
    <div class="d-flex align-center mb-6">
      <v-btn
        icon="mdi-arrow-left"
        variant="text"
        size="small"
        :to="{ name: 'campaigns.list' }"
        class="mr-2"
        :aria-label="t('app.campaigns.actions.backToList')"
      />
      <h1 class="text-h5 ma-0" data-test="campaign-create-heading">
        {{ t('app.campaigns.create.title') }}
      </h1>
    </div>

    <v-card class="pa-6" max-width="720">
      <CampaignForm
        v-model="form"
        :brands="brands"
        :submitting="submitting"
        :submit-label="t('app.campaigns.actions.save')"
        :error="error"
        :field-errors="fieldErrors"
        @submit="onSubmit"
      />
    </v-card>
  </div>
</template>
