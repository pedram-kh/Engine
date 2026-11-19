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
import { labelForCountryCode } from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import { strategyApi } from '../api/strategy.api'
import { useStrategyContext } from '../composables/useStrategyContext'
import { isGone, strategyErrorMessage } from '../strategyErrors'
import PersonaCard from './PersonaCard.vue'
import PersonaCopyDialog from './PersonaCopyDialog.vue'
import PersonaDialog from './PersonaDialog.vue'
import StrategyConfirmDialog from './StrategyConfirmDialog.vue'

const props = defineProps<{ summary: StrategySummaryResource; markets: string[] }>()
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
  return wanted !== null && props.markets.includes(wanted) ? wanted : (props.markets[0] ?? '')
})
const marketName = computed(() => (market.value === '' ? '' : labelForCountryCode(market.value)))

function inMarket(code: string): WavePersonaResource[] {
  return personas.value.filter((p) => p.attributes.market === code)
}
const current = computed(() => inMarket(market.value))
const count = computed(() => current.value.length)
const atMax = computed(() => count.value >= limits.value.persona_max)
const belowMin = computed(() => count.value < limits.value.persona_min)
const canCopy = computed(
  () => !atMax.value && props.markets.some((m) => m !== market.value && inMarket(m).length > 0),
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

const angleCount = computed(() => deleting.value?.attributes.angles.length ?? 0)
</script>

<template>
  <section class="personas-step" data-test="personas-step">
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
          loading ? '' : inMarket(code).length
        }}</span>
        <v-tooltip
          v-if="!loading && inMarket(code).length < limits.persona_min"
          location="bottom"
          activator="parent"
        >
          {{ t('app.strategy.persona.fewerThanMin', { min: limits.persona_min }) }}
        </v-tooltip>
        <span
          v-if="!loading && inMarket(code).length < limits.persona_min"
          class="market-tab__dot"
          :data-test="`market-dot-${code}`"
          role="img"
          :aria-label="t('app.strategy.persona.fewerThanMin', { min: limits.persona_min })"
        />
      </button>
    </div>

    <div class="d-flex flex-wrap align-center justify-space-between ga-3">
      <div>
        <h2 class="text-h6 mb-1" data-test="personas-heading">
          {{ t('app.strategy.persona.title', { market: marketName }) }}
        </h2>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{
            t('app.strategy.persona.subtitle', { min: limits.persona_min, max: limits.persona_max })
          }}
        </p>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <v-btn
          variant="outlined"
          prepend-icon="mdi-content-copy"
          :disabled="!canCopy || loading || failed"
          data-test="persona-copy"
          @click="copyDialog = true"
        >
          {{ t('app.strategy.copy.open') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
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
      <p
        v-if="count === 0"
        class="text-body-2 text-medium-emphasis mb-0"
        data-test="personas-empty"
      >
        {{ t('app.strategy.persona.empty', { market: marketName }) }}
      </p>
      <div class="personas-grid" data-test="personas-grid">
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
          <v-icon icon="mdi-plus" size="20" aria-hidden="true" />
          <span data-test="persona-tile-text">
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
      :markets="markets"
      @copied="reload"
    />
    <StrategyConfirmDialog
      v-model="deleteOpen"
      test-id="persona-delete-dialog"
      :title="t('app.strategy.persona.deleteTitle', { name: deleting?.attributes.name ?? '' })"
      :body="
        angleCount > 0
          ? t('app.strategy.persona.deleteBodyAngles', { count: angleCount }, angleCount)
          : t('app.strategy.persona.deleteBody')
      "
      :confirm-label="t('app.strategy.persona.delete')"
      :busy="deleteBusy"
      :error="deleteError"
      @confirm="confirmDelete"
    />
  </section>
</template>

<style scoped>
.personas-step {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.market-tabs {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding-bottom: 4px;
}
.market-tab {
  position: relative;
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.2);
  border-radius: 9999px;
  background: rgb(var(--v-theme-surface));
  color: inherit;
  font: inherit;
  font-size: 14px;
  cursor: pointer;
  white-space: nowrap;
}
.market-tab--active {
  background: rgb(var(--v-theme-primary));
  border-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
}
.market-tab__code {
  font-weight: 700;
}
.market-tab__count {
  font-size: 12px;
  opacity: 0.85;
}
.market-tab__dot {
  width: 8px;
  height: 8px;
  border-radius: 9999px;
  background: rgb(var(--v-theme-warning));
}
.personas-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 340px), 1fr));
  gap: 16px;
  align-items: start;
}
.persona-tile {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 96px;
  padding: 16px;
  border: 2px dashed rgba(var(--v-theme-on-surface), 0.3);
  border-radius: 12px;
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
</style>
