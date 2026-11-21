<script setup lang="ts">
/**
 * PL-2.1 — the Strategy & Scripts workspace of a wave: header, six-step stepper, and the working step (1 USPs,
 * 2 Personas, 3 Messaging matrix (PL-2.2) or 4 Concepts (PL-2.3); all four routes render this page). Behind two flags: `features.strategy` is true only while waves AND
 * strategy are on. With waves off the page goes to the brand; with waves on and strategy off it goes to the wave
 * page, which is then exactly as PL-1 left it. Nothing is requested before that check.
 *
 * No design frame exists for the page shell or the not-built steps; it is built from the agency page header,
 * card and field styles.
 */
import type { StrategySummaryResource, WaveResource } from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import AgencyPageHeader from '@/modules/agency/components/AgencyPageHeader.vue'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { wavesApi } from '@/modules/waves/api/waves.api'
import { strategyApi } from '../api/strategy.api'
import ConceptsStep from '../components/ConceptsStep.vue'
import MatrixStep from '../components/MatrixStep.vue'
import PersonasStep from '../components/PersonasStep.vue'
import StrategyStepper from '../components/StrategyStepper.vue'
import UspsStep from '../components/UspsStep.vue'
import { useStrategyContext } from '../composables/useStrategyContext'
import type { StepKey } from '../constants'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const { brandId, waveId, scope } = useStrategyContext()

const features = computed(() => authStore.user?.attributes.features)
const allowed = ref(false)
const wave = ref<WaveResource | null>(null)
const summary = ref<StrategySummaryResource | null>(null)
const loading = ref(true)
const failed = ref(false)

const step = computed<StepKey>(() =>
  route.name === 'strategy.personas'
    ? 'personas'
    : route.name === 'strategy.matrix'
      ? 'matrix'
      : route.name === 'strategy.concepts'
        ? 'concepts'
        : 'usps',
)
const markets = computed(() => summary.value?.attributes.markets.map((m) => m.market) ?? [])

async function load(): Promise<void> {
  const s = scope.value
  if (s === null) return
  loading.value = true
  failed.value = false
  try {
    const [w, sum] = await Promise.all([
      wavesApi.show(s.agencyId, s.brandId, s.waveId),
      strategyApi.summary(s),
    ])
    wave.value = w.data
    summary.value = sum.data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function refreshSummary(): Promise<void> {
  const s = scope.value
  if (s === null) return
  try {
    summary.value = (await strategyApi.summary(s)).data
  } catch {
    // The step's own list is the truth on screen; a failed refresh only leaves the stepper counts one step behind.
  }
}

function onSummary(next: StrategySummaryResource): void {
  summary.value = next
}

onMounted(async () => {
  if (features.value?.waves !== true) {
    await router.replace({ name: 'brands.detail', params: { ulid: brandId } })
    return
  }
  if (features.value.strategy !== true) {
    await router.replace({ name: 'waves.detail', params: { ulid: brandId, waveUlid: waveId } })
    return
  }
  allowed.value = true
  await load()
})
</script>

<template>
  <div v-if="allowed" class="agency-page agency-page--snug strategy-page" data-test="strategy-page">
    <AgencyPageHeader
      :title="t('app.strategy.title')"
      title-test="strategy-heading"
      subtitle-test="strategy-subtitle"
    >
      <template #leading>
        <v-btn
          icon="mdi-arrow-left"
          variant="text"
          :to="{ name: 'waves.detail', params: { ulid: brandId, waveUlid: waveId } }"
          class="agency-icon-square"
          :aria-label="t('app.strategy.back')"
          data-test="strategy-back"
        />
      </template>
      <template v-if="wave" #subtitle>
        {{ wave.relationships.brand.data.attributes.name }} · {{ wave.attributes.name }}
      </template>
    </AgencyPageHeader>

    <v-skeleton-loader v-if="loading" type="article" data-test="strategy-skeleton" />
    <v-alert
      v-else-if="failed || !wave || !summary"
      type="error"
      variant="tonal"
      data-test="strategy-error"
    >
      {{ t('app.strategy.errors.workspaceGone') }}
    </v-alert>
    <template v-else>
      <StrategyStepper :summary="summary" :brand-id="brandId" :wave-id="waveId" :active="step" />
      <UspsStep
        v-if="step === 'usps'"
        :key="'usps'"
        :summary="summary"
        @summary="onSummary"
        @changed="refreshSummary"
      />
      <MatrixStep
        v-else-if="step === 'matrix'"
        :key="'matrix'"
        :summary="summary"
        :markets="markets"
        @changed="refreshSummary"
      />
      <ConceptsStep
        v-else-if="step === 'concepts'"
        :key="'concepts'"
        :summary="summary"
        :markets="markets"
        :brand-name="wave.relationships.brand.data.attributes.name"
        @changed="refreshSummary"
      />
      <PersonasStep
        v-else
        :key="'personas'"
        :summary="summary"
        :markets="markets"
        @changed="refreshSummary"
      />
    </template>
  </div>
</template>

<style scoped>
.strategy-page {
  display: flex;
  flex-direction: column;
  gap: 20px;
}
</style>
