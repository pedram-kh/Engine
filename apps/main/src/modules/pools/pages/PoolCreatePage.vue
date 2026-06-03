<script setup lang="ts">
import { ApiError, extractFieldErrors, type CreateTalentPoolPayload } from '@catalyst/api-client'
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { brandsApi } from '@/modules/brands/api/brands.api'
import { talentPoolsApi } from '../api/talentPools.api'
import PoolForm, { type BrandOption } from '../components/PoolForm.vue'

const { t } = useI18n()
const router = useRouter()
const agencyStore = useAgencyStore()

const form = ref<CreateTalentPoolPayload>({ name: '' })
const submitting = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Partial<Record<keyof CreateTalentPoolPayload, readonly string[]>>>({})
const brandOptions = ref<BrandOption[]>([])

async function loadBrands(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return
  try {
    const res = await brandsApi.list(agencyId, { per_page: 100, status: 'active' })
    brandOptions.value = res.data.map((b) => ({ value: b.id, title: b.attributes.name }))
  } catch {
    // Brand options are an optional label; a load failure leaves the select
    // empty (agency-wide pools still work) rather than blocking pool creation.
    brandOptions.value = []
  }
}

async function onSubmit(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  submitting.value = true
  error.value = null
  fieldErrors.value = {}

  try {
    const res = await talentPoolsApi.create(agencyId, form.value)
    await router.push({ name: 'pools.detail', params: { ulid: res.data.id } })
  } catch (err) {
    if (err instanceof ApiError) {
      const grouped = extractFieldErrors<keyof CreateTalentPoolPayload>(err)
      fieldErrors.value = grouped
      if (Object.keys(grouped).length === 0) {
        error.value = `[${err.code}] ${err.message}`
      }
    } else {
      error.value = t('app.pools.errors.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}

onMounted(loadBrands)
</script>

<template>
  <div data-test="pool-create-page">
    <div class="d-flex align-center mb-6">
      <v-btn
        icon="mdi-arrow-left"
        variant="text"
        size="small"
        :to="{ name: 'pools.list' }"
        class="mr-2"
        :aria-label="t('app.pools.actions.backToList')"
      />
      <h1 class="text-h5 ma-0" data-test="pool-create-heading">
        {{ t('app.pools.create.title') }}
      </h1>
    </div>

    <v-card class="pa-6" max-width="640">
      <PoolForm
        v-model="form"
        :submitting="submitting"
        :submit-label="t('app.pools.actions.save')"
        :error="error"
        :brand-options="brandOptions"
        :field-errors="fieldErrors"
        @submit="onSubmit"
      />
    </v-card>
  </div>
</template>
