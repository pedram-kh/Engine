<script setup lang="ts">
/**
 * PL-3.2a, PL-3.2b — the editor of one script (frame 13:3108, the middle column): the title row, the workflow bar (the read-only
 * "Draft" pill until PL-3.3, and the language control), the brief, the hooks and the lines. The editor holds the script and takes
 * every answer into it: a block save merges only what it changed (a late answer never puts older text back), a structural answer
 * (add, delete, order) replaces the script. It loads the script it is given and is re-created (keyed) for another script, after the page flushed the old one.
 * Every block autosaves on its own; the chip in the title row is the worst state of all of them.
 */
import type { ScriptHook, ScriptsOverview, WaveScript } from '@catalyst/api-client'
import { ApiError, SCRIPT_TEXT_LIMITS } from '@catalyst/api-client'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { creatorLabel } from '@/core/creators/creatorLabel'
import { brandColorVar } from '@/modules/agency/composables/brandColor'
import { strategyApi, type WaveScope } from '../api/strategy.api'
import { useBlockDrag } from '../composables/useBlockDrag'
import { useReorder } from '../composables/useReorder'
import { provideSaveRegistry } from '../composables/useSaveRegistry'
import {
  allImages,
  applyHookOrder,
  localLanguages,
  moveBefore,
  moveId,
  normalizeView,
  type LanguageView,
} from '../scriptsLogic'
import { isGone, strategyErrorMessage } from '../strategyErrors'
import ScriptBrief from './ScriptBrief.vue'
import ScriptHookCard from './ScriptHookCard.vue'
import ScriptLanguageToggle from './ScriptLanguageToggle.vue'
import ScriptLinesSection from './ScriptLinesSection.vue'
import StrategyConfirmDialog from './StrategyConfirmDialog.vue'

type Route = { name: string; params: Record<string, string>; query: Record<string, string> }

const props = defineProps<{
  scope: WaveScope
  scriptId: string
  hooksMax: number
  matrixTo: Route
  creatorsTo: Route
  debounceMs?: number
}>()
const emit = defineEmits<{
  /** The script was archived (the answer is the new overview of the wave). */
  archived: [overview: ScriptsOverview]
  /** Somebody else removed it (404). */
  gone: []
}>()
const { t, locale } = useI18n()

const registry = provideSaveRegistry()
const script = ref<WaveScript | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)
const actionError = ref<string | null>(null)
const addingHook = ref(false)

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    script.value = (await strategyApi.script(props.scope, props.scriptId)).data
  } catch (err) {
    if (isGone(err)) {
      emit('gone')
      return
    }
    loadError.value = strategyErrorMessage(err, t)
  } finally {
    loading.value = false
  }
}
onMounted(load)

/** Reads the script again WITHOUT the skeleton (an image changed, a stale order, a language change): what a person is typing stays. */
async function refresh(): Promise<void> {
  try {
    script.value = (await strategyApi.script(props.scope, props.scriptId)).data
  } catch (err) {
    if (isGone(err)) emit('gone')
  }
}

// The language view: one language, or English beside one local language. It follows the script's languages when they change.
const wanted = ref<LanguageView | null>(null)
const view = computed<LanguageView>(() => {
  const langs = script.value?.languages ?? ['en']
  const local = localLanguages(langs)
  const base: LanguageView =
    wanted.value ??
    (local[0] !== undefined
      ? { mode: 'side', local: local[0] }
      : { mode: 'single', language: 'en' })
  return normalizeView(base, langs)
})

// Only what a save changed is merged in: a late answer must never put an older hook list or brief back.
function onBriefSaved(s: WaveScript): void {
  const cur = script.value
  if (cur === null) return
  script.value = {
    ...cur,
    brief: s.brief,
    estimate: s.estimate,
    updated_at: s.updated_at,
    revision: s.revision,
  }
}
function onHookSaved(s: WaveScript): void {
  const cur = script.value
  if (cur === null) return
  const byId = new Map(s.hooks.map((h) => [h.id, h]))
  script.value = {
    ...cur,
    hooks: cur.hooks.map((h) => {
      const next = byId.get(h.id)
      return next !== undefined && next.revision > h.revision ? next : h
    }),
    estimate: s.estimate,
    usage: s.usage,
    updated_at: s.updated_at,
    revision: s.revision,
  }
}
function onLineSaved(s: WaveScript): void {
  const cur = script.value
  if (cur === null) return
  const byId = new Map(s.lines.map((l) => [l.id, l]))
  script.value = {
    ...cur,
    lines: cur.lines.map((l) => {
      const next = byId.get(l.id)
      return next !== undefined && next.revision > l.revision ? next : l
    }),
    estimate: s.estimate,
    usage: s.usage,
    updated_at: s.updated_at,
    revision: s.revision,
  }
}
function replaceScript(s: WaveScript): void {
  script.value = s
}

// Hooks: reorder by drag, keyboard and menu. A move shows at once and sends the whole list; a stale list reloads the order.
const hookNotice = ref<string | null>(null)
const hookLive = ref('')
const hookOrder = useReorder({
  optimistic: (ids) => {
    if (script.value !== null)
      script.value = { ...script.value, hooks: applyHookOrder(script.value.hooks, ids) }
  },
  send: async (ids) => (await strategyApi.orderHooks(props.scope, props.scriptId, ids)).data,
  confirmed: (s) => (script.value = s),
  failed: (err) => {
    void refresh()
    hookNotice.value =
      err instanceof ApiError && err.code === 'wave_script.order_mismatch'
        ? t('app.strategy.scripts.order.changedElsewhere')
        : strategyErrorMessage(err, t)
  },
})
function moveHook(id: string, delta: -1 | 1): void {
  if (script.value === null) return
  hookNotice.value = null
  const ids = script.value.hooks.map((h) => h.id)
  const next = moveId(ids, id, delta)
  if (next.join() === ids.join()) return
  hookOrder.request(next)
  const at = next.indexOf(id)
  hookLive.value = t('app.strategy.scripts.order.moved', {
    name: t('app.strategy.scripts.hooks.hook', {
      letter: String.fromCharCode(65 + ids.indexOf(id)),
    }),
    now: t('app.strategy.scripts.hooks.hook', { letter: String.fromCharCode(65 + at) }),
  })
  void nextTick().then(() =>
    document.querySelector<HTMLElement>(`[data-test="script-hook-grip-${id}"]`)?.focus(),
  )
}
// A hook that disappears after the person wrote in it stays as a deleted card (its text can be copied). By the hook's id, never its letter.
const hookDeletedByMe = new Set<string>()
const hookGhosts = ref(new Map<string, { hook: ScriptHook; index: number }>())
watch(
  () => script.value?.hooks,
  (now, before) => {
    const nowIds = new Set((now ?? []).map((h) => h.id))
    ;(before ?? []).forEach((h, index) => {
      if (nowIds.has(h.id) || hookDeletedByMe.has(h.id) || hookGhosts.value.has(h.id)) return
      const status = registry.statusOf(`hook:${h.id}`)
      if (status !== undefined && status !== 'idle') hookGhosts.value.set(h.id, { hook: h, index })
    })
    for (const id of [...hookGhosts.value.keys()]) if (nowIds.has(id)) hookGhosts.value.delete(id)
  },
)
const shownHooks = computed(() => {
  const out: { hook: ScriptHook; ghost: boolean }[] = (script.value?.hooks ?? []).map((hook) => ({
    hook,
    ghost: false,
  }))
  const g = [...hookGhosts.value.values()].sort((a, b) => a.index - b.index)
  for (const { hook, index } of g) out.splice(Math.min(index, out.length), 0, { hook, ghost: true })
  return out
})
function dismissHookGhost(id: string): void {
  hookGhosts.value.delete(id)
  // A card that learned it is gone on its own save is still in the list: take it out for good.
  if (script.value?.hooks.some((h) => h.id === id)) {
    hookDeletedByMe.add(id)
    void refresh()
  }
}
const hookDrag = useBlockDrag((from, target) => {
  if (script.value === null) return
  hookNotice.value = null
  const ids = script.value.hooks.map((h) => h.id)
  const next = moveBefore(ids, from, target)
  if (next.join() !== ids.join()) hookOrder.request(next)
})

async function structural(call: () => Promise<{ data: WaveScript }>): Promise<string | null> {
  actionError.value = null
  try {
    script.value = (await call()).data
    return null
  } catch (err) {
    if (isGone(err)) {
      emit('gone')
      return null
    }
    // A hook somebody deleted: the list is read again so the screen is the truth.
    if (err instanceof ApiError && err.code === 'wave_script_hook.deleted') void refresh()
    return strategyErrorMessage(err, t)
  }
}
async function addHook(): Promise<void> {
  if (addingHook.value) return
  addingHook.value = true
  actionError.value = await structural(() => strategyApi.addHook(props.scope, props.scriptId))
  addingHook.value = false
}
async function duplicateHook(hookId: string): Promise<void> {
  actionError.value = await structural(() =>
    strategyApi.duplicateHook(props.scope, props.scriptId, hookId),
  )
}
async function removeHook(hookId: string): Promise<string | null> {
  hookDeletedByMe.add(hookId)
  const err = await structural(() => strategyApi.deleteHook(props.scope, props.scriptId, hookId))
  if (err !== null) hookDeletedByMe.delete(hookId)
  return err
}

// Archive the script (every agency role): it moves to the archived list with its hooks and texts, and can be restored there.
const archiveOpen = ref(false)
const archiving = ref(false)
const archiveError = ref<string | null>(null)
async function doArchive(): Promise<void> {
  if (archiving.value) return
  archiving.value = true
  archiveError.value = null
  try {
    const res = await strategyApi.archiveScript(props.scope, props.scriptId)
    archiveOpen.value = false
    emit('archived', res.data)
  } catch (err) {
    if (isGone(err)) {
      archiveOpen.value = false
      emit('gone')
    } else archiveError.value = strategyErrorMessage(err, t)
  } finally {
    archiving.value = false
  }
}
function askArchive(): void {
  archiveError.value = null
  archiveOpen.value = true
}

const chip = computed(() => registry.state.value)
const lastEdited = computed(() => {
  const at = script.value?.updated_at
  if (at === undefined) return ''
  return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(
    new Date(at),
  )
})
const slotCode = computed(() => script.value?.slot?.slot_code ?? null)
const personaColor = computed(() => {
  const p = script.value?.brief.persona
  return p ? brandColorVar(p.color_key) : 'rgb(var(--v-theme-on-surface-variant))'
})
const creatorName = computed(() =>
  script.value === null
    ? ''
    : creatorLabel(script.value.creator, t, t('app.strategy.creators.card.unnamed')),
)

// A page that is being left or a script that is being switched asks the editor.
function beforeUnload(e: BeforeUnloadEvent): void {
  if (registry.unsaved.value) {
    e.preventDefault()
    e.returnValue = ''
  }
}
onMounted(() => window.addEventListener('beforeunload', beforeUnload))
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload))

defineExpose({ unsaved: registry.unsaved, flushAll: registry.flushAll, refresh })
</script>

<template>
  <div class="sed" data-test="script-editor" :data-script="scriptId">
    <v-skeleton-loader v-if="loading" type="article" data-test="script-editor-loading" />
    <div
      v-else-if="loadError !== null || script === null"
      class="sed__error"
      role="alert"
      data-test="script-editor-error"
    >
      <p>{{ loadError ?? t('app.strategy.scripts.editor.loadFailed') }}</p>
      <v-btn size="small" variant="outlined" data-test="script-editor-retry" @click="load">{{
        t('app.strategy.scripts.save.retry')
      }}</v-btn>
    </div>
    <template v-else>
      <header class="sed__head">
        <div class="sed__who">
          <span class="sed__chip" :style="{ '--chip': personaColor }" data-test="script-slot">{{
            slotCode ?? '—'
          }}</span>
          <div class="sed__titles">
            <h2 class="sed__title" data-test="script-title">
              {{ t('app.strategy.scripts.editor.title', { name: creatorName, n: script.number }) }}
            </h2>
            <p class="sed__edited" data-test="script-last-edited">
              {{ t('app.strategy.scripts.editor.lastEdited', { when: lastEdited }) }}
            </p>
          </div>
        </div>
        <div class="sed__tools">
          <ScriptLanguageToggle
            :languages="script.languages"
            :view="view"
            @update:view="(v: LanguageView) => (wanted = v)"
          />
          <span
            class="sed__save"
            :class="`sed__save--${chip.kind}`"
            :role="chip.kind === 'stuck' ? 'alert' : 'status'"
            :data-state="chip.kind"
            data-test="script-save-chip"
          >
            <template v-if="chip.kind === 'saving'">{{
              t('app.strategy.scripts.save.saving')
            }}</template>
            <template v-else-if="chip.kind === 'stuck'">{{
              t('app.strategy.scripts.save.stuck', { n: chip.stuck }, chip.stuck)
            }}</template>
            <template v-else
              ><v-icon icon="mdi-check" size="13" aria-hidden="true" />{{
                t('app.strategy.scripts.save.allSaved')
              }}</template
            >
          </span>
          <v-btn
            variant="text"
            size="small"
            prepend-icon="mdi-archive-outline"
            data-test="script-archive"
            @click="askArchive"
            >{{ t('app.strategy.scripts.editor.archive') }}</v-btn
          >
        </div>
      </header>

      <div class="sed__flow" data-test="script-workflow">
        <span class="sed__pill" data-test="script-status-pill">{{
          t('app.strategy.scripts.rail.draft')
        }}</span>
      </div>

      <v-alert
        v-if="actionError !== null"
        type="error"
        variant="tonal"
        density="compact"
        closable
        data-test="script-action-error"
        @click:close="actionError = null"
        >{{ actionError }}</v-alert
      >

      <ScriptBrief
        :script="script"
        :scope="scope"
        :matrix-to="matrixTo"
        :creators-to="creatorsTo"
        :slot-code="slotCode"
        :angle-max="SCRIPT_TEXT_LIMITS.angle"
        :debounce-ms="debounceMs"
        @saved="onBriefSaved"
      />

      <div class="sed__section">
        <h3 class="sed__section-title">{{ t('app.strategy.scripts.hooks.title') }}</h3>
        <v-btn
          variant="outlined"
          class="sed__add"
          size="small"
          prepend-icon="mdi-plus"
          :disabled="script.hooks.length >= hooksMax || addingHook"
          :loading="addingHook"
          data-test="script-add-hook"
          @click="addHook"
          >{{
            script.hooks.length >= hooksMax
              ? t('app.strategy.scripts.hooks.limit', { max: hooksMax })
              : t('app.strategy.scripts.hooks.add', { n: script.hooks.length, max: hooksMax })
          }}</v-btn
        >
      </div>

      <div class="sed__hooks" data-test="script-hooks">
        <ScriptHookCard
          v-for="(row, i) in shownHooks"
          :key="row.hook.id"
          :hook="row.hook"
          :script-id="script.id"
          :scope="scope"
          :can-delete="script.hooks.length > 1"
          :can-add="script.hooks.length < hooksMax"
          :limits="SCRIPT_TEXT_LIMITS"
          :languages="script.languages"
          :view="view"
          :index="i"
          :count="script.hooks.length"
          :image-limits="{
            images: script.limits.images_per_block,
            bytes: script.limits.image_total_bytes,
          }"
          :script-bytes="script.usage.image_bytes"
          :dragging="hookDrag.dragging.value"
          :over="hookDrag.over.value"
          :remove-fn="() => removeHook(row.hook.id)"
          :ghost="row.ghost"
          :debounce-ms="debounceMs"
          @saved="onHookSaved"
          @dismiss="dismissHookGhost(row.hook.id)"
          @duplicate="duplicateHook(row.hook.id)"
          @move="(d: -1 | 1) => moveHook(row.hook.id, d)"
          @dragstart="(e: DragEvent) => hookDrag.start(row.hook.id, e)"
          @dragover="(e: DragEvent) => hookDrag.hover(row.hook.id, e)"
          @drop="(e: DragEvent) => hookDrag.drop(row.hook.id, e)"
          @dragend="hookDrag.end"
          @images-changed="refresh"
        />
      </div>
      <v-alert
        v-if="hookNotice !== null"
        type="info"
        variant="tonal"
        density="compact"
        closable
        data-test="script-hooks-notice"
        @click:close="hookNotice = null"
        >{{ hookNotice }}</v-alert
      >
      <p class="sed__live" role="status" aria-live="polite" data-test="script-hooks-live">
        {{ hookLive }}
      </p>

      <ScriptLinesSection
        :script="script"
        :scope="scope"
        :view="view"
        :text-max="SCRIPT_TEXT_LIMITS.line"
        :broll-max="SCRIPT_TEXT_LIMITS.broll"
        :debounce-ms="debounceMs"
        @replace="replaceScript"
        @line-saved="onLineSaved"
        @refresh="refresh"
        @images-changed="refresh"
        @gone="emit('gone')"
      />

      <StrategyConfirmDialog
        v-model="archiveOpen"
        :title="
          t('app.strategy.scripts.editor.archiveTitle', { name: creatorName, n: script.number })
        "
        :body="
          t('app.strategy.scripts.editor.archiveBodyFull', {
            hooks: script.hooks.length,
            lines: script.lines.length,
            images: allImages(script).length,
          })
        "
        :confirm-label="t('app.strategy.scripts.editor.archiveConfirm')"
        :busy="archiving"
        :error="archiveError"
        confirm-color="primary"
        test-id="script-archive-dialog"
        @confirm="doArchive"
      />
    </template>
  </div>
</template>

<style scoped>
.sed {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}
.sed__error {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 10px;
  color: rgb(var(--v-theme-error));
}
.sed__error p {
  margin: 0;
}
.sed__head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px 16px;
}
/* With three languages the tools are wide: the title keeps a readable width and the tools drop below it instead of squeezing it. */
.sed__who {
  display: flex;
  align-items: center;
  flex: 1 1 320px;
  gap: 8px;
  min-width: 0;
}
.sed__chip {
  flex: none;
  padding: 2px 6px;
  border: 1px solid color-mix(in srgb, var(--chip) 70%, transparent);
  border-radius: 4px;
  font-size: 11px;
  font-weight: 700;
  line-height: normal;
  color: var(--chip);
}
.sed__titles {
  min-width: 0;
}
.sed__title {
  margin: 0;
  font-size: 20px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
  overflow-wrap: anywhere;
}
.sed__edited {
  margin: 4px 0 0;
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.sed__tools {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
.sed__save {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.sed__save--stuck {
  color: rgb(var(--v-theme-error));
  font-weight: 600;
}
.sed__flow {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  padding: 10px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
}
.sed__pill {
  padding: 5px 6px;
  border-radius: 9999px;
  background: rgb(var(--v-theme-surface-bright));
  font-size: 12px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
}
.sed__hooks {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.sed__live {
  position: absolute;
  width: 1px;
  height: 1px;
  margin: -1px;
  padding: 0;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
  border: 0;
}
.sed__section {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding-top: 6px;
}
.sed__section-title {
  margin: 0;
  font-size: 17px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
}
.sed__add {
  flex: none;
  height: auto !important;
  padding: 6px 12px !important;
  border-color: rgb(var(--v-theme-border-color)) !important;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 500;
  line-height: normal;
  text-transform: none;
  letter-spacing: normal;
}
.sed__add :deep(.v-btn__prepend) {
  margin-inline: 0 8px;
}
.sed__add :deep(.v-icon) {
  font-size: 14px;
  width: 14px;
  height: 14px;
}
@media (max-width: 599px) {
  .sed__head {
    flex-direction: column;
    align-items: flex-start;
  }
  .sed__tools {
    flex-wrap: wrap;
  }
}
</style>
