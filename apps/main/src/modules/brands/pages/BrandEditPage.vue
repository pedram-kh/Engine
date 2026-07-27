<script setup lang="ts">
/**
 * Brand edit.
 *
 * The brand row already exists here, so the logo behaves exactly like the
 * creator avatar (AH-053, D7): choosing a file uploads it immediately and the
 * response carries the refreshed brand, including a freshly-signed
 * `logo_url`. Removal is immediate too.
 *
 * The D6 floor is mirrored by the shared form: brands created before the floor
 * arrive here missing fields, the submit button stays held with the missing
 * fields named, and the API's refusal is never the first thing the user sees.
 */

import { ApiError, extractFieldErrors, type CreateBrandPayload } from '@catalyst/api-client'
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

const form = ref<Partial<CreateBrandPayload>>({ name: '' })
const loading = ref(true)
const submitting = ref(false)
const loadError = ref<string | null>(null)
const saveError = ref<string | null>(null)
const fieldErrors = ref<Partial<Record<string, readonly string[]>>>({})

const logoUrl = ref<string | null>(null)
const logoBusy = ref(false)
const logoError = ref<string | null>(null)

async function loadBrand(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  loading.value = true
  loadError.value = null
  try {
    const res = await brandsApi.show(agencyId, ulid)
    const attrs = res.data.attributes
    // `default_currency` / `default_language` are deliberately NOT seeded
    // (D8): the form no longer renders them, so omitting them from the PATCH
    // preserves the stored values. Re-seeding would re-send fields the user
    // never saw — the AH-032 wipe mechanic.
    form.value = {
      name: attrs.name,
      slug: attrs.slug ?? undefined,
      description: attrs.description ?? undefined,
      industry: attrs.industry ?? undefined,
      website_url: attrs.website_url ?? undefined,
    }
    logoUrl.value = attrs.logo_url
  } catch {
    loadError.value = t('app.brands.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

async function onLogoSelected(file: File): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  logoBusy.value = true
  logoError.value = null
  try {
    const res = await brandsApi.uploadLogo(agencyId, ulid, file)
    logoUrl.value = res.data.attributes.logo_url
  } catch (err) {
    logoError.value =
      err instanceof ApiError ? err.message : t('app.brands.logo.errors.uploadFailed')
    console.error('[BrandEditPage] logo upload failed', err)
  } finally {
    logoBusy.value = false
  }
}

async function onLogoRemoved(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  logoBusy.value = true
  logoError.value = null
  try {
    const res = await brandsApi.deleteLogo(agencyId, ulid)
    logoUrl.value = res.data.attributes.logo_url
  } catch (err) {
    logoError.value =
      err instanceof ApiError ? err.message : t('app.brands.logo.errors.removeFailed')
    console.error('[BrandEditPage] logo removal failed', err)
  } finally {
    logoBusy.value = false
  }
}

async function onSubmit(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  submitting.value = true
  saveError.value = null
  fieldErrors.value = {}

  try {
    await brandsApi.update(agencyId, ulid, form.value)
    await router.push({ name: 'brands.detail', params: { ulid } })
  } catch (err) {
    if (err instanceof ApiError) {
      const grouped = extractFieldErrors<string>(err)
      fieldErrors.value = grouped

      if (Object.keys(grouped).length === 0) {
        saveError.value = `[${err.code}] ${err.message}`
      }

      console.error('[BrandEditPage] save failed', {
        status: err.status,
        code: err.code,
        details: err.details,
        requestId: err.requestId,
      })
    } else {
      saveError.value = t('app.brands.errors.saveFailed')
      console.error('[BrandEditPage] save failed (non-ApiError)', err)
    }
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
        :field-errors="fieldErrors"
        :logo-url="logoUrl"
        :logo-busy="logoBusy"
        :logo-error="logoError"
        @submit="onSubmit"
        @logo-selected="onLogoSelected"
        @logo-removed="onLogoRemoved"
      />
    </v-card>
  </div>
</template>
