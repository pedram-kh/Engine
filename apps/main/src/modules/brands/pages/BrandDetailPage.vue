<script setup lang="ts">
import { formatDateTime } from '@catalyst/api-client'
import type { BrandResource } from '@catalyst/api-client'
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import AgencyPageHeader from '@/modules/agency/components/AgencyPageHeader.vue'
import AgencyStatusPill from '@/modules/agency/components/AgencyStatusPill.vue'
import { brandsApi } from '../api/brands.api'

const { t, locale } = useI18n()
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

/** Set by BrandCreatePage when the post-create logo upload failed (D7). */
const logoUploadFailed = route.query.logo_failed === '1'

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

onMounted(loadBrand)
</script>

<template>
  <div class="agency-page agency-page--snug" data-test="brand-detail-page">
    <AgencyPageHeader
      :title="brand?.attributes.name ?? t('app.brands.detail.title')"
      title-test="brand-detail-heading"
    >
      <template #leading>
        <v-btn
          icon="mdi-arrow-left"
          variant="text"
          :to="{ name: 'brands.list' }"
          class="agency-icon-square"
          :aria-label="t('app.brands.actions.backToList')"
        />
      </template>
      <template v-if="brand" #title-suffix>
        <AgencyStatusPill
          kind="brand"
          :status="brand.attributes.status"
          data-test="brand-detail-status"
        />
      </template>
      <!-- An archived brand is readable (a campaign or pool can still point at one, AH-116 A3) but
           not editable: its `update` route does not resolve archived brands, so Edit would 404,
           and Archive is already done. The Archived pill beside the title says why. -->
      <template v-if="brand && brand.attributes.status === 'active'" #actions>
        <div class="d-flex ga-2">
          <v-btn
            variant="outlined"
            :to="{ name: 'brands.edit', params: { ulid } }"
            data-test="brand-edit-btn"
          >
            {{ t('app.brands.actions.edit') }}
          </v-btn>
          <v-btn
            color="warning"
            variant="outlined"
            data-test="brand-archive-btn"
            @click="archiveDialog = true"
          >
            {{ t('app.brands.actions.archive') }}
          </v-btn>
        </div>
      </template>
    </AgencyPageHeader>

    <!-- Loading skeleton -->
    <v-skeleton-loader v-if="loading" type="article" data-test="brand-detail-skeleton" />

    <!-- Error -->
    <v-alert v-else-if="error" type="error" variant="tonal" data-test="brand-detail-error">
      {{ error }}
    </v-alert>

    <!-- Brand details -->
    <div v-else-if="brand" class="agency-detail">
      <div class="agency-detail__main">
        <!--
          The create flow uploads the logo AFTER the brand row exists (D7), so a
          failed upload lands here. Say it plainly: the brand was created, the
          logo was not, and the next edit will require one.
        -->
        <v-alert
          v-if="logoUploadFailed"
          type="warning"
          variant="tonal"
          data-test="brand-detail-logo-failed"
        >
          {{ t('app.brands.logo.errors.uploadFailedAfterCreate') }}
        </v-alert>

        <section class="agency-card" data-test="brand-detail-card">
          <div class="d-flex align-center ga-4">
            <v-avatar size="72" rounded="lg" color="surface-variant" data-test="brand-detail-logo">
              <v-img
                v-if="brand.attributes.logo_url"
                :src="brand.attributes.logo_url"
                :alt="t('app.brands.logo.alt')"
                cover
              />
              <v-icon v-else icon="mdi-image-outline" />
            </v-avatar>
            <h2 class="agency-card__title">{{ brand.attributes.name }}</h2>
          </div>

          <hr class="agency-card__rule" />

          <dl class="agency-fields">
            <div v-if="brand.attributes.slug" class="agency-field" data-test="brand-detail-slug">
              <dt class="agency-field__label">{{ t('app.brands.fields.slug') }}</dt>
              <dd class="agency-field__value">{{ brand.attributes.slug }}</dd>
            </div>
            <div
              v-if="brand.attributes.industry"
              class="agency-field"
              data-test="brand-detail-industry"
            >
              <dt class="agency-field__label">{{ t('app.brands.fields.industry') }}</dt>
              <dd class="agency-field__value">{{ brand.attributes.industry }}</dd>
            </div>
            <div
              v-if="brand.attributes.website_url"
              class="agency-field"
              data-test="brand-detail-website"
            >
              <dt class="agency-field__label">{{ t('app.brands.fields.websiteUrl') }}</dt>
              <dd class="agency-field__value">{{ brand.attributes.website_url }}</dd>
            </div>
            <div class="agency-field" data-test="brand-detail-created-at">
              <dt class="agency-field__label">{{ t('app.brands.fields.createdAt') }}</dt>
              <dd class="agency-field__value">
                {{ formatDateTime(brand.attributes.created_at, locale, { dateStyle: 'medium' }) }}
              </dd>
            </div>
          </dl>

          <template v-if="brand.attributes.description">
            <hr class="agency-card__rule" />
            <div class="agency-field" data-test="brand-detail-description">
              <h3 class="agency-field__label">{{ t('app.brands.fields.description') }}</h3>
              <p class="agency-field__text ma-0">{{ brand.attributes.description }}</p>
            </div>
          </template>
        </section>
      </div>
    </div>

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
