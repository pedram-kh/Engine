<script setup lang="ts">
import type { CreateBrandPayload } from '@catalyst/api-client'
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { brandsApi } from '../api/brands.api'
import BrandForm from '../components/BrandForm.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const agencyStore = useAgencyStore()

const ulid = route.params.ulid as string

const form = ref<CreateBrandPayload>({ name: '' })
const loading = ref(true)
const submitting = ref(false)
const loadError = ref<string | null>(null)
const saveError = ref<string | null>(null)

async function loadBrand(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  loading.value = true
  loadError.value = null
  try {
    const res = await brandsApi.show(agencyId, ulid)
    const attrs = res.data.attributes
    form.value = {
      name: attrs.name,
      slug: attrs.slug ?? undefined,
      description: attrs.description ?? undefined,
      industry: attrs.industry ?? undefined,
      website_url: attrs.website_url ?? undefined,
      default_currency: attrs.default_currency ?? undefined,
      default_language: attrs.default_language ?? undefined,
    }
  } catch {
    loadError.value = t('app.brands.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

async function onSubmit(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  submitting.value = true
  saveError.value = null

  try {
    await brandsApi.update(agencyId, ulid, form.value)
    await router.push({ name: 'brands.detail', params: { ulid } })
  } catch {
    saveError.value = t('app.brands.errors.saveFailed')
  } finally {
    submitting.value = false
  }
}

onMounted(loadBrand)
</script>

<template>
  <div data-test="brand-edit-page">
    <div class="d-flex align-center mb-6">
      <v-btn
        icon="mdi-arrow-left"
        variant="text"
        size="small"
        :to="{ name: 'brands.detail', params: { ulid } }"
        class="mr-2"
        :aria-label="t('app.brands.actions.backToDetail')"
      />
      <h1 class="text-h5 ma-0" data-test="brand-edit-heading">
        {{ t('app.brands.edit.title') }}
      </h1>
    </div>

    <v-skeleton-loader v-if="loading" type="article" data-test="brand-edit-skeleton" />

    <v-alert v-else-if="loadError" type="error" variant="tonal" data-test="brand-edit-load-error">
      {{ loadError }}
    </v-alert>

    <v-card v-else class="pa-6" max-width="640">
      <BrandForm
        v-model="form"
        :submitting="submitting"
        :submit-label="t('app.brands.actions.save')"
        :error="saveError"
        @submit="onSubmit"
      />
    </v-card>
  </div>
</template>
