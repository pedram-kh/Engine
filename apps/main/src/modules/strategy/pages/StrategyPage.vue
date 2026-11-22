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
import type {
  StrategyCompletionResponse,
  StrategySummaryResource,
  WaveResource,
} from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import AgencyPageHeader from '@/modules/agency/components/AgencyPageHeader.vue'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { wavesApi } from '@/modules/waves/api/waves.api'
import { strategyApi } from '../api/strategy.api'
import ConceptsStep from '../components/ConceptsStep.vue'
import MatrixStep from '../components/MatrixStep.vue'
import NotesPanel from '../components/NotesPanel.vue'
import NotesSheet from '../components/NotesSheet.vue'
import PersonasStep from '../components/PersonasStep.vue'
import StepCompletionBar from '../components/StepCompletionBar.vue'
import StrategyStepper from '../components/StrategyStepper.vue'
import UspsStep from '../components/UspsStep.vue'
import { useStrategyContext } from '../composables/useStrategyContext'
import type { StepName } from '../completionLogic'
import { marketsOf } from '../useMarkets'

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

const step = computed<StepName>(() =>
  route.name === 'strategy.personas'
    ? 'personas'
    : route.name === 'strategy.matrix'
      ? 'matrix'
      : route.name === 'strategy.concepts'
        ? 'concepts'
        : 'usps',
)
/**
 * The market the step is on: the same rule the steps use (`?market=` when it is one of the wave's markets, else the
 * first). USPs have none. The completion bar and the notes follow it.
 */
const market = computed<string | null>(() => {
  if (step.value === 'usps' || summary.value === null) return null
  const ids = marketsOf(summary.value).map((m) => m.id)
  const q = route.query.market
  return typeof q === 'string' && ids.includes(q) ? q : (ids[0] ?? null)
})
const marketName = computed(
  () => summary.value?.attributes.markets.find((m) => m.market === market.value)?.name ?? '',
)
const noteCount = computed(() => {
  const n = summary.value?.attributes.note_counts
  if (!n) return 0
  if (step.value === 'usps') return n.usps
  return market.value === null ? 0 : (n[step.value][market.value] ?? 0)
})
/** USPs, Personas and Concepts carry the notes panel beside the step; the matrix opens them in a side sheet. */
const panelOn = computed(
  () => step.value !== 'matrix' && (step.value === 'usps' || market.value !== null),
)
const sheetOpen = ref(false)

function onCompleted(data: StrategyCompletionResponse['data']): void {
  if (summary.value === null) return
  summary.value = {
    ...summary.value,
    attributes: {
      ...summary.value.attributes,
      steps: data.steps,
      landing: data.landing,
      note_counts: data.note_counts,
    },
  }
}

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
  // An answer without the PL-2.5 blocks must never blank them: keep what the screen has.
  const prev = summary.value?.attributes
  const got: Partial<StrategySummaryResource['attributes']> = next.attributes
  summary.value =
    prev === undefined
      ? next
      : {
          ...next,
          attributes: {
            ...next.attributes,
            steps: got.steps ?? prev.steps,
            landing: got.landing ?? prev.landing,
            note_counts: got.note_counts ?? prev.note_counts,
          },
        }
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
      <StepCompletionBar
        :summary="summary"
        :step="step"
        :market="market"
        :market-name="marketName"
        @completed="onCompleted"
      >
        <template v-if="step === 'matrix' && market !== null" #extra>
          <v-btn
            variant="outlined"
            size="small"
            prepend-icon="mdi-note-text-outline"
            data-test="notes-open"
            @click="sheetOpen = true"
            >{{ t('app.strategy.notes.open', { count: noteCount }) }}</v-btn
          >
        </template>
      </StepCompletionBar>
      <div class="strategy-grid" :class="{ 'strategy-grid--notes': panelOn }">
        <div class="strategy-grid__main">
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
            @changed="refreshSummary"
          />
          <ConceptsStep
            v-else-if="step === 'concepts'"
            :key="'concepts'"
            :summary="summary"
            :brand-name="wave.relationships.brand.data.attributes.name"
            @changed="refreshSummary"
          />
          <PersonasStep v-else :key="'personas'" :summary="summary" @changed="refreshSummary" />
        </div>
        <div v-if="panelOn" class="strategy-grid__notes">
          <NotesPanel
            :key="`${step}:${market ?? ''}`"
            :step="step"
            :market="market"
            @changed="refreshSummary"
          />
        </div>
      </div>
      <NotesSheet
        v-if="step === 'matrix' && market !== null"
        v-model="sheetOpen"
        step="matrix"
        :market="market"
        :market-name="marketName"
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
/* PL-2.5 (frame 13:428): the step on the left, the 300 px notes column on the right with a 24 px gap. Under 1100 px the
   column goes below the step. Without the panel (the matrix) the step has the whole width. */
.strategy-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 24px;
  align-items: start;
}
.strategy-grid--notes {
  grid-template-columns: minmax(0, 1fr) 300px;
}
.strategy-grid__main,
.strategy-grid__notes {
  min-width: 0;
}
@media (max-width: 1099px) {
  .strategy-grid--notes {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
