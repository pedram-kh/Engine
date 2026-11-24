<script setup lang="ts">
/**
 * PL-2.1 — the Strategy & Scripts workspace of a wave: header, six-step stepper, and the working step (1 USPs,
 * 2 Personas, 3 Messaging matrix (PL-2.2), 4 Concepts (PL-2.3), 5 Creators (PL-3.1) or 6 Scripts (PL-3.2a); all routes render this page). Behind two flags: `features.strategy` is true only while waves AND
 * strategy are on. With waves off the page goes to the brand; with waves on and strategy off it goes to the wave
 * page, which is then exactly as PL-1 left it. Nothing is requested before that check.
 *
 * No design frame exists for the page shell or the not-built steps; it is built from the agency page header,
 * card and field styles.
 */
import type {
  ScriptsOverview,
  StrategyCompletionResponse,
  StrategySummaryResource,
  WaveCreatorsData,
  WaveResource,
} from '@catalyst/api-client'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import AgencyPageHeader from '@/modules/agency/components/AgencyPageHeader.vue'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { wavesApi } from '@/modules/waves/api/waves.api'
import { strategyApi, type NoteStep } from '../api/strategy.api'
import ConceptsStep from '../components/ConceptsStep.vue'
import CreatorsStep from '../components/CreatorsStep.vue'
import MatrixStep from '../components/MatrixStep.vue'
import NotesPanel from '../components/NotesPanel.vue'
import NotesSheet from '../components/NotesSheet.vue'
import PersonasStep from '../components/PersonasStep.vue'
import ScriptsStep from '../components/ScriptsStep.vue'
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

/** PL-3.1: step 5 exists only while `features.creators_scripts` is on; the address is refused to the wave otherwise. */
const creatorsOn = computed(() => features.value?.creators_scripts === true)
const onCreators = computed(() => route.name === 'strategy.creators')
const creatorsState = ref<WaveCreatorsData['state'] | null>(null)
/** PL-3.2a: step 6 shares the flag and the page; its stepper state and counts come from the scripts overview. */
const onScripts = computed(() => route.name === 'strategy.scripts')
const scriptsOverview = ref<ScriptsOverview | null>(null)

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
  () =>
    !onCreators.value &&
    !onScripts.value &&
    step.value !== 'matrix' &&
    (step.value === 'usps' || market.value !== null),
)
const sheetOpen = ref(false)
// The panel's expanded view (a large side sheet) and the one draft shared by the panel, the expanded view and the matrix
// sheet; the draft belongs to the step and market it was typed on.
const expanded = ref(false)
const noteDraft = ref('')
const panelRef = ref<{ focusExpand: () => void } | null>(null)
const notesOpenBtn = ref<{ $el: HTMLElement } | null>(null)
watch([step, market], () => {
  noteDraft.value = ''
  expanded.value = false
  sheetOpen.value = false
})
// Focus goes back to the control that opened the larger view.
watch(expanded, async (open, was) => {
  if (was && !open) {
    await nextTick()
    panelRef.value?.focusExpand()
  }
})
watch(sheetOpen, async (open, was) => {
  if (was && !open) {
    await nextTick()
    notesOpenBtn.value?.$el.focus()
  }
})

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
    await refreshCreatorsState()
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

/** PL-3.1: the stepper's step 5 reads its state from the creators payload (flag on only; nothing is asked otherwise). */
async function refreshCreatorsState(): Promise<void> {
  const s = scope.value
  if (s === null || !creatorsOn.value) return
  await Promise.all([
    strategyApi.creators(s).then(
      (r) => (creatorsState.value = r.data.state),
      () => (creatorsState.value = null),
    ),
    strategyApi.scripts(s, null).then(
      (r) => (scriptsOverview.value = r.data),
      () => (scriptsOverview.value = null),
    ),
  ])
}

async function refreshSummary(): Promise<void> {
  const s = scope.value
  if (s === null) return
  try {
    summary.value = (await strategyApi.summary(s)).data
    await refreshCreatorsState()
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
  if ((onCreators.value || onScripts.value) && features.value.creators_scripts !== true) {
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
      <StrategyStepper
        :summary="summary"
        :brand-id="brandId"
        :wave-id="waveId"
        :active="onScripts ? 'scripts' : onCreators ? 'creators' : step"
        :creators-on="creatorsOn"
        :creators="creatorsState"
        :scripts="scriptsOverview"
      />
      <ScriptsStep
        v-for="n in onScripts ? [1] : []"
        :key="`scripts-${n}`"
        :summary="summary"
        @changed="refreshSummary"
      />
      <CreatorsStep
        v-for="n in onCreators ? [1] : []"
        :key="`creators-${n}`"
        :summary="summary"
        :wave="wave"
        @changed="refreshSummary"
        @wave="(w) => (wave = w)"
      />
      <StepCompletionBar
        v-if="!onCreators && !onScripts"
        :summary="summary"
        :step="step"
        :market="market"
        :market-name="marketName"
        @completed="onCompleted"
      >
        <template v-if="step === 'matrix' && market !== null" #extra>
          <v-btn
            ref="notesOpenBtn"
            variant="outlined"
            size="small"
            prepend-icon="mdi-note-text-outline"
            data-test="notes-open"
            @click="sheetOpen = true"
            >{{ t('app.strategy.notes.open', { count: noteCount }) }}</v-btn
          >
        </template>
      </StepCompletionBar>
      <div
        v-if="!onCreators && !onScripts"
        class="strategy-grid"
        :class="{ 'strategy-grid--notes': panelOn }"
      >
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
            ref="panelRef"
            :key="`${step}:${market ?? ''}`"
            v-model:draft="noteDraft"
            :step="step"
            :market="market"
            :expanded="expanded"
            @expand="expanded = true"
            @changed="refreshSummary"
          />
        </div>
      </div>
      <NotesSheet
        v-if="panelOn && expanded"
        v-model="expanded"
        v-model:draft="noteDraft"
        from-panel
        :step="step as NoteStep"
        :market="market"
        :market-name="step === 'usps' ? '' : marketName"
        @changed="refreshSummary"
      />
      <NotesSheet
        v-if="step === 'matrix' && market !== null"
        v-model="sheetOpen"
        v-model:draft="noteDraft"
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
/* The notes cell is as tall as the step so the panel inside it can stay in view (sticky) while the cards scroll. */
.strategy-grid__notes {
  align-self: stretch;
}
@media (max-width: 1099px) {
  .strategy-grid--notes {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
