<script setup lang="ts">
/**
 * PL-2.1 — step 2, Personas: 2 to 8 per market, one tab per market of the wave (in the wave's order). The active
 * market is in the address (`?market=SK`) so a reload or a shared link keeps it; the default is the first market.
 * A tab with fewer than 2 personas shows an amber dot (a warning, never blocking). At 8 both Add controls are off.
 *
 * All the wave's personas are loaded once and grouped by market here, so switching tabs never refetches and a save
 * in flight lands in the market it started in. Every save and delete reloads the list: the screen shows what the
 * server holds, including a card someone else removed.
 */
import type {
  StrategySummaryResource,
  WavePersonaResource,
  WaveUspResource,
} from '@catalyst/api-client'
import { personaInUseByConceptsMeta } from '@catalyst/api-client'
import type { PersonaInUseByConceptsMeta } from '@catalyst/api-client'
import { CEmptyState } from '@catalyst/ui'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { provideMarkets } from '../useMarkets'
import MarketTabs from './MarketTabs.vue'
import { useRoute, useRouter } from 'vue-router'

import { strategyApi } from '../api/strategy.api'
import { useStrategyContext } from '../composables/useStrategyContext'
import { isGone, strategyErrorMessage } from '../strategyErrors'
import PersonaCard from './PersonaCard.vue'
import PersonaCopyDialog from './PersonaCopyDialog.vue'
import PersonaDialog from './PersonaDialog.vue'
import StrategyConfirmDialog from './StrategyConfirmDialog.vue'

const props = defineProps<{ summary: StrategySummaryResource }>()

/** PL-2.4: the wave's markets by ULID, name and countries; the dialogs below read the same directory. */
const dir = provideMarkets(() => props.summary)
const emit = defineEmits<{ changed: [] }>()

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { brandId, waveId, scope } = useStrategyContext()

const limits = computed(() => props.summary.attributes.limits)
const personas = ref<WavePersonaResource[]>([])
const usps = ref<WaveUspResource[]>([])
const loading = ref(true)
const failed = ref(false)

const market = computed(() => {
  const q = route.query.market
  const wanted = typeof q === 'string' ? q : null
  return wanted !== null && dir.ids.value.includes(wanted) ? wanted : (dir.ids.value[0] ?? '')
})
const marketName = computed(() => dir.name(market.value))

function inMarket(code: string): WavePersonaResource[] {
  return personas.value.filter((p) => p.attributes.market === code)
}
const current = computed(() => inMarket(market.value))
const count = computed(() => current.value.length)
const atMax = computed(() => count.value >= limits.value.persona_max)
const belowMin = computed(() => count.value < limits.value.persona_min)
const canCopy = computed(
  () => !atMax.value && dir.ids.value.some((m) => m !== market.value && inMarket(m).length > 0),
)

async function load(): Promise<void> {
  const s = scope.value
  if (s === null) return
  try {
    const [p, u] = await Promise.all([strategyApi.listPersonas(s), strategyApi.listUsps(s)])
    personas.value = p.data
    usps.value = u.data
    failed.value = false
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}
onMounted(load)

function selectMarket(code: string): void {
  if (code === market.value) return
  void router.replace({ query: { ...route.query, market: code } })
}

async function reload(): Promise<void> {
  await load()
  emit('changed')
}

// Dialogs ---------------------------------------------------------------------------------------------------
const personaDialog = ref(false)
const editing = ref<WavePersonaResource | null>(null)
const copyDialog = ref(false)
const deleteOpen = ref(false)
const deleting = ref<WavePersonaResource | null>(null)
const deleteBusy = ref(false)
const deleteError = ref<string | null>(null)
/** PL-2.3: the concepts that use the persona; while there are any the delete is refused and they are listed. */
const blockedBy = ref<PersonaInUseByConceptsMeta['affected']>([])

function openAdd(): void {
  editing.value = null
  personaDialog.value = true
}
function openEdit(p: WavePersonaResource): void {
  editing.value = p
  personaDialog.value = true
}
function askDelete(p: WavePersonaResource): void {
  deleting.value = p
  deleteError.value = null
  blockedBy.value = []
  deleteOpen.value = true
}

async function confirmDelete(): Promise<void> {
  const s = scope.value
  if (s === null || deleting.value === null) return
  deleteBusy.value = true
  deleteError.value = null
  try {
    await strategyApi.removePersona(s, deleting.value.id)
    deleteOpen.value = false
    await reload()
  } catch (err) {
    const inUse = personaInUseByConceptsMeta(err)
    if (inUse !== null) {
      blockedBy.value = inUse.affected
      deleteError.value = strategyErrorMessage(err, t)
    } else if (isGone(err)) {
      deleteOpen.value = false
      await reload()
    } else {
      deleteError.value = strategyErrorMessage(err, t)
    }
  } finally {
    deleteBusy.value = false
  }
}

const angleCount = computed(
  () => deleting.value?.attributes.angles.filter((a) => !a.attributes.read_only).length ?? 0,
)
const cellCount = computed(() => deleting.value?.attributes.matrix_cells.length ?? 0)
/** The persona's cells for the confirmation: the USP and the level, in the USPs' order. */
const deletingCells = computed(() =>
  (deleting.value?.attributes.matrix_cells ?? []).map((c) => ({
    usp: usps.value.find((u) => u.id === c.usp)?.attributes.title ?? '',
    level: c.level,
  })),
)
</script>

<template>
  <section class="personas-step" data-test="personas-step">
    <MarketTabs :markets="dir.list.value" :active="market" @select="selectMarket">
      <template #extra="{ market: m }">
        <span class="market-tab__count" :data-test="`market-count-${m.code}`">{{
          loading ? '' : inMarket(m.id).length
        }}</span>
        <span
          v-if="!loading && inMarket(m.id).length < limits.persona_min"
          class="market-tab__dot"
          :data-test="`market-dot-${m.code}`"
          role="img"
          :aria-label="t('app.strategy.persona.fewerThanMin', { min: limits.persona_min })"
        >
          <v-tooltip location="bottom" activator="parent">
            {{ t('app.strategy.persona.fewerThanMin', { min: limits.persona_min }) }}
          </v-tooltip>
        </span>
      </template>
    </MarketTabs>

    <div class="step-head">
      <div>
        <h2 class="step-head__title" data-test="personas-heading">
          {{ t('app.strategy.persona.title', { market: marketName }) }}
        </h2>
        <p class="step-head__sub">
          {{
            t('app.strategy.persona.subtitle', { min: limits.persona_min, max: limits.persona_max })
          }}
        </p>
      </div>
      <div class="step-head__actions">
        <v-btn
          variant="outlined"
          size="small"
          class="step-btn"
          prepend-icon="mdi-content-copy"
          :disabled="!canCopy || loading || failed"
          data-test="persona-copy"
          @click="copyDialog = true"
        >
          {{ t('app.strategy.copy.open') }}
        </v-btn>
        <v-btn
          variant="outlined"
          size="small"
          class="step-btn"
          prepend-icon="mdi-plus"
          :disabled="atMax || loading || failed || market === ''"
          data-test="persona-add"
          @click="openAdd"
        >
          {{ t('app.strategy.persona.add') }}
        </v-btn>
      </div>
    </div>

    <v-alert
      v-if="!loading && !failed && belowMin"
      type="warning"
      variant="tonal"
      density="compact"
      data-test="persona-warning"
    >
      {{ t('app.strategy.persona.warning', { min: limits.persona_min, market: marketName }) }}
    </v-alert>

    <v-skeleton-loader v-if="loading" type="card, card" data-test="personas-skeleton" />
    <v-alert v-else-if="failed" type="error" variant="tonal" data-test="personas-error">
      {{ t('app.strategy.errors.loadFailed') }}
    </v-alert>
    <template v-else-if="scope">
      <CEmptyState
        v-if="count === 0"
        class="agency-card"
        data-test="personas-empty"
        title-tag="h3"
        :title="t('app.strategy.persona.emptyState.title', { market: marketName })"
        :body="
          t('app.strategy.persona.emptyState.body', {
            min: limits.persona_min,
            max: limits.persona_max,
          })
        "
      >
        <template #icon>
          <v-icon icon="mdi-account-group-outline" size="48" aria-hidden="true" />
        </template>
        <template #action>
          <v-btn
            color="primary"
            variant="flat"
            prepend-icon="mdi-plus"
            :disabled="atMax || market === ''"
            data-test="personas-empty-cta"
            @click="openAdd"
          >
            {{ t('app.strategy.persona.add') }}
          </v-btn>
        </template>
      </CEmptyState>
      <div v-else class="personas-grid" data-test="personas-grid">
        <PersonaCard
          v-for="p in current"
          :key="p.id"
          :persona="p"
          :usps="usps"
          :scope="scope"
          :limits="limits"
          :brand-id="brandId"
          :wave-id="waveId"
          @edit="openEdit"
          @remove="askDelete"
          @changed="reload"
        />
        <button
          type="button"
          class="persona-tile"
          :disabled="atMax || market === ''"
          data-test="persona-tile"
          @click="openAdd"
        >
          <v-icon
            icon="mdi-account-plus-outline"
            size="24"
            class="persona-tile__icon"
            aria-hidden="true"
          />
          <span class="persona-tile__title" data-test="persona-tile-title">
            {{ t('app.strategy.persona.add') }}
          </span>
          <span class="persona-tile__count" data-test="persona-tile-text">
            {{
              atMax
                ? t('app.strategy.persona.tileMax', {
                    count,
                    max: limits.persona_max,
                    market: marketName,
                  })
                : t('app.strategy.persona.tile', {
                    count,
                    max: limits.persona_max,
                    market: marketName,
                  })
            }}
          </span>
        </button>
      </div>
    </template>

    <PersonaDialog
      v-if="scope"
      v-model="personaDialog"
      :scope="scope"
      :limits="limits"
      :market="market"
      :persona="editing"
      @saved="reload"
      @gone="reload"
    />
    <PersonaCopyDialog
      v-if="scope"
      v-model="copyDialog"
      :scope="scope"
      :limits="limits"
      :target="market"
      :personas="personas"
      @copied="reload"
    />
    <StrategyConfirmDialog
      v-model="deleteOpen"
      test-id="persona-delete-dialog"
      :title="
        blockedBy.length > 0
          ? t('app.strategy.persona.blockedTitle', { name: deleting?.attributes.name ?? '' })
          : t('app.strategy.persona.deleteTitle', { name: deleting?.attributes.name ?? '' })
      "
      :body="
        blockedBy.length > 0
          ? ''
          : cellCount > 0
            ? t('app.strategy.persona.deleteBodyCells', {
                angles: t('app.strategy.counts.angles', { count: angleCount }, angleCount),
                cells: t('app.strategy.counts.cells', { count: cellCount }, cellCount),
              })
            : angleCount > 0
              ? t('app.strategy.persona.deleteBodyAngles', { count: angleCount }, angleCount)
              : t('app.strategy.persona.deleteBody')
      "
      :confirm-label="t('app.strategy.persona.delete')"
      :busy="deleteBusy"
      :error="deleteError"
      :confirm-disabled="blockedBy.length > 0"
      @confirm="confirmDelete"
    >
      <section
        v-if="blockedBy.length > 0"
        class="persona-delete-group"
        data-test="persona-delete-concepts-group"
      >
        <h3 class="persona-delete-group__title" data-test="persona-delete-concepts-heading">
          {{ t('app.strategy.stepper.concepts') }}
        </h3>
        <ul class="persona-delete-cells" data-test="persona-delete-concepts">
          <li v-for="c in blockedBy" :key="c.concept">
            {{ t('app.strategy.concepts.card.number', { n: c.number }) }} · {{ c.title }} ·
            {{ t(`app.strategy.concepts.status.${c.status}`) }}
          </li>
        </ul>
        <v-btn
          v-if="deleting"
          variant="text"
          size="small"
          prepend-icon="mdi-open-in-new"
          class="px-0 mt-1"
          :to="{
            name: 'strategy.concepts',
            params: { ulid: brandId, waveUlid: waveId },
            query: { market, persona: deleting.id },
          }"
          data-test="persona-delete-concepts-link"
        >
          {{ t('app.strategy.persona.seeConcepts') }}
        </v-btn>
      </section>
      <section
        v-if="cellCount > 0"
        class="persona-delete-group"
        data-test="persona-delete-cells-group"
      >
        <h3 class="persona-delete-group__title" data-test="persona-delete-cells-heading">
          {{ t('app.strategy.persona.groupCells') }}
        </h3>
        <ul class="persona-delete-cells" data-test="persona-delete-cells">
          <li v-for="c in deletingCells" :key="c.usp + c.level">
            {{ c.usp }} · {{ t(`app.strategy.matrix.level.${c.level}`) }}
          </li>
        </ul>
      </section>
    </StrategyConfirmDialog>
  </section>
</template>

<style scoped>
/*
 * Values from frame 13:828 (market bar, section head, "Add persona" slot), through the agency theme tokens.
 */
.personas-step {
  display: flex;
  flex-direction: column;
  gap: 20px;
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
.step-head__actions .step-btn :deep(.v-btn__prepend) {
  margin-inline: 0 8px;
  font-size: 14px;
}
.personas-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 340px), 1fr));
  gap: 16px;
  align-items: start;
}
.persona-tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  min-height: 160px;
  padding: 32px;
  border: 1px dashed rgb(var(--v-theme-border-color));
  border-radius: 10px;
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;
  text-align: center;
}
.persona-tile:disabled {
  cursor: default;
  opacity: 0.6;
}
.persona-tile__icon,
.persona-tile__title {
  color: rgb(var(--v-theme-primary));
}
.persona-tile__title {
  font-size: 15px;
  font-weight: 600;
  line-height: normal;
}
.persona-tile__count {
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary));
}
.persona-delete-group + .persona-delete-group {
  margin-top: 12px;
}
.persona-delete-group__title {
  margin: 0 0 4px;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.6px;
  text-transform: uppercase;
  color: rgb(var(--v-theme-text-tertiary));
}
.persona-delete-cells {
  margin: 0;
  padding-left: 18px;
  font-size: 13px;
  line-height: 19px;
  max-height: 30vh;
  overflow-y: auto;
}
</style>
