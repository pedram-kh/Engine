<script setup lang="ts">
import { ApiError, extractFieldErrors, type CreateTalentPoolPayload } from '@catalyst/api-client'
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { brandsApi } from '@/modules/brands/api/brands.api'
import { talentPoolsApi } from '../api/talentPools.api'
import PoolForm, { type BrandOption } from '../components/PoolForm.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const agencyStore = useAgencyStore()

const ulid = route.params.ulid as string

const form = ref<CreateTalentPoolPayload>({ name: '' })
const loading = ref(true)
const submitting = ref(false)
const loadError = ref<string | null>(null)
const saveError = ref<string | null>(null)
const fieldErrors = ref<Partial<Record<keyof CreateTalentPoolPayload, readonly string[]>>>({})
const brandOptions = ref<BrandOption[]>([])

async function loadBrands(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return
  try {
    const res = await brandsApi.list(agencyId, { per_page: 100, status: 'active' })
    brandOptions.value = res.data.map((b) => ({ value: b.id, title: b.attributes.name }))
  } catch {
    brandOptions.value = []
  }
}

async function loadPool(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  loading.value = true
  loadError.value = null
  try {
    const res = await talentPoolsApi.show(agencyId, ulid)
    const attrs = res.data.attributes
    form.value = {
      name: attrs.name,
      description: attrs.description ?? null,
      brand_id: attrs.brand_id ?? null,
    }
  } catch {
    loadError.value = t('app.pools.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

async function onSubmit(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  submitting.value = true
  saveError.value = null
  fieldErrors.value = {}

  try {
    await talentPoolsApi.update(agencyId, ulid, form.value)
    await router.push({ name: 'pools.detail', params: { ulid } })
  } catch (err) {
    if (err instanceof ApiError) {
      const grouped = extractFieldErrors<keyof CreateTalentPoolPayload>(err)
      fieldErrors.value = grouped
      if (Object.keys(grouped).length === 0) {
        saveError.value = `[${err.code}] ${err.message}`
      }
    } else {
      saveError.value = t('app.pools.errors.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  void loadBrands()
  void loadPool()
})
</script>

<template>
  <div data-test="pool-edit-page">
    <div class="d-flex align-center mb-6">
      <v-btn
        icon="mdi-arrow-left"
        variant="text"
        size="small"
        :to="{ name: 'pools.detail', params: { ulid } }"
        class="mr-2"
        :aria-label="t('app.pools.actions.backToDetail')"
      />
      <h1 class="text-h5 ma-0" data-test="pool-edit-heading">{{ t('app.pools.edit.title') }}</h1>
    </div>

    <v-skeleton-loader v-if="loading" type="article" data-test="pool-edit-skeleton" />

    <v-alert v-else-if="loadError" type="error" variant="tonal" data-test="pool-edit-load-error">
      {{ loadError }}
    </v-alert>

    <v-card v-else class="pa-6" max-width="640">
      <PoolForm
        v-model="form"
        :submitting="submitting"
        :submit-label="t('app.pools.actions.save')"
        :error="saveError"
        :brand-options="brandOptions"
        :field-errors="fieldErrors"
        @submit="onSubmit"
      />
    </v-card>
  </div>
</template>
