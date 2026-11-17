<script setup lang="ts">
/**
 * PL-1.1 — the wave page: header (name, status pill, Edit for an admin or manager), a fields card (go-live
 * date, markets, linked campaign, created by, updated), and a way back to the brand.
 *
 * No design frame exists for this page; it is built from the agency page header, card and field styles.
 * With `features.waves` off the page redirects to the brand; an unknown wave (or one in another agency) is
 * the not-found state, since the server answers 404 for both.
 */
import { formatDateTime, labelForCountryCode } from '@catalyst/api-client'
import type { WaveResource } from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import AgencyPageHeader from '@/modules/agency/components/AgencyPageHeader.vue'
import AgencyStatusPill from '@/modules/agency/components/AgencyStatusPill.vue'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { wavesApi } from '../api/waves.api'
import NewWaveDialog from '../components/NewWaveDialog.vue'
import WaveProgressSegments from '../components/WaveProgressSegments.vue'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const agencyStore = useAgencyStore()
const authStore = useAuthStore()

const brandUlid = route.params.ulid as string
const waveUlid = route.params.waveUlid as string

const wavesOn = computed(() => authStore.user?.attributes.features?.waves === true)
const canManage = computed(
  () => agencyStore.currentRole === 'agency_admin' || agencyStore.currentRole === 'agency_manager',
)

const wave = ref<WaveResource | null>(null)
const loading = ref(true)
const failed = ref(false)
const editOpen = ref(false)

async function load(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return
  loading.value = true
  failed.value = false
  try {
    wave.value = (await wavesApi.show(agencyId, brandUlid, waveUlid)).data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  if (!wavesOn.value) {
    await router.replace({ name: 'brands.detail', params: { ulid: brandUlid } })
    return
  }
  await load()
})
</script>

<template>
  <div class="agency-page agency-page--snug" data-test="wave-detail-page">
    <AgencyPageHeader
      :title="wave?.attributes.name ?? t('app.waves.detail.title')"
      title-test="wave-detail-heading"
    >
      <template #leading>
        <v-btn
          icon="mdi-arrow-left"
          variant="text"
          :to="{ name: 'brands.detail', params: { ulid: brandUlid } }"
          class="agency-icon-square"
          :aria-label="t('app.brands.actions.backToDetail')"
          data-test="wave-back"
        />
      </template>
      <template v-if="wave" #title-suffix>
        <AgencyStatusPill kind="wave" :status="wave.attributes.status" data-test="wave-status" />
      </template>
      <template v-if="wave && canManage" #actions>
        <v-btn variant="outlined" data-test="wave-edit-btn" @click="editOpen = true">
          {{ t('app.brands.actions.edit') }}
        </v-btn>
      </template>
    </AgencyPageHeader>

    <v-skeleton-loader v-if="loading" type="article" data-test="wave-detail-skeleton" />
    <v-alert v-else-if="failed" type="error" variant="tonal" data-test="wave-detail-error">
      {{ t('app.waves.detail.notFound') }}
    </v-alert>

    <section v-else-if="wave" class="agency-card" data-test="wave-detail-card">
      <dl class="agency-fields">
        <div class="agency-field" data-test="wave-field-brand">
          <dt class="agency-field__label">{{ t('app.waves.detail.brand') }}</dt>
          <dd class="agency-field__value">
            <router-link :to="{ name: 'brands.detail', params: { ulid: brandUlid } }">
              {{ wave.relationships.brand.data.attributes.name }}
            </router-link>
          </dd>
        </div>
        <div class="agency-field" data-test="wave-field-go-live">
          <dt class="agency-field__label">{{ t('app.waves.dialog.goLive') }}</dt>
          <dd class="agency-field__value">
            {{
              formatDateTime(`${wave.attributes.go_live_on}T00:00:00`, locale, {
                dateStyle: 'medium',
              })
            }}
          </dd>
        </div>
        <div class="agency-field" data-test="wave-field-progress">
          <dt class="agency-field__label">{{ t('app.waves.table.progress') }}</dt>
          <dd class="agency-field__value"><WaveProgressSegments /></dd>
        </div>
      </dl>

      <hr class="agency-card__rule" />

      <div class="agency-field" data-test="wave-field-markets">
        <h3 class="agency-field__label">{{ t('app.waves.dialog.markets') }}</h3>
        <div class="d-flex flex-wrap ga-2">
          <v-chip v-for="code in wave.attributes.markets" :key="code" size="small">
            {{ labelForCountryCode(code) }}
          </v-chip>
        </div>
      </div>

      <hr class="agency-card__rule" />

      <dl class="agency-fields">
        <div class="agency-field" data-test="wave-field-campaign">
          <dt class="agency-field__label">{{ t('app.waves.detail.campaign') }}</dt>
          <dd class="agency-field__value">
            <router-link
              v-if="wave.relationships.campaign"
              :to="{
                name: 'campaigns.detail',
                params: { ulid: wave.relationships.campaign.data.id },
              }"
              data-test="wave-campaign-link"
            >
              {{ wave.relationships.campaign.data.attributes.name }}
            </router-link>
            <span v-else class="text-medium-emphasis">{{ t('app.waves.detail.noCampaign') }}</span>
          </dd>
        </div>
        <div class="agency-field" data-test="wave-field-created-by">
          <dt class="agency-field__label">{{ t('app.waves.detail.createdBy') }}</dt>
          <dd class="agency-field__value">
            {{ wave.relationships.created_by.data.attributes.name }}
          </dd>
        </div>
        <div class="agency-field" data-test="wave-field-updated">
          <dt class="agency-field__label">{{ t('app.waves.table.updated') }}</dt>
          <dd class="agency-field__value">
            {{ formatDateTime(wave.attributes.updated_at, locale, { dateStyle: 'medium' }) }}
          </dd>
        </div>
      </dl>
    </section>

    <NewWaveDialog
      v-if="wave && agencyStore.currentAgencyId"
      v-model="editOpen"
      :agency-id="agencyStore.currentAgencyId"
      :brand-id="brandUlid"
      :brand-name="wave.relationships.brand.data.attributes.name"
      :wave="wave"
      @saved="(w: WaveResource) => (wave = w)"
    />
  </div>
</template>
