<script setup lang="ts">
/**
 * PL-2.3 — step 4, Concepts: one tab per market (the market is in the address, `?market=SK`), a banner with the counts and
 * "Record client's decision", the under-three warning, the card grid, the "Add concept" slot and the 30-per-market ceiling.
 *
 * Personas and USPs of the wave are read once (the dialog needs them); the concepts of the market are read on open and
 * after every change, without a loading state on a reload, so the grid does not flicker and an open dialog or menu
 * stays where it is (cards are keyed by id). The page's own list is what the server holds.
 *
 * One confirmation serves the whole step (A1): before the first change that sends an Approved concept back to Awaiting
 * client (editing it, or adding or removing an image or a link) the person is told the client's approval will be cleared.
 * Cancel changes nothing.
 */
import type {
  ConceptMarketCounts,
  FunnelStage,
  StrategySummaryResource,
  WaveConceptResource,
  WavePersonaResource,
  WaveUspResource,
} from '@catalyst/api-client'
import { labelForCountryCode } from '@catalyst/api-client'
import { CEmptyState } from '@catalyst/ui'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import { strategyApi } from '../api/strategy.api'
import { bannerState, marketCounts, nextFunnelNote, showUnderMinimum } from '../conceptsLogic'
import { provideConfirmReset } from '../composables/confirmReset'
import { useStrategyContext } from '../composables/useStrategyContext'
import { isGone, strategyErrorMessage } from '../strategyErrors'
import ConceptCard from './ConceptCard.vue'
import ConceptDecisionDialog from './ConceptDecisionDialog.vue'
import ConceptDialog from './ConceptDialog.vue'
import StrategyConfirmDialog from './StrategyConfirmDialog.vue'

const props = defineProps<{
  summary: StrategySummaryResource
  markets: string[]
  brandName: string
}>()
const emit = defineEmits<{ changed: [] }>()

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { brandId, waveId, scope, canDecide } = useStrategyContext()

const limits = computed(() => props.summary.attributes.limits)
const personas = ref<WavePersonaResource[]>([])
const usps = ref<WaveUspResource[]>([])
const concepts = ref<WaveConceptResource[]>([])
const listCounts = ref<ConceptMarketCounts | null>(null)
const loading = ref(true)
const failed = ref(false)
const pageError = ref<string | null>(null)
const busyIds = ref<string[]>([])
/** Only the newest read of a market may fill the grid. */
let ticket = 0

const market = computed(() => {
  const q = route.query.market
  const wanted = typeof q === 'string' ? q : null
  return wanted !== null && props.markets.includes(wanted) ? wanted : (props.markets[0] ?? '')
})
const marketName = computed(() => (market.value === '' ? '' : labelForCountryCode(market.value)))
const marketPersonas = computed(() =>
  personas.value.filter((p) => p.attributes.market === market.value),
)
/** The counts of the market: the list's own when loaded, else the summary's (the tab numbers). */
const counts = computed(
  () => listCounts.value ?? marketCounts(props.summary.attributes.concepts, market.value),
)
const total = computed(() => concepts.value.length)
const atMax = computed(() => total.value >= limits.value.concept_max_per_market)
const banner = computed(() => bannerState(counts.value))
const underMinimum = computed(() =>
  showUnderMinimum(counts.value, limits.value.concept_min_recommended),
)
const noPersonas = computed(() => marketPersonas.value.length === 0)
/**
 * `?persona=<id>` (from the "see the concepts" link in the refused persona delete) shows only that persona's concepts,
 * with a line saying so and "Show all". The banner and the tab numbers stay the market's. An id that is not a persona of
 * this market is ignored.
 */
const personaFilter = computed(() => {
  const q = route.query.persona
  return typeof q === 'string' ? (marketPersonas.value.find((p) => p.id === q) ?? null) : null
})
const visibleConcepts = computed(() =>
  personaFilter.value === null
    ? concepts.value
    : concepts.value.filter((c) => c.attributes.persona.id === personaFilter.value?.id),
)
function showAllPersonas(): void {
  const rest = { ...route.query }
  delete rest.persona
  void router.replace({ query: rest })
}

function tabCount(code: string): number {
  return marketCounts(props.summary.attributes.concepts, code)?.total ?? 0
}

async function loadWaveData(): Promise<void> {
  const s = scope.value
  if (s === null) return
  try {
    const [p, u] = await Promise.all([strategyApi.listPersonas(s), strategyApi.listUsps(s)])
    personas.value = p.data
    usps.value = u.data
  } catch {
    failed.value = true
  }
}

async function loadConcepts(first = false): Promise<void> {
  const s = scope.value
  if (s === null || market.value === '') {
    loading.value = false
    return
  }
  const mine = ++ticket
  const m = market.value
  try {
    const res = await strategyApi.listConcepts(s, m)
    if (mine !== ticket) return
    concepts.value = res.data
    listCounts.value = res.meta.market
    failed.value = false
  } catch {
    if (mine === ticket && first) failed.value = true
  } finally {
    if (mine === ticket) loading.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadWaveData(), loadConcepts(true)])
})

watch(market, () => {
  // A market switch is a real load: the old market's cards must not stay up under the new tab.
  concepts.value = []
  listCounts.value = null
  loading.value = true
  pageError.value = null
  void loadConcepts(true)
})

function selectMarket(code: string): void {
  if (code === market.value) return
  void router.replace({ query: { ...route.query, market: code } })
}

async function reload(): Promise<void> {
  await loadConcepts()
  emit('changed')
}

// A1 — the reset confirmation ------------------------------------------------------------------------------
const resetOpen = ref(false)
let resetResolve: ((ok: boolean) => void) | null = null
function settleReset(ok: boolean): void {
  resetOpen.value = false
  const r = resetResolve
  resetResolve = null
  r?.(ok)
}
function confirmFor(c: WaveConceptResource): Promise<boolean> {
  if (c.attributes.status !== 'approved') return Promise.resolve(true)
  settleReset(false)
  return new Promise<boolean>((resolve) => {
    resetResolve = resolve
    resetOpen.value = true
  })
}
provideConfirmReset(confirmFor)

// Dialogs --------------------------------------------------------------------------------------------------
const dialogOpen = ref(false)
const editing = ref<WaveConceptResource | null>(null)
const decisionOpen = ref(false)
const decisionFor = ref<WaveConceptResource | null>(null)
const deleteOpen = ref(false)
const deleting = ref<WaveConceptResource | null>(null)
const deleteBusy = ref(false)
const deleteError = ref<string | null>(null)

function openAdd(): void {
  editing.value = null
  dialogOpen.value = true
}
function openEdit(c: WaveConceptResource): void {
  editing.value = c
  dialogOpen.value = true
}
function openDecision(c: WaveConceptResource | null): void {
  decisionFor.value = c
  decisionOpen.value = true
}
function askDelete(c: WaveConceptResource): void {
  deleting.value = c
  deleteError.value = null
  deleteOpen.value = true
}

async function withBusy(id: string, work: () => Promise<void>): Promise<void> {
  busyIds.value = [...busyIds.value, id]
  pageError.value = null
  try {
    await work()
  } catch (err) {
    pageError.value = strategyErrorMessage(err, t)
    if (isGone(err)) await reload()
  } finally {
    busyIds.value = busyIds.value.filter((x) => x !== id)
  }
}

async function duplicate(c: WaveConceptResource): Promise<void> {
  const s = scope.value
  if (s === null) return
  await withBusy(c.id, async () => {
    await strategyApi.duplicateConcept(s, c.id)
    await reload()
  })
}

async function setStage(c: WaveConceptResource, stage: FunnelStage | null): Promise<void> {
  const s = scope.value
  if (s === null) return
  if (!(await confirmFor(c))) return
  await withBusy(c.id, async () => {
    await strategyApi.updateConcept(s, c.id, {
      funnel_stage: stage,
      funnel_note: nextFunnelNote(c.attributes.funnel_stage, c.attributes.funnel_note, stage),
    })
    await reload()
  })
}
async function confirmDelete(): Promise<void> {
  const s = scope.value
  const c = deleting.value
  if (s === null || c === null) return
  deleteBusy.value = true
  deleteError.value = null
  try {
    await strategyApi.removeConcept(s, c.id)
    deleteOpen.value = false
    await reload()
  } catch (err) {
    if (isGone(err)) {
      deleteOpen.value = false
      await reload()
    } else {
      deleteError.value = strategyErrorMessage(err, t)
    }
  } finally {
    deleteBusy.value = false
  }
}

const deleteBody = computed(() => {
  const status = deleting.value?.attributes.status
  const base = t('app.strategy.concepts.delete.body')
  return status === 'awaiting' || status === undefined
    ? base
    : `${base} ${t('app.strategy.concepts.delete.bodyDecided')}`
})
</script>

<template>
  <section class="concepts-step" data-test="concepts-step">
    <div class="market-bar">
      <span class="market-bar__label" aria-hidden="true">{{
        t('app.strategy.persona.marketLabel')
      }}</span>
      <div
        role="tablist"
        class="market-tabs"
        :aria-label="t('app.strategy.persona.markets')"
        data-test="market-tabs"
      >
        <button
          v-for="code in markets"
          :key="code"
          type="button"
          role="tab"
          class="market-tab"
          :class="{ 'market-tab--active': code === market }"
          :aria-selected="code === market"
          :data-test="`market-tab-${code}`"
          @click="selectMarket(code)"
        >
          <span class="market-tab__code">{{ code }}</span>
          <span class="market-tab__name">{{ labelForCountryCode(code) }}</span>
          <span class="market-tab__count" :data-test="`market-count-${code}`">{{
            tabCount(code)
          }}</span>
        </button>
      </div>
    </div>

    <div class="step-head">
      <div>
        <h2 class="step-head__title" data-test="concepts-heading">
          {{ t('app.strategy.concepts.title', { market: marketName }) }}
        </h2>
        <p class="step-head__sub">
          {{ t('app.strategy.concepts.subtitle', { max: limits.concept_max_per_market }) }}
        </p>
      </div>
      <div class="step-head__actions">
        <v-btn
          variant="outlined"
          size="small"
          class="step-btn"
          prepend-icon="mdi-plus"
          :disabled="atMax || loading || failed || noPersonas || market === ''"
          data-test="concept-add"
          @click="openAdd"
        >
          {{ t('app.strategy.concepts.add') }}
        </v-btn>
      </div>
    </div>

    <div
      v-if="!loading && !failed && banner !== 'none' && counts"
      class="concepts-banner"
      :class="`concepts-banner--${banner}`"
      role="status"
      data-test="concepts-banner"
      :data-state="banner"
    >
      <v-icon
        :icon="banner === 'green' ? 'mdi-check-circle-outline' : 'mdi-progress-clock'"
        size="18"
        aria-hidden="true"
      />
      <span class="concepts-banner__text" data-test="concepts-banner-text">
        <template v-if="banner === 'green'">
          {{ t('app.strategy.concepts.banner.allApproved', { total: counts.total }, counts.total) }}
        </template>
        <template v-else>
          {{
            t('app.strategy.concepts.banner.counts', {
              approved: counts.approved,
              total: counts.total,
              awaiting: counts.awaiting,
              changes: counts.changes_requested,
            })
          }}
        </template>
      </span>
      <v-btn
        v-if="canDecide"
        variant="outlined"
        size="small"
        class="step-btn"
        data-test="concepts-record-decision"
        @click="openDecision(null)"
      >
        {{ t('app.strategy.concepts.banner.record') }}
      </v-btn>
    </div>

    <v-alert
      v-if="!loading && !failed && underMinimum"
      type="warning"
      variant="tonal"
      density="compact"
      data-test="concepts-under-minimum"
    >
      {{
        t('app.strategy.concepts.underMinimum', {
          min: limits.concept_min_recommended,
          market: marketName,
          count: total,
        })
      }}
    </v-alert>
    <v-alert
      v-if="pageError"
      type="error"
      variant="tonal"
      density="compact"
      data-test="concepts-error"
    >
      {{ pageError }}
    </v-alert>

    <v-skeleton-loader v-if="loading" type="card, card" data-test="concepts-skeleton" />
    <v-alert v-else-if="failed" type="error" variant="tonal" data-test="concepts-load-error">
      {{ t('app.strategy.errors.loadFailed') }}
    </v-alert>
    <template v-else-if="scope">
      <CEmptyState
        v-if="total === 0"
        class="agency-card"
        data-test="concepts-empty"
        title-tag="h3"
        :title="
          noPersonas
            ? t('app.strategy.concepts.emptyState.noPersonasTitle', { market: marketName })
            : t('app.strategy.concepts.emptyState.title', { market: marketName })
        "
        :body="
          noPersonas
            ? t('app.strategy.concepts.emptyState.noPersonasBody')
            : t('app.strategy.concepts.emptyState.body', { min: limits.concept_min_recommended })
        "
      >
        <template #icon>
          <v-icon icon="mdi-lightbulb-on-outline" size="48" aria-hidden="true" />
        </template>
        <template #action>
          <v-btn
            v-if="noPersonas"
            color="primary"
            variant="flat"
            :to="{
              name: 'strategy.personas',
              params: { ulid: brandId, waveUlid: waveId },
              query: { market },
            }"
            data-test="concepts-empty-personas"
          >
            {{ t('app.strategy.concepts.emptyState.toPersonas') }}
          </v-btn>
          <v-btn
            v-else
            color="primary"
            variant="flat"
            prepend-icon="mdi-plus"
            data-test="concepts-empty-cta"
            @click="openAdd"
          >
            {{ t('app.strategy.concepts.add') }}
          </v-btn>
        </template>
      </CEmptyState>
      <p
        v-if="total > 0 && personaFilter"
        class="concepts-filter"
        data-test="concepts-persona-filter"
      >
        {{
          t('app.strategy.concepts.personaFilter', {
            persona: `${personaFilter.attributes.code} · ${personaFilter.attributes.title}`,
          })
        }}
        <v-btn
          variant="text"
          size="small"
          class="px-1"
          data-test="concepts-persona-filter-clear"
          @click="showAllPersonas"
        >
          {{ t('app.strategy.concepts.showAll') }}
        </v-btn>
      </p>
      <div v-if="total > 0" class="concepts-grid" data-test="concepts-grid">
        <ConceptCard
          v-for="c in visibleConcepts"
          :key="c.id"
          :concept="c"
          :scope="scope"
          :limits="limits"
          :brand-name="brandName"
          :can-decide="canDecide"
          :busy="busyIds.includes(c.id)"
          @edit="openEdit"
          @decide="openDecision"
          @duplicate="duplicate"
          @remove="askDelete"
          @stage="setStage"
          @changed="reload"
        />
        <button
          type="button"
          class="concept-tile"
          :disabled="atMax || noPersonas"
          data-test="concept-tile"
          @click="openAdd"
        >
          <span class="concept-tile__icon"
            ><v-icon icon="mdi-plus" size="22" aria-hidden="true"
          /></span>
          <span class="concept-tile__title">{{ t('app.strategy.concepts.add') }}</span>
          <span class="concept-tile__count" data-test="concept-tile-text">
            {{
              atMax
                ? t('app.strategy.concepts.tileMax', {
                    count: total,
                    max: limits.concept_max_per_market,
                  })
                : t('app.strategy.concepts.tile', {
                    count: total,
                    max: limits.concept_max_per_market,
                  })
            }}
          </span>
        </button>
      </div>
    </template>

    <ConceptDialog
      v-if="scope"
      v-model="dialogOpen"
      :scope="scope"
      :market="market"
      :concept="editing"
      :personas="marketPersonas"
      :usps="usps"
      :limits="limits"
      @saved="reload"
    />
    <ConceptDecisionDialog
      v-if="scope"
      v-model="decisionOpen"
      :scope="scope"
      :concept="decisionFor"
      :concepts="concepts"
      :limits="limits"
      @saved="reload"
    />
    <StrategyConfirmDialog
      v-model="deleteOpen"
      test-id="concept-delete-dialog"
      :title="t('app.strategy.concepts.delete.title', { title: deleting?.attributes.title ?? '' })"
      :body="deleteBody"
      :confirm-label="t('app.strategy.concepts.menu.delete')"
      :busy="deleteBusy"
      :error="deleteError"
      @confirm="confirmDelete"
    />
    <StrategyConfirmDialog
      :model-value="resetOpen"
      test-id="concept-reset-dialog"
      :title="t('app.strategy.concepts.reset.title')"
      :body="t('app.strategy.concepts.reset.body')"
      :confirm-label="t('app.strategy.concepts.reset.confirm')"
      confirm-color="primary"
      :busy="false"
      :error="null"
      @update:model-value="
        (v: boolean) => {
          if (!v) settleReset(false)
        }
      "
      @confirm="settleReset(true)"
    />
  </section>
</template>

<style scoped>
/*
 * Values from frame 13:828 (market bar, section head) as PersonasStep, and from frame 13:4412 (banner, card grid, "Add concept" slot).
 */
.concepts-step {
  display: flex;
  flex-direction: column;
  gap: 20px;
}
.market-bar {
  display: flex;
  align-items: flex-end;
  gap: 4px 14px;
  border-bottom: 1px solid rgb(var(--v-theme-border-color));
}
.market-bar__label {
  padding-bottom: 12px;
  font-size: 11px;
  font-weight: 600;
  line-height: normal;
  letter-spacing: 0.6px;
  text-transform: uppercase;
  color: rgb(var(--v-theme-text-tertiary));
}
.market-tabs {
  display: flex;
  gap: 4px;
  overflow-x: auto;
  min-width: 0;
}
.market-tab {
  position: relative;
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px 12px;
  border: 0;
  border-bottom: 2px solid transparent;
  background: transparent;
  color: rgb(var(--v-theme-on-surface-variant));
  font: inherit;
  font-size: 14px;
  line-height: normal;
  cursor: pointer;
  white-space: nowrap;
}
.market-tab--active {
  border-bottom-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-surface));
  font-weight: 600;
}
.market-tab__code {
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 700;
  background: rgb(var(--v-theme-surface-bright));
  color: rgb(var(--v-theme-on-surface-variant));
}
.market-tab--active .market-tab__code {
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
}
.market-tab__count {
  font-size: 12px;
  font-weight: 400;
  color: rgb(var(--v-theme-text-tertiary));
}
.market-tab__dot {
  width: 8px;
  height: 8px;
  border-radius: 9999px;
  background: rgb(var(--v-theme-warning));
}
.step-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.step-head__title {
  margin: 0 0 4px;
  font-size: 22px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
}
.step-head__sub {
  margin: 0;
  font-size: 13px;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
}
.step-head__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
/*
 * 13px medium, 14px icon, 8px gap, 6px 12px padding, 8px radius, 1px border. The frame draws these buttons with no
 * fill and the default border; the agency theme's secondary button has a surface fill and the strong border, and
 * its selector is more specific than any scoped one, hence the !important.
 */
.step-head__actions .step-btn,
.concepts-banner .step-btn {
  height: auto;
  min-height: 0;
  padding: 6px 12px;
  border: 1px solid rgb(var(--v-theme-border-color)) !important;
  border-radius: 8px;
  background-color: transparent !important;
  font-size: 13px;
  font-weight: 500;
  letter-spacing: normal;
  text-transform: none;
  color: rgb(var(--v-theme-on-surface));
}
.step-head__actions .step-btn :deep(.v-btn__prepend),
.concepts-banner .step-btn :deep(.v-btn__prepend) {
  margin-inline: 0 8px;
  font-size: 14px;
}
.concepts-filter {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  margin: 0;
  font-size: 13px;
  color: rgb(var(--v-theme-on-surface-variant, var(--v-theme-on-surface)));
}
.concepts-banner {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px 12px;
  padding: 14px 16px;
  border: 1px solid transparent;
  border-radius: 10px;
  font-size: 14px;
  line-height: normal;
  --banner: var(--agency-brand-amber);
  color: rgb(var(--v-theme-on-surface));
  background: color-mix(in srgb, var(--banner) 8%, transparent);
  border-color: color-mix(in srgb, var(--banner) 40%, transparent);
}
.concepts-banner--green {
  --banner: var(--agency-brand-green);
}
.concepts-banner .v-icon {
  color: var(--banner);
}
.concepts-banner__text {
  flex: 1 1 auto;
  min-width: 0;
  font-weight: 600;
  color: var(--banner);
}
.concepts-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 420px), 1fr));
  gap: 16px;
  align-items: start;
}
.concept-tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  min-height: 160px;
  padding: 24px;
  border: 1px dashed rgb(var(--v-theme-border-color));
  border-radius: 12px;
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;
  text-align: center;
}
.concept-tile:disabled {
  cursor: default;
  opacity: 0.6;
}
.concept-tile__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 46px;
  height: 46px;
  border-radius: 9999px;
  background: color-mix(in srgb, rgb(var(--v-theme-primary)) 12%, transparent);
  color: rgb(var(--v-theme-primary));
}
.concept-tile__title {
  font-size: 15px;
  font-weight: 600;
  color: rgb(var(--v-theme-primary));
}
.concept-tile__count {
  font-size: 12px;
  color: rgb(var(--v-theme-text-tertiary));
}
</style>
