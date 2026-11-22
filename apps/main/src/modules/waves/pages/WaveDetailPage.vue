<script setup lang="ts">
/**
 * PL-1.1 — the wave page: header (name, status pill, Edit for an admin or manager), a fields card (go-live
 * date, markets, linked campaign, created by, updated), and a way back to the brand.
 *
 * No design frame exists for this page; it is built from the agency page header, card and field styles.
 * With `features.waves` off the page redirects to the brand; an unknown wave (or one in another agency) is
 * the not-found state, since the server answers 404 for both.
 */
import { formatDateTime } from '@catalyst/api-client'
import type { PersonaCodesResult, WaveResource, WaveTrackerResource } from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import AgencyPageHeader from '@/modules/agency/components/AgencyPageHeader.vue'
import AgencyStatusPill from '@/modules/agency/components/AgencyStatusPill.vue'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { wavesApi } from '../api/waves.api'
import MarketCountriesInfo from '../components/MarketCountriesInfo.vue'
import NewWaveDialog from '../components/NewWaveDialog.vue'
import WaveProgressSegments from '../components/WaveProgressSegments.vue'
import WaveTrackerSection from '../components/WaveTrackerSection.vue'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const agencyStore = useAgencyStore()
const authStore = useAuthStore()

const brandUlid = route.params.ulid as string
const waveUlid = route.params.waveUlid as string

const wavesOn = computed(() => authStore.user?.attributes.features?.waves === true)
// PL-2.1: true only while waves AND strategy are on (the server computes it). The entry button is drawn from a list
// of zero or one item, not a `v-if`: with the flag off a `v-if` leaves a comment node in the DOM, and the wave page
// must be byte-identical to PL-1 (W-OFF).
const strategyOn = computed(() => authStore.user?.attributes.features?.strategy === true)
const strategyEntry = computed(() => (strategyOn.value ? ['strategy'] : []))
const canManage = computed(
  () => agencyStore.currentRole === 'agency_admin' || agencyStore.currentRole === 'agency_manager',
)

const wave = ref<WaveResource | null>(null)
const loading = ref(true)
const failed = ref(false)
const editOpen = ref(false)
/**
 * A1: what the last edit did to persona codes, kept on the page until closed. The alert is drawn with a v-for over
 * zero or one entries, not a v-if, so with nothing to say no node at all (not even a comment) is left in the page.
 */
const personaCodes = ref<PersonaCodesResult | null>(null)
const tracker = ref<WaveTrackerResource | null>(null)

const health = computed(() => tracker.value?.attributes.health ?? null)
const progress = computed(
  () => tracker.value?.attributes.progress ?? wave.value?.attributes.progress ?? null,
)

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
  <div class="agency-page agency-page--snug wave-detail" data-test="wave-detail-page">
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
        <AgencyStatusPill
          v-if="health"
          :tone="health.status === 'at_risk' ? 'danger' : 'success'"
          :label="
            health.status === 'at_risk'
              ? t(
                  'app.waves.tracker.health.atRisk',
                  { count: health.delayed_streams },
                  health.delayed_streams,
                )
              : t('app.waves.tracker.health.onTrack')
          "
          data-test="wave-health"
        />
      </template>
      <template v-if="wave && (canManage || strategyOn)" #actions>
        <v-btn
          v-for="entry in strategyEntry"
          :key="entry"
          variant="flat"
          color="primary"
          prepend-icon="mdi-lightbulb-on-outline"
          :to="{ name: 'strategy.usps', params: { ulid: brandUlid, waveUlid: waveUlid } }"
          data-test="wave-strategy-btn"
        >
          {{ t('app.strategy.entry') }}
        </v-btn>
        <v-btn
          v-if="canManage"
          variant="outlined"
          data-test="wave-edit-btn"
          @click="editOpen = true"
        >
          {{ t('app.brands.actions.edit') }}
        </v-btn>
      </template>
    </AgencyPageHeader>

    <v-alert
      v-for="codes in personaCodes ? [personaCodes] : []"
      :key="`${codes.renamed}-${codes.skipped}-${codes.kept_custom}`"
      type="success"
      variant="tonal"
      closable
      class="mb-4"
      data-test="persona-codes-result"
      @click:close="personaCodes = null"
    >
      {{
        t('app.waves.markets.personaCodesResult', {
          renamed: codes.renamed,
          skipped: codes.skipped,
          kept: codes.kept_custom,
        })
      }}
    </v-alert>

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
          <dd class="agency-field__value"><WaveProgressSegments :progress="progress" /></dd>
        </div>
      </dl>

      <hr class="agency-card__rule" />

      <div class="agency-field" data-test="wave-field-markets">
        <h3 class="agency-field__label">{{ t('app.waves.dialog.markets') }}</h3>
        <div class="d-flex flex-wrap ga-2">
          <span
            v-for="m in wave.attributes.markets"
            :key="m.id"
            class="wave-market"
            :data-test="`wave-market-${m.code}`"
          >
            <v-chip size="small" class="wave-market__chip">
              <span class="wave-market__name">{{ m.name }}</span>
            </v-chip>
            <MarketCountriesInfo :id="m.code" :name="m.name" :countries="m.countries" />
          </span>
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
            <div
              v-if="wave.attributes.link_locked === true"
              class="text-caption text-medium-emphasis mt-1"
              data-test="wave-link-locked"
            >
              {{ t('app.waves.detail.linkLocked') }}
            </div>
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

    <WaveTrackerSection
      v-if="wave && agencyStore.currentAgencyId"
      :agency-id="agencyStore.currentAgencyId"
      :brand-id="brandUlid"
      :wave-id="waveUlid"
      :can-manage="canManage"
      :me-ulid="authStore.user?.id ?? null"
      @tracker="(value: WaveTrackerResource | null) => (tracker = value)"
    />

    <NewWaveDialog
      v-if="wave && agencyStore.currentAgencyId"
      v-model="editOpen"
      :agency-id="agencyStore.currentAgencyId"
      :brand-id="brandUlid"
      :brand-name="wave.relationships.brand.data.attributes.name"
      :wave="wave"
      :offer-persona-codes="strategyOn"
      @saved="
        (w: WaveResource, codes?: PersonaCodesResult) => {
          wave = w
          personaCodes = codes ?? null
        }
      "
      @refreshed="(w: WaveResource) => (wave = w)"
    />
  </div>
</template>

<style scoped>
/*
 * No design exists below 1280 px (plan 4.3), and the frozen agency theme has no narrow rule for these two blocks. On
 * a phone the title, its two pills and Edit cannot share one line, and three field columns leave the Progress column
 * running out of the card. Both are fixed here, in this page's own style, so the frozen theme is untouched.
 */
@media (max-width: 767px) {
  .wave-detail :deep(.agency-page-header) {
    flex-wrap: wrap;
    align-items: flex-start;
  }

  .wave-detail :deep(.agency-page-header__heading) {
    flex-wrap: wrap;
  }

  .wave-detail :deep(.agency-fields) {
    grid-template-columns: minmax(0, 1fr);
  }
}

.wave-market {
  display: inline-flex;
  align-items: center;
  max-width: 100%;
}
.wave-market__chip {
  max-width: 260px;
}
.wave-market__name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
