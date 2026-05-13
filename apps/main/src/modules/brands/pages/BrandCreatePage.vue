<script setup lang="ts">
import type { CreateBrandPayload } from '@catalyst/api-client'
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

async function onSubmit(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  submitting.value = true
  error.value = null

  try {
    const res = await brandsApi.create(agencyId, form.value)
    await router.push({ name: 'brands.detail', params: { ulid: res.data.id } })
  } catch {
    error.value = t('app.brands.errors.saveFailed')
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
        @submit="onSubmit"
      />
    </v-card>
  </div>
</template>
