<script setup lang="ts">
import type { BrandResource } from '@catalyst/api-client'
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { brandsApi } from '../api/brands.api'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const agencyStore = useAgencyStore()

const brand = ref<BrandResource | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

const archiveDialog = ref(false)
const archiving = ref(false)
const archiveError = ref<string | null>(null)

const ulid = route.params.ulid as string

async function loadBrand(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  loading.value = true
  error.value = null
  try {
    const res = await brandsApi.show(agencyId, ulid)
    brand.value = res.data
  } catch {
    error.value = t('app.brands.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

async function confirmArchive(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null || brand.value === null) return

  archiving.value = true
  archiveError.value = null
  try {
    await brandsApi.archive(agencyId, ulid)
    archiveDialog.value = false
    await router.push({ name: 'brands.list' })
  } catch {
    archiveError.value = t('app.brands.errors.archiveFailed')
  } finally {
    archiving.value = false
  }
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString()
}

onMounted(loadBrand)
</script>

<template>
  <div data-test="brand-detail-page">
    <div class="d-flex align-center mb-6">
      <v-btn
        icon="mdi-arrow-left"
        variant="text"
        size="small"
        :to="{ name: 'brands.list' }"
        class="mr-2"
        :aria-label="t('app.brands.actions.backToList')"
      />
      <h1 class="text-h5 ma-0" data-test="brand-detail-heading">
        {{ brand?.attributes.name ?? t('app.brands.detail.title') }}
      </h1>
      <v-spacer />
      <template v-if="brand">
        <v-btn
          variant="outlined"
          :to="{ name: 'brands.edit', params: { ulid } }"
          class="mr-2"
          data-test="brand-edit-btn"
        >
          {{ t('app.brands.actions.edit') }}
        </v-btn>
        <v-btn
          v-if="brand.attributes.status === 'active'"
          color="warning"
          variant="outlined"
          data-test="brand-archive-btn"
          @click="archiveDialog = true"
        >
          {{ t('app.brands.actions.archive') }}
        </v-btn>
      </template>
    </div>

    <!-- Loading skeleton -->
    <v-skeleton-loader v-if="loading" type="article" data-test="brand-detail-skeleton" />

    <!-- Error -->
    <v-alert v-else-if="error" type="error" variant="tonal" data-test="brand-detail-error">
      {{ error }}
    </v-alert>

    <!-- Brand details -->
    <v-card v-else-if="brand" class="pa-6" max-width="640" data-test="brand-detail-card">
      <v-list>
        <v-list-item :title="t('app.brands.fields.name')" :subtitle="brand.attributes.name" />
        <v-list-item
          v-if="brand.attributes.slug"
          :title="t('app.brands.fields.slug')"
          :subtitle="brand.attributes.slug"
          data-test="brand-detail-slug"
        />
        <v-list-item :title="t('app.brands.fields.status')">
          <template #subtitle>
            <v-chip
              :color="brand.attributes.status === 'active' ? 'success' : 'default'"
              size="small"
              variant="tonal"
              data-test="brand-detail-status"
            >
              {{ t(`app.brands.status.${brand.attributes.status}`) }}
            </v-chip>
          </template>
        </v-list-item>
        <v-list-item
          v-if="brand.attributes.description"
          :title="t('app.brands.fields.description')"
          :subtitle="brand.attributes.description"
          data-test="brand-detail-description"
        />
        <v-list-item
          v-if="brand.attributes.industry"
          :title="t('app.brands.fields.industry')"
          :subtitle="brand.attributes.industry"
          data-test="brand-detail-industry"
        />
        <v-list-item
          v-if="brand.attributes.website_url"
          :title="t('app.brands.fields.websiteUrl')"
          :subtitle="brand.attributes.website_url"
          data-test="brand-detail-website"
        />
        <v-list-item
          v-if="brand.attributes.default_currency"
          :title="t('app.brands.fields.defaultCurrency')"
          :subtitle="brand.attributes.default_currency"
          data-test="brand-detail-currency"
        />
        <v-list-item
          v-if="brand.attributes.default_language"
          :title="t('app.brands.fields.defaultLanguage')"
          :subtitle="brand.attributes.default_language"
          data-test="brand-detail-language"
        />
        <v-list-item
          :title="'Created'"
          :subtitle="formatDate(brand.attributes.created_at)"
          data-test="brand-detail-created-at"
        />
      </v-list>
    </v-card>

    <!-- Archive dialog -->
    <v-dialog v-model="archiveDialog" max-width="440" data-test="brand-detail-archive-dialog">
      <v-card v-if="brand">
        <v-card-title class="text-h6 pa-4">
          {{ t('app.brands.archive.confirmTitle') }}
        </v-card-title>
        <v-card-text>
          <p data-test="brand-detail-archive-message">
            {{ t('app.brands.archive.confirmMessage', { name: brand.attributes.name }) }}
          </p>
          <v-alert
            v-if="archiveError"
            type="error"
            variant="tonal"
            class="mt-2"
            data-test="brand-detail-archive-error"
          >
            {{ archiveError }}
          </v-alert>
        </v-card-text>
        <v-card-actions class="px-4 pb-4">
          <v-spacer />
          <v-btn
            variant="text"
            :disabled="archiving"
            data-test="brand-detail-archive-cancel"
            @click="archiveDialog = false"
          >
            {{ t('app.brands.archive.cancel') }}
          </v-btn>
          <v-btn
            color="warning"
            variant="flat"
            :loading="archiving"
            data-test="brand-detail-archive-confirm"
            @click="confirmArchive"
          >
            {{ t('app.brands.archive.confirm') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>
