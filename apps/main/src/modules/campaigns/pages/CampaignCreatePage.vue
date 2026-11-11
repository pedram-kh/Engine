<script setup lang="ts">
import {
  ApiError,
  extractFieldErrors,
  type BrandOptionResource,
  type CreateCampaignPayload,
} from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { brandsApi } from '@/modules/brands/api/brands.api'
import { campaignsApi } from '../api/campaigns.api'
import CampaignForm from '../components/CampaignForm.vue'
import AgencyPageHeader from '@/modules/agency/components/AgencyPageHeader.vue'

const { t } = useI18n()
const router = useRouter()
const agencyStore = useAgencyStore()
const authStore = useAuthStore()
// AH-118: the region switch renders only while the flag is on (`/me` features).
const regionRestrictionEnabled = computed(
  () => authStore.user?.attributes.features?.campaign_region_restriction === true,
)

// AH-120: the brand multi-select and end date render only while the flag is on.
const brandExclusivityEnabled = computed(
  () => authStore.user?.attributes.features?.campaign_brand_exclusivity === true,
)

function emptyForm(): CreateCampaignPayload {
  // `objective` is omitted — the server defaults it to `ugc` (D-1).
  return {
    brand_id: '',
    name: '',
    budget_minor_units: 0,
    budget_currency: 'EUR',
    // AH-069 D1 — the PRODUCT default, and the reason it is stated here rather
    // than left to the server: a campaign created through this form hands off
    // at approval. The column's own default is the opposite (`true`, posting
    // expected) because it is the SAFETY FLOOR for every path that does not
    // name the field — a direct API POST, a factory, an import. Naming it here
    // means the two never have to agree.
    creator_posts_content: false,
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
    // AH-085 — every active brand, unpaginated (not `list()`'s 25-row page),
    // so agencies with more than a page of brands don't lose the alphabetical
    // tail from the select.
    const res = await brandsApi.listOptions(agencyId, 'active')
    brands.value = res.data.map((b: BrandOptionResource) => ({ id: b.id, name: b.attributes.name }))
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
    if (err instanceof ApiError && err.code === 'campaign.brand_exclusivity_disabled') {
      error.value = t('app.campaigns.errors.brandExclusivityDisabled')
    } else if (err instanceof ApiError) {
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
  <div class="agency-page agency-page--snug" data-test="campaign-create-page">
    <AgencyPageHeader :title="t('app.campaigns.create.title')" title-test="campaign-create-heading">
      <template #leading>
        <v-btn
          icon="mdi-arrow-left"
          variant="text"
          :to="{ name: 'campaigns.list' }"
          class="agency-icon-square"
          :aria-label="t('app.campaigns.actions.backToList')"
        />
      </template>
    </AgencyPageHeader>

    <section class="agency-card agency-card--form">
      <CampaignForm
        v-model="form"
        :brands="brands"
        :submitting="submitting"
        :submit-label="t('app.campaigns.actions.save')"
        :error="error"
        :field-errors="fieldErrors"
        :region-restriction-enabled="regionRestrictionEnabled"
        :brand-exclusivity-enabled="brandExclusivityEnabled"
        @submit="onSubmit"
      />
    </section>
  </div>
</template>
