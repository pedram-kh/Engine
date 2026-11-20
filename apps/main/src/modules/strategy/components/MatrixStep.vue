<script setup lang="ts">
/**
 * PL-2.2 — step 3, the messaging matrix: one tab per market of the wave (in the wave's order; the active market is in
 * the address, `?market=SK`), the legend, the table and the footer note. One market is loaded in one request (the
 * USPs, the market's personas and its set cells). A cell is edited in a popover (a sheet under 720 px); one cell is one
 * request, and the screen takes the server's answer for that cell, so what is drawn is what is held.
 *
 * Each tab shows its own "set/total"; the stepper shows the wave's total (the summary is refreshed after every save).
 * With no persona in the market, or no USP in the wave, the table is replaced by a line that says where to add them.
 */
import type {
  MatrixCell,
  MatrixPersona,
  MatrixResource,
  MatrixUsp,
  StrategySummaryResource,
} from '@catalyst/api-client'
import { labelForCountryCode } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import '../matrixColors.css'
import { strategyApi } from '../api/strategy.api'
import { useStrategyContext } from '../composables/useStrategyContext'
import { cellKey, changeFor, nextCell, type EditorState } from '../matrixLogic'
import MatrixCellEditor from './MatrixCellEditor.vue'
import MatrixTable from './MatrixTable.vue'

const props = defineProps<{ summary: StrategySummaryResource; markets: string[] }>()
const emit = defineEmits<{ changed: [] }>()

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { brandId, waveId, scope } = useStrategyContext()

const limits = computed(() => props.summary.attributes.limits)
const market = computed(() => {
  const q = route.query.market
  const wanted = typeof q === 'string' ? q : null
  return wanted !== null && props.markets.includes(wanted) ? wanted : (props.markets[0] ?? '')
})
const marketName = computed(() => (market.value === '' ? '' : labelForCountryCode(market.value)))

const data = ref<MatrixResource['attributes'] | null>(null)
const cells = ref(new Map<string, MatrixCell>())
const loading = ref(true)
const failed = ref(false)
/** Bumped on every load; an answer for a market the user already left must not fill the screen. */
let loadId = 0

async function load(): Promise<void> {
  const s = scope.value
  const code = market.value
  if (s === null || code === '') {
    loading.value = false
    return
  }
  const id = ++loadId
  loading.value = true
  failed.value = false
  closeEditor()
  try {
    const res = await strategyApi.matrix(s, code)
    if (id !== loadId) return
    data.value = res.data.attributes
    cells.value = new Map(
      res.data.attributes.cells.map((c) => [cellKey(c.persona, c.usp), c] as const),
    )
  } catch {
    if (id === loadId) {
      data.value = null
      failed.value = true
    }
  } finally {
    if (id === loadId) loading.value = false
  }
}
function selectMarket(code: string): void {
  if (code === market.value) return
  void router.replace({ query: { ...route.query, market: code } })
}

const usps = computed<MatrixUsp[]>(() => data.value?.usps ?? [])
const personas = computed<MatrixPersona[]>(() => data.value?.personas ?? [])
const ready = computed(() => usps.value.length > 0 && personas.value.length > 0)

function progressOf(code: string): { set: number; total: number } | null {
  const row = props.summary.attributes.matrix.by_market.find((m) => m.market === code)
  return row === undefined ? null : { set: row.set, total: row.total }
}

// The editor ------------------------------------------------------------------------------------------------
const editorOpen = ref(false)
const anchor = ref<HTMLElement | null>(null)
const editing = ref<{ persona: MatrixPersona; usp: MatrixUsp } | null>(null)

const editingCell = computed(() =>
  editing.value === null
    ? null
    : (cells.value.get(cellKey(editing.value.persona.id, editing.value.usp.id)) ?? null),
)
const activeKey = computed(() =>
  editorOpen.value && editing.value !== null
    ? cellKey(editing.value.persona.id, editing.value.usp.id)
    : null,
)
const heading = computed(() =>
  editing.value === null ? '' : `${editing.value.usp.title} · ${editing.value.persona.code}`,
)
const next = computed(() => {
  if (editing.value === null) return null
  const pi = personas.value.findIndex((p) => p.id === editing.value?.persona.id)
  const ui = usps.value.findIndex((u) => u.id === editing.value?.usp.id)
  return nextCell(pi, ui, personas.value.length, usps.value.length)
})

function openEditor(persona: MatrixPersona, usp: MatrixUsp, el: HTMLElement): void {
  editing.value = { persona, usp }
  anchor.value = el
  editorOpen.value = true
}
function closeEditor(): void {
  editorOpen.value = false
}

function take(changed: MatrixCell[]): void {
  const m = new Map(cells.value)
  for (const c of changed) {
    if (c.level === null) m.delete(cellKey(c.persona, c.usp))
    else m.set(cellKey(c.persona, c.usp), c)
  }
  cells.value = m
}

async function saveCell(state: EditorState): Promise<void> {
  const s = scope.value
  const e = editing.value
  if (s === null || e === null) return
  const change = changeFor(e.persona.id, e.usp.id, editingCell.value, state)
  if (change === null) return
  const res = await strategyApi.saveCells(s, market.value, [change])
  take(res.data.cells)
  emit('changed')
}

async function clearCell(): Promise<void> {
  const s = scope.value
  const e = editing.value
  if (s === null || e === null) return
  const res = await strategyApi.saveCells(s, market.value, [
    { persona: e.persona.id, usp: e.usp.id, clear: true },
  ])
  take(res.data.cells)
  emit('changed')
}

function onDone(goNext: boolean): void {
  const n = goNext ? next.value : null
  if (n === null) {
    closeEditor()
    return
  }
  const persona = personas.value[n.personaIndex]
  const usp = usps.value[n.uspIndex]
  if (persona === undefined || usp === undefined) {
    closeEditor()
    return
  }
  // The next cell's element is in the table now; the popover moves to it.
  const el = document.querySelector<HTMLElement>(
    `[data-test="matrix-cell"][data-persona-id="${persona.id}"][data-usp-id="${usp.id}"]`,
  )
  if (el === null) {
    closeEditor()
    return
  }
  editorOpen.value = false
  void Promise.resolve().then(() => openEditor(persona, usp, el))
}

// After the editor's state exists: the first load runs at once and again on every market change.
watch(market, () => void load(), { immediate: true })

const errorText = computed(() => (failed.value ? t('app.strategy.errors.loadFailed') : null))
</script>

<template>
  <section class="mx-root matrix-step" data-test="matrix-step">
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
          <span
            v-if="progressOf(code)"
            class="market-tab__count"
            :aria-label="
              t('app.strategy.matrix.tabProgress', {
                set: progressOf(code)?.set ?? 0,
                total: progressOf(code)?.total ?? 0,
                market: labelForCountryCode(code),
              })
            "
            :data-test="`market-progress-${code}`"
            >{{ progressOf(code)?.set }}/{{ progressOf(code)?.total }}</span
          >
        </button>
      </div>
    </div>

    <div class="step-head">
      <div>
        <h2 class="step-head__title" data-test="matrix-heading">
          {{ t('app.strategy.matrix.title', { market: marketName }) }}
        </h2>
        <p class="step-head__sub">{{ t('app.strategy.matrix.subtitle') }}</p>
      </div>
    </div>

    <ul class="mx-legend" data-test="matrix-legend">
      <li v-for="l in ['priority', 'support', 'low']" :key="l" class="mx-legend__item">
        <span class="mx-legend__dot" :class="`mx-legend__dot--${l}`" aria-hidden="true" />
        <span class="mx-legend__label">{{ t(`app.strategy.matrix.level.${l}`) }}</span>
        <span class="mx-legend__hint">· {{ t(`app.strategy.matrix.legend.${l}Hint`) }}</span>
      </li>
    </ul>

    <v-skeleton-loader v-if="loading" type="table" data-test="matrix-skeleton" />
    <div v-else-if="errorText" class="mx-empty" data-test="matrix-error">
      <v-alert type="error" variant="tonal">{{ errorText }}</v-alert>
      <v-btn size="small" variant="outlined" data-test="matrix-retry" @click="load">
        {{ t('app.strategy.matrix.retry') }}
      </v-btn>
    </div>
    <p v-else-if="usps.length === 0" class="mx-empty" data-test="matrix-no-usps">
      {{ t('app.strategy.matrix.noUsps') }}
      <router-link :to="{ name: 'strategy.usps', params: { ulid: brandId, waveUlid: waveId } }">
        {{ t('app.strategy.persona.goToUsps') }}
      </router-link>
    </p>
    <p v-else-if="personas.length === 0" class="mx-empty" data-test="matrix-no-personas">
      {{ t('app.strategy.matrix.noPersonas', { market: marketName }) }}
      <router-link
        :to="{
          name: 'strategy.personas',
          params: { ulid: brandId, waveUlid: waveId },
          query: { market },
        }"
      >
        {{ t('app.strategy.matrix.goToPersonas') }}
      </router-link>
    </p>
    <MatrixTable
      v-else-if="ready"
      :usps="usps"
      :personas="personas"
      :cells="cells"
      :active-key="activeKey"
      @open="openEditor"
    />

    <p class="mx-foot" data-test="matrix-footer">
      <v-icon icon="mdi-information-outline" size="14" aria-hidden="true" />
      <span>{{ t('app.strategy.matrix.footer') }}</span>
    </p>

    <MatrixCellEditor
      v-model="editorOpen"
      :anchor="anchor"
      :cell="editingCell"
      :heading="heading"
      :limits="limits"
      :has-next="next !== null"
      :save="saveCell"
      :clear="clearCell"
      @done="onDone"
    />
  </section>
</template>

<style>
/* The level colours, shared by the table, the legend and the teleported editor. The fills and the line colours are
   frame 13:1375's values and live in `../matrixColors.css` (`--agency-matrix-*`); the
   level colours are the agency brand green and blue. */
.mx-root,
.mx-editor-overlay {
  --mx-priority: var(--agency-brand-green);
  --mx-support: var(--agency-brand-blue);
  --mx-low: var(--agency-matrix-low);
  --mx-priority-fill: var(--agency-matrix-priority-fill);
  --mx-support-fill: var(--agency-matrix-support-fill);
  --mx-low-fill: var(--agency-matrix-low-fill);
  --mx-priority-tint: var(--agency-matrix-priority-tint);
  --mx-yellow-strong: var(--agency-matrix-line-strong);
  --mx-yellow-soft: var(--agency-matrix-line-soft);
  --mx-yellow-fill: var(--agency-matrix-line-fill);
}
</style>

<style scoped>
/*
 * The market bar and the section head are the ones of the Personas step (frame 13:828), repeated here so that step stays
 * untouched; the legend is frame 13:1375: right-aligned, gap 20, each item a dot of 8, gap 6, label 12 / 600 and hint 12.
 */
.matrix-step {
  display: flex;
  flex-direction: column;
  gap: 20px;
  min-width: 0;
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
.step-head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: 12px 20px;
}
.step-head__title {
  margin: 0;
  font-size: 22px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
}
.step-head__sub {
  margin: 4px 0 0;
  font-size: 13px;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
}
.mx-legend {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 6px 20px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.mx-legend__item {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  line-height: normal;
}
.mx-legend__dot {
  flex: none;
  width: 8px;
  height: 8px;
  border-radius: 9999px;
}
.mx-legend__dot--priority {
  background: var(--mx-priority);
}
.mx-legend__dot--support {
  background: var(--mx-support);
}
.mx-legend__dot--low {
  background: var(--mx-low);
}
.mx-legend__label {
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
}
.mx-legend__hint {
  color: rgb(var(--v-theme-text-tertiary));
}
.mx-empty {
  margin: 0;
  font-size: 13px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.mx-foot {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  color: rgb(var(--v-theme-text-tertiary));
}
.mx-foot .v-icon {
  flex: none;
  margin-top: 1px;
}
@media (max-width: 719px) {
  .mx-legend {
    justify-content: flex-start;
  }
}
</style>
