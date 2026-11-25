<script setup lang="ts">
/**
 * PL-3.2a — step 6, Scripts (frame 13:3108): one tab per market (`?market=`), then three columns: the rail of creators and their
 * scripts (240 px), the editor of the selected script (`?script=`) and the Notes panel (300 px). Under 1100 px the rail becomes a
 * row above the editor and the notes open in a side sheet. The rail is read on open and after every change; the editor loads its
 * own script. The editor's blocks autosave; this page keeps the leave guard: moving to another script, market, step or page asks
 * the editor to save first, and only when something still cannot be saved does a dialog let the person stay, retry or leave.
 */
import type { ScriptsOverview, StrategySummaryResource } from '@catalyst/api-client'
import { CEmptyState } from '@catalyst/ui'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import { strategyApi } from '../api/strategy.api'
import { useStrategyContext } from '../composables/useStrategyContext'
import { strategyErrorMessage } from '../strategyErrors'
import { provideMarkets } from '../useMarkets'
import ArchivedScriptsDialog from './ArchivedScriptsDialog.vue'
import MarketTabs from './MarketTabs.vue'
import NotesPanel from './NotesPanel.vue'
import NotesSheet from './NotesSheet.vue'
import ScriptEditor from './ScriptEditor.vue'
import ScriptsLeaveDialog from './ScriptsLeaveDialog.vue'
import ScriptsRail from './ScriptsRail.vue'

const props = defineProps<{ summary: StrategySummaryResource }>()
const emit = defineEmits<{ changed: [] }>()

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { brandId, waveId, scope, canDecide } = useStrategyContext()
const dir = provideMarkets(() => props.summary)

const overview = ref<ScriptsOverview | null>(null)
const loading = ref(true)
const failed = ref(false)
const pageError = ref<string | null>(null)
const busySlot = ref<string | null>(null)
let ticket = 0

const market = computed(() => {
  const q = route.query.market
  const wanted = typeof q === 'string' ? q : null
  return wanted !== null && dir.ids.value.includes(wanted) ? wanted : (dir.ids.value[0] ?? '')
})
const marketName = computed(() => dir.name(market.value))
// The rail on screen is the rail of THIS market; while another market's is still on the way the page shows a skeleton.
const rail = computed(() => {
  const r = overview.value?.rail ?? null
  return r !== null && r.market === market.value ? r : null
})
const max = computed(() => overview.value?.limits.scripts_per_creator ?? 3)
const hooksMax = computed(() => overview.value?.limits.hooks_per_script ?? 6)
const allScripts = computed(() => rail.value?.creators.flatMap((c) => c.scripts) ?? [])
const selectedId = computed<string | null>(() => {
  const q = route.query.script
  if (typeof q === 'string' && allScripts.value.some((s) => s.id === q)) return q
  return allScripts.value[0]?.id ?? null
})
const archivedCount = computed(
  () => overview.value?.markets.find((m) => m.id === market.value)?.archived_scripts ?? 0,
)
const noteCount = computed(() => rail.value?.notes ?? 0)

function tabCount(id: string): number {
  return overview.value?.markets.find((m) => m.id === id)?.scripts ?? 0
}

async function load(): Promise<void> {
  const s = scope.value
  if (s === null || market.value === '') {
    loading.value = false
    return
  }
  const mine = ++ticket
  try {
    const res = await strategyApi.scripts(s, market.value)
    if (mine !== ticket) return
    overview.value = res.data
    failed.value = false
  } catch {
    if (mine === ticket) failed.value = true
  } finally {
    if (mine === ticket) loading.value = false
  }
}
async function reload(): Promise<void> {
  await load()
  emit('changed')
}

onMounted(load)
watch(market, () => {
  pageError.value = null
  noteDraft.value = ''
  expanded.value = false
  notesSheet.value = false
  loading.value = true
  void load()
})

function link(name: string): {
  name: string
  params: Record<string, string>
  query: Record<string, string>
} {
  return { name, params: { ulid: brandId, waveUlid: waveId }, query: { market: market.value } }
}
const matrixTo = computed(() => link('strategy.matrix'))
const creatorsTo = computed(() => link('strategy.creators'))

function selectMarket(code: string): void {
  if (code === market.value) return
  void router.replace({ query: { market: code } })
}
function selectScript(id: string): void {
  if (id === selectedId.value) return
  void router.replace({ query: { market: market.value, script: id } })
}

async function addScript(slot: string): Promise<void> {
  const s = scope.value
  if (s === null || busySlot.value !== null) return
  busySlot.value = slot
  pageError.value = null
  try {
    const created = (await strategyApi.createScript(s, slot)).data
    await reload()
    await router.replace({ query: { market: market.value, script: created.id } })
  } catch (err) {
    pageError.value = strategyErrorMessage(err, t)
    await reload()
  } finally {
    busySlot.value = null
  }
}

// The editor's script is gone (archived here, or removed by someone else): the rail is the truth, the guard must not ask.
let bypass = false
async function afterRemoved(): Promise<void> {
  bypass = true
  try {
    await reload()
    await router.replace({ query: { market: market.value } })
  } finally {
    bypass = false
  }
}

// Archived dialog ------------------------------------------------------------------------------------------------
const archivedOpen = ref(false)

// The leave guard ------------------------------------------------------------------------------------------------
const editorRef = ref<{ unsaved: boolean; flushAll: () => Promise<boolean> } | null>(null)
const leaveOpen = ref(false)
const leaveBusy = ref(false)
let leaveResolve: ((ok: boolean) => void) | null = null
function askLeave(): Promise<boolean> {
  return new Promise((resolve) => {
    leaveResolve = resolve
    leaveOpen.value = true
  })
}
function settle(ok: boolean): void {
  leaveOpen.value = false
  const done = leaveResolve
  leaveResolve = null
  done?.(ok)
}
async function leaveRetry(): Promise<void> {
  leaveBusy.value = true
  const ok = (await editorRef.value?.flushAll()) ?? true
  leaveBusy.value = false
  if (ok) settle(true)
}
const removeGuard = router.beforeEach(async (_to, from) => {
  if (from.name !== 'strategy.scripts' || bypass) return true
  const editor = editorRef.value
  if (editor === null || !editor.unsaved) return true
  if (await editor.flushAll()) return true
  return askLeave()
})
onBeforeUnmount(() => {
  removeGuard()
  settle(true)
})

// Notes ----------------------------------------------------------------------------------------------------------
const noteDraft = ref('')
const expanded = ref(false)
const notesSheet = ref(false)
const panelRef = ref<{ focusExpand: () => void } | null>(null)
const notesBtn = ref<{ $el: HTMLElement } | null>(null)
// jsdom has no matchMedia: the wide layout is the default there.
const mq = typeof window.matchMedia === 'function' ? window.matchMedia('(min-width: 1100px)') : null
const wide = ref(mq?.matches ?? true)
const onMq = (e: MediaQueryListEvent): void => {
  wide.value = e.matches
  if (e.matches) notesSheet.value = false
}
mq?.addEventListener('change', onMq)
onBeforeUnmount(() => mq?.removeEventListener('change', onMq))
watch(expanded, async (open, was) => {
  if (was && !open) {
    await nextTick()
    panelRef.value?.focusExpand()
  }
})
watch(notesSheet, async (open, was) => {
  if (was && !open) {
    await nextTick()
    notesBtn.value?.$el.focus()
  }
})
</script>

<template>
  <section class="sstep" data-test="scripts-step">
    <MarketTabs :markets="dir.list.value" :active="market" @select="selectMarket">
      <template #extra="{ market: m }">
        <span class="market-tab__count" :data-test="`market-count-${m.code}`">{{
          tabCount(m.id)
        }}</span>
      </template>
    </MarketTabs>

    <v-skeleton-loader v-if="loading" type="article" data-test="scripts-skeleton" />
    <v-alert
      v-else-if="failed || rail === null"
      type="error"
      variant="tonal"
      data-test="scripts-load-error"
    >
      {{ t('app.strategy.errors.loadFailed') }}
      <template #append>
        <v-btn size="small" variant="outlined" data-test="scripts-reload" @click="reload">{{
          t('app.strategy.scripts.save.retry')
        }}</v-btn>
      </template>
    </v-alert>

    <template v-else>
      <v-alert
        v-if="pageError !== null"
        type="error"
        variant="tonal"
        density="compact"
        closable
        data-test="scripts-error"
        @click:close="pageError = null"
        >{{ pageError }}</v-alert
      >

      <CEmptyState
        v-if="rail.creators.length === 0"
        class="agency-card"
        data-test="scripts-no-creators"
        title-tag="h3"
        :title="t('app.strategy.scripts.empty.noCreators.title', { market: marketName })"
        :body="t('app.strategy.scripts.empty.noCreators.body')"
      >
        <template #icon>
          <v-icon icon="mdi-account-multiple-plus-outline" size="48" aria-hidden="true" />
        </template>
        <template #action>
          <v-btn color="primary" variant="flat" :to="creatorsTo" data-test="scripts-to-creators">{{
            t('app.strategy.scripts.empty.noCreators.action')
          }}</v-btn>
        </template>
      </CEmptyState>

      <div v-else class="sstep__grid" :class="{ 'sstep__grid--notes': wide }">
        <ScriptsRail
          :rail="rail"
          :market-name="marketName"
          :selected-id="selectedId"
          :archived-count="archivedCount"
          :busy-slot="busySlot"
          :max="max"
          @select="selectScript"
          @add="addScript"
          @archived="archivedOpen = true"
        />

        <div class="sstep__main">
          <div v-if="!wide" class="sstep__notes-btn">
            <v-btn
              ref="notesBtn"
              variant="outlined"
              size="small"
              prepend-icon="mdi-note-text-outline"
              data-test="scripts-notes-open"
              @click="notesSheet = true"
              >{{ t('app.strategy.notes.open', { count: noteCount }) }}</v-btn
            >
          </div>
          <ScriptEditor
            v-if="selectedId !== null && scope !== null"
            :key="selectedId"
            ref="editorRef"
            :scope="scope"
            :script-id="selectedId"
            :hooks-max="hooksMax"
            :matrix-to="matrixTo"
            :creators-to="creatorsTo"
            @archived="afterRemoved"
            @gone="afterRemoved"
          />
          <CEmptyState
            v-else
            class="agency-card"
            data-test="scripts-none"
            title-tag="h3"
            :title="t('app.strategy.scripts.empty.noScripts.title')"
            :body="t('app.strategy.scripts.empty.noScripts.body')"
          >
            <template #icon>
              <v-icon icon="mdi-script-text-outline" size="48" aria-hidden="true" />
            </template>
          </CEmptyState>
        </div>

        <div v-if="wide" class="sstep__notes">
          <NotesPanel
            ref="panelRef"
            :key="`scripts:${market}`"
            v-model:draft="noteDraft"
            step="scripts"
            :market="market"
            :expanded="expanded"
            @expand="expanded = true"
            @changed="reload"
          />
        </div>
      </div>
    </template>

    <NotesSheet
      v-if="wide && expanded"
      v-model="expanded"
      v-model:draft="noteDraft"
      from-panel
      step="scripts"
      :market="market"
      :market-name="marketName"
      @changed="reload"
    />
    <NotesSheet
      v-if="!wide"
      v-model="notesSheet"
      v-model:draft="noteDraft"
      step="scripts"
      :market="market"
      :market-name="marketName"
      @changed="reload"
    />

    <ArchivedScriptsDialog
      v-if="scope !== null && market !== ''"
      v-model="archivedOpen"
      :scope="scope"
      :market="market"
      :market-name="marketName"
      :can-purge="canDecide"
      :max="max"
      @changed="reload"
    />
    <ScriptsLeaveDialog
      :model-value="leaveOpen"
      :busy="leaveBusy"
      @stay="settle(false)"
      @retry="leaveRetry"
      @leave="settle(true)"
    />
  </section>
</template>

<style scoped>
.sstep {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}
.sstep__grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 24px;
  align-items: start;
}
@media (min-width: 1100px) {
  .sstep__grid {
    grid-template-columns: 240px minmax(0, 1fr);
  }
  .sstep__grid--notes {
    grid-template-columns: 240px minmax(0, 1fr) 300px;
  }
}
.sstep__main,
.sstep__notes {
  min-width: 0;
}
.sstep__main {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.sstep__notes {
  align-self: stretch;
}
.sstep__notes-btn {
  display: flex;
  justify-content: flex-end;
}
</style>
