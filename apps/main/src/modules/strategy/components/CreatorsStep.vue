<script setup lang="ts">
/**
 * PL-3.1 — step 5, Creators (frame 13:1953): one tab per market (`?market=`), the step title and its buttons, the summary row
 * (total, per status, per persona, the warning for a persona with no creator), the card grid and the add slot. A wave with no
 * campaign gets the link / create panel instead: creators come from a campaign.
 *
 * The whole payload (every market's slots, derived statuses, decisions) is read on open and after every change, without a
 * loading state on a reload, so the grid does not flicker and an open dialog or menu stays where it is (cards are keyed by id).
 * Personas of the wave are read once for the persona selects. The status is NEVER set here: it follows the campaign.
 */
import type {
  StrategySummaryResource,
  WaveCreatorMarket,
  WaveCreatorSlot,
  WaveCreatorsData,
  WavePersonaResource,
  WaveResource,
} from '@catalyst/api-client'
import { CEmptyState } from '@catalyst/ui'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import CreatorProfileDialog from '@/components/CreatorProfileDialog.vue'
import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { brandColorVar } from '@/modules/agency/composables/brandColor'
import AgencyStatusPill from '@/modules/agency/components/AgencyStatusPill.vue'
import { strategyApi } from '../api/strategy.api'
import { useStrategyContext } from '../composables/useStrategyContext'
import {
  personasWithoutCreator,
  roomInMarket,
  roomInWave,
  statusPills,
  statusTone,
  waveCount,
} from '../creatorsLogic'
import { isGone, strategyErrorMessage } from '../strategyErrors'
import { provideMarkets } from '../useMarkets'
import CreatorDecisionDialog from './CreatorDecisionDialog.vue'
import CreatorHistoryDialog from './CreatorHistoryDialog.vue'
import CreatorPickerDialog from './CreatorPickerDialog.vue'
import MarketTabs from './MarketTabs.vue'
import NotesSheet from './NotesSheet.vue'
import StrategyConfirmDialog from './StrategyConfirmDialog.vue'
import WaveCampaignPanel from './WaveCampaignPanel.vue'
import WaveCreatorCard from './WaveCreatorCard.vue'

const props = defineProps<{ summary: StrategySummaryResource; wave: WaveResource }>()
const emit = defineEmits<{ changed: []; wave: [wave: WaveResource] }>()

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const agencyStore = useAgencyStore()
const { scope, canDecide } = useStrategyContext()
const dir = provideMarkets(() => props.summary)

const data = ref<WaveCreatorsData | null>(null)
const personas = ref<WavePersonaResource[]>([])
const loading = ref(true)
const failed = ref(false)
const pageError = ref<string | null>(null)
const notice = ref<string | null>(null)
const busyIds = ref<string[]>([])
let ticket = 0

const agencyId = computed(() => agencyStore.currentAgencyId ?? '')
const limits = computed(() => props.summary.attributes.limits)
const market = computed(() => {
  const q = route.query.market
  const wanted = typeof q === 'string' ? q : null
  return wanted !== null && dir.ids.value.includes(wanted) ? wanted : (dir.ids.value[0] ?? '')
})
const marketInfo = computed(() => dir.find(market.value) ?? null)
const marketName = computed(() => dir.name(market.value))
const current = computed<WaveCreatorMarket | undefined>(() =>
  data.value?.markets.find((m) => m.market === market.value),
)
const marketPersonas = computed(() =>
  personas.value.filter((p) => p.attributes.market === market.value),
)
const linked = computed(() => data.value?.campaign.linked === true)
const slots = computed(() => current.value?.slots ?? [])
const missing = computed(() =>
  current.value === undefined ? [] : personasWithoutCreator(current.value),
)
const pills = computed(() => (current.value === undefined ? [] : statusPills(current.value)))
const perMarket = computed(() => data.value?.limits.per_market ?? 30)
const perWave = computed(() => data.value?.limits.per_wave ?? 100)
const roomMkt = computed(() => roomInMarket(current.value, perMarket.value))
const roomWv = computed(() => roomInWave(waveCount(data.value?.markets ?? []), perWave.value))
const atCap = computed(() => Math.min(roomMkt.value, roomWv.value) === 0)

function tabCount(id: string): number {
  return data.value?.markets.find((m) => m.market === id)?.count ?? 0
}
function personaColor(personaId: string): string {
  const p = personas.value.find((x) => x.id === personaId)
  return p === undefined
    ? 'var(--v-theme-on-surface-variant)'
    : brandColorVar(p.attributes.color_key)
}

async function loadPersonas(): Promise<void> {
  const s = scope.value
  if (s === null) return
  try {
    personas.value = (await strategyApi.listPersonas(s)).data
  } catch {
    // The persona selects stay empty; the page's own error is for the creators list.
  }
}

async function load(first = false): Promise<void> {
  const s = scope.value
  if (s === null) return
  const mine = ++ticket
  try {
    const res = await strategyApi.creators(s)
    if (mine !== ticket) return
    data.value = res.data
    failed.value = false
  } catch {
    if (mine === ticket && first) failed.value = true
  } finally {
    if (mine === ticket) loading.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadPersonas(), load(true)])
})

watch(market, () => {
  pageError.value = null
  notice.value = null
})

function selectMarket(code: string): void {
  if (code === market.value) return
  void router.replace({ query: { ...route.query, market: code } })
}

async function reload(): Promise<void> {
  await load()
  emit('changed')
}

function apply(next: WaveCreatorsData): void {
  data.value = next
  emit('changed')
}

async function withBusy(id: string, work: () => Promise<void>): Promise<void> {
  busyIds.value = [...busyIds.value, id]
  pageError.value = null
  notice.value = null
  try {
    await work()
  } catch (err) {
    pageError.value = strategyErrorMessage(err, t)
    if (isGone(err)) await reload()
  } finally {
    busyIds.value = busyIds.value.filter((x) => x !== id)
  }
}

async function setPersona(row: WaveCreatorSlot, personaId: string | null): Promise<void> {
  const s = scope.value
  if (s === null) return
  await withBusy(row.id, async () =>
    apply((await strategyApi.updateCreator(s, row.id, { persona: personaId })).data),
  )
}
async function setSlotCode(row: WaveCreatorSlot, code: string): Promise<void> {
  const s = scope.value
  if (s === null) return
  await withBusy(row.id, async () =>
    apply((await strategyApi.updateCreator(s, row.id, { slot_code: code })).data),
  )
}

// Dialogs --------------------------------------------------------------------------------------------------
const pickerOpen = ref(false)
const pickerMode = ref<'add' | 'replace'>('add')
const replacing = ref<WaveCreatorSlot | null>(null)
function openAdd(): void {
  pickerMode.value = 'add'
  replacing.value = null
  pickerOpen.value = true
}
function openReplace(row: WaveCreatorSlot): void {
  pickerMode.value = 'replace'
  replacing.value = row
  pickerOpen.value = true
}

const removeOpen = ref(false)
const removing = ref<WaveCreatorSlot | null>(null)
const removeBusy = ref(false)
const removeError = ref<string | null>(null)
function askRemove(row: WaveCreatorSlot): void {
  removing.value = row
  removeError.value = null
  removeOpen.value = true
}
async function confirmRemove(): Promise<void> {
  const s = scope.value
  const row = removing.value
  if (s === null || row === null) return
  removeBusy.value = true
  removeError.value = null
  try {
    apply((await strategyApi.removeCreator(s, row.id)).data)
    removeOpen.value = false
  } catch (err) {
    if (isGone(err)) {
      removeOpen.value = false
      await reload()
    } else {
      removeError.value = strategyErrorMessage(err, t)
    }
  } finally {
    removeBusy.value = false
  }
}
const removeBody = computed(() => {
  const row = removing.value
  if (row === null) return ''
  const base = t('app.strategy.creators.remove.body')
  return row.decision_count > 0
    ? `${base} ${t('app.strategy.creators.remove.bodyDecisions')}`
    : base
})

const decisionOpen = ref(false)
const decisionFor = ref<WaveCreatorSlot | null>(null)
function openDecision(row: WaveCreatorSlot): void {
  decisionFor.value = row
  decisionOpen.value = true
}
function onDecided(meta: { moved: boolean; notMoved: string | null }): void {
  notice.value = meta.moved
    ? t('app.strategy.creators.decision.cardMoved')
    : meta.notMoved !== null
      ? t('app.strategy.creators.decision.cardNotMoved')
      : null
  void reload()
}
const historyOpen = ref(false)
const historyFor = ref<WaveCreatorSlot | null>(null)
function openHistory(row: WaveCreatorSlot): void {
  historyFor.value = row
  historyOpen.value = true
}

const profileOpen = ref(false)
const profileCreator = ref('')
function openProfile(row: WaveCreatorSlot): void {
  if (row.creator === null || row.creator.is_erased) return
  profileCreator.value = row.creator.id
  profileOpen.value = true
}

// Notes (the step's own, per market) -------------------------------------------------------------------------
const notesOpen = ref(false)
const noteDraft = ref('')
const noteCount = computed(() => current.value?.notes_count ?? 0)
watch(market, () => {
  noteDraft.value = ''
  notesOpen.value = false
})

function onLinked(w: WaveResource): void {
  emit('wave', w)
  loading.value = true
  void reload()
}
</script>

<template>
  <section class="creators-step" data-test="creators-step">
    <MarketTabs :markets="dir.list.value" :active="market" @select="selectMarket">
      <template #extra="{ market: m }">
        <span class="market-tab__count" :data-test="`market-count-${m.code}`">{{
          tabCount(m.id)
        }}</span>
      </template>
    </MarketTabs>

    <div class="step-head">
      <div>
        <h2 class="step-head__title" data-test="creators-heading">
          {{ t('app.strategy.creators.title', { market: marketName }) }}
        </h2>
        <p class="step-head__sub">{{ t('app.strategy.creators.subtitle') }}</p>
      </div>
      <div class="step-head__actions">
        <v-btn
          v-if="linked && market !== ''"
          variant="outlined"
          size="small"
          class="step-btn"
          prepend-icon="mdi-note-text-outline"
          data-test="creators-notes-open"
          @click="notesOpen = true"
        >
          {{ t('app.strategy.notes.open', { count: noteCount }) }}
        </v-btn>
        <v-btn
          v-if="linked"
          color="primary"
          variant="flat"
          size="small"
          class="step-primary"
          prepend-icon="mdi-account-plus-outline"
          :disabled="loading || failed || atCap || market === ''"
          data-test="creators-add"
          @click="openAdd"
        >
          {{ t('app.strategy.creators.add') }}
        </v-btn>
      </div>
    </div>

    <v-skeleton-loader v-if="loading" type="card, card" data-test="creators-skeleton" />
    <v-alert
      v-else-if="failed || data === null"
      type="error"
      variant="tonal"
      data-test="creators-load-error"
    >
      {{ t('app.strategy.errors.loadFailed') }}
    </v-alert>
    <WaveCampaignPanel
      v-else-if="!linked && scope"
      :scope="scope"
      :can-link="canDecide"
      @linked="onLinked"
    />
    <template v-else-if="scope">
      <div v-if="current" class="summary" data-test="creators-summary">
        <span class="summary__count" data-test="creators-summary-count">{{
          t('app.strategy.creators.summary.total', { count: current.summary.total })
        }}</span>
        <AgencyStatusPill
          v-for="p in pills"
          :key="p.status"
          :tone="statusTone(p.status)"
          :label="`${p.count} ${t(`app.strategy.creators.status.${p.status}`)}`"
          :data-test="`creators-summary-${p.status}`"
        />
        <template v-if="current.summary.personas.length > 0">
          <span class="summary__divider" aria-hidden="true" />
          <span class="summary__label">{{ t('app.strategy.creators.summary.coverage') }}</span>
          <span
            v-for="p in current.summary.personas"
            :key="p.id"
            class="summary__persona"
            :style="{ '--usp-color': personaColor(p.id) }"
            :data-test="`creators-summary-persona-${p.code}`"
          >
            {{ p.code }} · {{ p.count }}
          </span>
        </template>
      </div>

      <v-alert
        v-if="missing.length > 0"
        type="warning"
        variant="tonal"
        density="compact"
        data-test="creators-persona-warning"
      >
        {{ t('app.strategy.creators.summary.personaWarning', { personas: missing.join(', ') }) }}
      </v-alert>
      <v-alert
        v-if="notice"
        type="info"
        variant="tonal"
        density="compact"
        closable
        data-test="creators-notice"
        @click:close="notice = null"
      >
        {{ notice }}
      </v-alert>
      <v-alert
        v-if="pageError"
        type="error"
        variant="tonal"
        density="compact"
        data-test="creators-error"
      >
        {{ pageError }}
      </v-alert>
      <v-alert
        v-if="atCap"
        type="info"
        variant="tonal"
        density="compact"
        data-test="creators-at-cap"
      >
        {{
          roomMkt === 0
            ? t('app.strategy.creators.picker.maxMarket', { max: perMarket, market: marketName })
            : t('app.strategy.creators.picker.maxWave', { max: perWave })
        }}
      </v-alert>

      <CEmptyState
        v-if="slots.length === 0"
        class="agency-card"
        data-test="creators-empty"
        title-tag="h3"
        :title="t('app.strategy.creators.empty.title', { market: marketName })"
        :body="t('app.strategy.creators.empty.body', { campaign: data.campaign.name ?? '' })"
      >
        <template #icon>
          <v-icon icon="mdi-account-multiple-plus-outline" size="48" aria-hidden="true" />
        </template>
        <template #action>
          <v-btn
            color="primary"
            variant="flat"
            prepend-icon="mdi-account-plus-outline"
            :disabled="atCap"
            data-test="creators-empty-cta"
            @click="openAdd"
          >
            {{ t('app.strategy.creators.add') }}
          </v-btn>
        </template>
      </CEmptyState>
      <div v-else class="grid" data-test="creators-grid">
        <WaveCreatorCard
          v-for="row in slots"
          :key="row.id"
          :row="row"
          :personas="marketPersonas"
          :busy="busyIds.includes(row.id)"
          :can-manage="true"
          :can-decide="canDecide"
          @persona="(id: string | null) => setPersona(row, id)"
          @slot-code="(code: string) => setSlotCode(row, code)"
          @profile="openProfile(row)"
          @replace="openReplace(row)"
          @remove="askRemove(row)"
          @decide="openDecision(row)"
          @history="openHistory(row)"
        />
        <button
          type="button"
          class="add-slot"
          :disabled="atCap"
          data-test="creators-add-slot"
          @click="openAdd"
        >
          <span class="add-slot__icon"
            ><v-icon icon="mdi-account-plus-outline" size="22" aria-hidden="true"
          /></span>
          <span class="add-slot__title">{{ t('app.strategy.creators.add') }}</span>
          <span class="add-slot__caption">{{
            t('app.strategy.creators.addSlotCaption', { market: marketName })
          }}</span>
        </button>
      </div>

      <CreatorPickerDialog
        v-model="pickerOpen"
        :scope="scope"
        :market="marketInfo"
        :market-name="dir.name"
        :personas="marketPersonas"
        :campaign-id="data.campaign.id"
        :campaign-name="data.campaign.name"
        :mode="pickerMode"
        :replacing="replacing"
        :room-market="roomMkt"
        :room-wave="roomWv"
        :per-market="perMarket"
        :per-wave="perWave"
        @done="reload"
      />
      <CreatorDecisionDialog
        v-model="decisionOpen"
        :scope="scope"
        :row="decisionFor"
        :limits="limits"
        @saved="onDecided"
      />
      <CreatorHistoryDialog v-model="historyOpen" :scope="scope" :row="historyFor" />
      <StrategyConfirmDialog
        v-model="removeOpen"
        :title="t('app.strategy.creators.remove.title')"
        :body="removeBody"
        :confirm-label="t('app.strategy.creators.card.remove')"
        :busy="removeBusy"
        :error="removeError"
        test-id="creator-remove-dialog"
        @confirm="confirmRemove"
      />
      <CreatorProfileDialog
        v-if="profileCreator !== ''"
        v-model="profileOpen"
        :agency-id="agencyId"
        :creator-ulid="profileCreator"
      />
      <NotesSheet
        v-if="market !== ''"
        v-model="notesOpen"
        v-model:draft="noteDraft"
        step="creators"
        :market="market"
        :market-name="marketName"
        @changed="reload"
      />
    </template>
  </section>
</template>

<style scoped>
.creators-step {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
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
.step-head__actions .step-btn {
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
.step-head__actions .step-primary {
  height: auto;
  min-height: 0;
  padding: 9px 16px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 600;
  letter-spacing: normal;
  text-transform: none;
}
/* Frame 13:2160: count 13/600, status pills, a divider, "Persona coverage:" 12/400, persona pills 12/500, gap 10. */
.summary {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 10px;
}
.summary__count {
  font-size: 13px;
  font-weight: 600;
}
.summary__divider {
  width: 1px;
  height: 12px;
  background: rgb(var(--v-theme-border-color));
}
.summary__label {
  font-size: 12px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.summary__persona {
  padding: 4px 10px;
  border-radius: 9999px;
  background: color-mix(in srgb, var(--usp-color) 15%, transparent);
  font-size: 12px;
  font-weight: 500;
  color: var(--usp-color);
}
/* Frame 13:2177: four cards of 291 per row at 1236, gap 16. */
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 16px;
  align-items: start;
}
@media (min-width: 1100px) {
  .grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}
/* Frame 13:2563: a 1 px dashed border, radius 10, padding 24, gap 10, centred. */
.add-slot {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  min-height: 300px;
  padding: 24px;
  border: 1px dashed rgb(var(--v-theme-border-color));
  border-radius: 10px;
  background: transparent;
  text-align: center;
  cursor: pointer;
  color: inherit;
}
.add-slot:hover:not(:disabled) {
  background: rgb(var(--v-theme-surface));
}
.add-slot:disabled {
  opacity: 0.5;
  cursor: default;
}
.add-slot__icon {
  display: inline-flex;
  padding: 12px;
  border-radius: 9999px;
  background: color-mix(in srgb, rgb(var(--v-theme-primary)) 12%, transparent);
  color: rgb(var(--v-theme-primary));
}
.add-slot__title {
  font-size: 15px;
  font-weight: 600;
  color: rgb(var(--v-theme-primary));
}
.add-slot__caption {
  font-size: 12px;
  color: rgb(var(--v-theme-on-surface-variant));
}
</style>
