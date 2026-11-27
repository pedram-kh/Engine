<script setup lang="ts">
/**
 * PL-3.2b — the "Script lines" section of the editor (frame 13:3689): the header with "Add line", the lines in order and what a
 * script with no lines says. It owns the structural actions of lines and B-roll (add, duplicate, delete, move, reorder): each is an
 * immediate request whose answer is the whole script. A move shows at once and sends the complete id list; a stale list reloads the
 * order and tells the person. A line that somebody deleted while it held unsaved text stays on screen as a "deleted" card so the
 * text can be copied. Limit text shows only near the limit (from 25 of 30 lines; the B-roll limit shows inside a line).
 */
import type { ScriptLine, WaveScript } from '@catalyst/api-client'
import { ApiError } from '@catalyst/api-client'
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { strategyApi, type WaveScope } from '../api/strategy.api'
import { useBlockDrag } from '../composables/useBlockDrag'
import { useReorder } from '../composables/useReorder'
import { useSaveRegistryRead } from '../composables/useSaveRegistry'
import {
  applyBrollOrder,
  applyLineOrder,
  LINES_NEAR,
  moveBefore,
  moveId,
  nearLimit,
  type LanguageView,
} from '../scriptsLogic'
import { isGone, strategyErrorMessage } from '../strategyErrors'
import ScriptLineCard from './ScriptLineCard.vue'

const props = defineProps<{
  script: WaveScript
  scope: WaveScope
  view: LanguageView
  textMax: number
  brollMax: number
  debounceMs?: number
}>()
const emit = defineEmits<{
  /** A newer copy of the script (an answer, or the optimistic order). */
  replace: [script: WaveScript]
  /** A line block saved: the editor merges what that save changed (its line, the estimate, the usage). */
  lineSaved: [script: WaveScript]
  /** Read the script again from the server. */
  refresh: []
  /** An image was added or removed. */
  imagesChanged: []
  /** The script itself is gone (404). */
  gone: []
}>()
const { t } = useI18n()
const registry = useSaveRegistryRead()

const lines = computed(() => props.script.lines)
const ids = computed(() => lines.value.map((l) => l.id))
const max = computed(() => props.script.limits.lines_per_script)
const full = computed(() => lines.value.length >= max.value)
const near = computed(() => nearLimit(lines.value.length, max.value, LINES_NEAR))
const imageLimits = computed(() => ({
  images: props.script.limits.images_per_block,
  bytes: props.script.limits.image_total_bytes,
}))

const error = ref<string | null>(null)
const notice = ref<string | null>(null)
const announce = ref('')
const adding = ref(false)
const justAdded = ref(new Set<string>())
const deletedByMe = new Set<string>()

// A line that disappears after the person wrote in it (typing, a save that went through, or a failed one) is kept as a deleted card so
// the text can be copied. A line they never touched just goes. Everything here is by the line's id, never its number or place.
const ghosts = ref(new Map<string, { line: ScriptLine; index: number }>())
watch(
  () => props.script.lines,
  (now, before) => {
    const nowIds = new Set(now.map((l) => l.id))
    ;(before ?? []).forEach((l, index) => {
      if (nowIds.has(l.id) || deletedByMe.has(l.id) || ghosts.value.has(l.id)) return
      const status = registry?.statusOf(`line:${l.id}`)
      if (status !== undefined && status !== 'idle') ghosts.value.set(l.id, { line: l, index })
    })
    for (const id of [...ghosts.value.keys()]) if (nowIds.has(id)) ghosts.value.delete(id)
  },
)
const shown = computed(() => {
  const out: { line: ScriptLine; ghost: boolean }[] = lines.value.map((line) => ({
    line,
    ghost: false,
  }))
  const g = [...ghosts.value.values()].sort((a, b) => a.index - b.index)
  for (const { line, index } of g) out.splice(Math.min(index, out.length), 0, { line, ghost: true })
  return out
})

async function structural(call: () => Promise<{ data: WaveScript }>): Promise<string | null> {
  try {
    emit('replace', (await call()).data)
    return null
  } catch (err) {
    if (err instanceof ApiError && err.code === 'wave_script_line.deleted') {
      emit('refresh')
      return t('app.strategy.scripts.lines.goneTitle')
    }
    if (isGone(err)) {
      emit('gone')
      return null
    }
    // A limit or a stale list: the answer says why; the script is read again so the screen is the truth.
    emit('refresh')
    return strategyErrorMessage(err, t)
  }
}

async function addLine(): Promise<void> {
  if (adding.value || full.value) return
  adding.value = true
  error.value = null
  const before = new Set(ids.value)
  const err = await structural(() => strategyApi.addLine(props.scope, props.script.id))
  adding.value = false
  error.value = err
  const fresh = props.script.lines.find((l) => !before.has(l.id))
  if (err === null && fresh !== undefined) {
    justAdded.value = new Set([...justAdded.value, fresh.id])
    await nextTick()
    document
      .querySelector<HTMLElement>(`[data-test="script-line-text-${fresh.id}"] textarea`)
      ?.focus()
  }
}
async function duplicateLine(id: string): Promise<void> {
  error.value = null
  const before = new Set(ids.value)
  error.value = await structural(() => strategyApi.duplicateLine(props.scope, props.script.id, id))
  const fresh = props.script.lines.find((l) => !before.has(l.id))
  if (fresh !== undefined) justAdded.value = new Set([...justAdded.value, fresh.id])
}
function removeLine(id: string): Promise<string | null> {
  deletedByMe.add(id)
  return structural(() => strategyApi.deleteLine(props.scope, props.script.id, id)).then((err) => {
    if (err !== null) deletedByMe.delete(id)
    return err
  })
}

// Reorder ---------------------------------------------------------------------------------------------------------------------
const lineOrder = useReorder({
  optimistic: (next) =>
    emit('replace', { ...props.script, lines: applyLineOrder(props.script.lines, next) }),
  send: async (next) => (await strategyApi.orderLines(props.scope, props.script.id, next)).data,
  confirmed: (s) => emit('replace', s),
  failed: (err) => {
    emit('refresh')
    if (isGone(err)) {
      emit('gone')
      return
    }
    notice.value =
      err instanceof ApiError && err.code === 'wave_script.order_mismatch'
        ? t('app.strategy.scripts.order.changedElsewhere')
        : strategyErrorMessage(err, t)
  },
})
async function focusGrip(id: string): Promise<void> {
  await nextTick()
  document.querySelector<HTMLElement>(`[data-test="script-line-grip-${id}"]`)?.focus()
}
function move(id: string, delta: -1 | 1): void {
  notice.value = null
  const next = moveId(ids.value, id, delta)
  if (next.join() === ids.value.join()) return
  const was = ids.value.indexOf(id)
  lineOrder.request(next)
  announce.value = t('app.strategy.scripts.order.moved', {
    name: t('app.strategy.scripts.lines.line', { n: was + 2 }),
    now: t('app.strategy.scripts.lines.line', { n: next.indexOf(id) + 2 }),
  })
  void focusGrip(id)
}
const drag = useBlockDrag((from, target) => {
  notice.value = null
  const next = moveBefore(ids.value, from, target)
  if (next.join() !== ids.value.join()) lineOrder.request(next)
})

// B-roll ----------------------------------------------------------------------------------------------------------------------
function addBroll(lineId: string): Promise<string | null> {
  return structural(() => strategyApi.addBroll(props.scope, props.script.id, lineId))
}
function removeBroll(lineId: string, brollId: string): Promise<string | null> {
  return structural(() => strategyApi.deleteBroll(props.scope, props.script.id, lineId, brollId))
}
async function moveBroll(lineId: string, brollId: string, delta: -1 | 1): Promise<string | null> {
  const line = props.script.lines.find((l) => l.id === lineId)
  if (line === undefined) return null
  const next = moveId(
    line.broll.map((r) => r.id),
    brollId,
    delta,
  )
  // Shown at once, confirmed by the answer.
  emit('replace', {
    ...props.script,
    lines: props.script.lines.map((l) => (l.id === lineId ? applyBrollOrder(l, next) : l)),
  })
  return structural(() => strategyApi.orderBroll(props.scope, props.script.id, lineId, next))
}

function dismissGhost(id: string): void {
  ghosts.value.delete(id)
  // A card that found out it is gone on its own save (the list was not refreshed) is still in the list: take it out for good.
  if (props.script.lines.some((l) => l.id === id)) {
    deletedByMe.add(id)
    emit('refresh')
  }
}
function startsOpen(id: string): boolean {
  return justAdded.value.has(id) || lines.value.length <= 3
}
</script>

<template>
  <section class="lsec" data-test="script-lines-section">
    <div class="lsec__head">
      <h3 class="lsec__title">{{ t('app.strategy.scripts.lines.title') }}</h3>
      <div class="lsec__tools">
        <span v-if="near" class="lsec__near" data-test="script-lines-usage">{{
          t('app.strategy.scripts.lines.usage', { n: lines.length, max })
        }}</span>
        <v-btn
          variant="outlined"
          class="lsec__add"
          size="small"
          prepend-icon="mdi-plus"
          :disabled="full || adding"
          :loading="adding"
          data-test="script-add-line"
          @click="addLine"
          >{{
            full ? t('app.strategy.scripts.lines.limit') : t('app.strategy.scripts.lines.add')
          }}</v-btn
        >
      </div>
    </div>

    <v-alert
      v-if="error !== null"
      type="error"
      variant="tonal"
      density="compact"
      closable
      data-test="script-lines-error"
      @click:close="error = null"
      >{{ error }}</v-alert
    >
    <v-alert
      v-if="notice !== null"
      type="info"
      variant="tonal"
      density="compact"
      closable
      data-test="script-lines-notice"
      @click:close="notice = null"
      >{{ notice }}</v-alert
    >

    <p v-if="shown.length === 0" class="lsec__empty" data-test="script-lines-empty">
      {{ t('app.strategy.scripts.lines.noLines') }}
    </p>
    <div v-else class="lsec__list" data-test="script-lines">
      <ScriptLineCard
        v-for="(row, i) in shown"
        :key="row.line.id"
        :line="row.line"
        :script-id="script.id"
        :scope="scope"
        :languages="script.languages"
        :view="view"
        :index="i"
        :count="shown.length"
        :initially-open="startsOpen(row.line.id)"
        :text-max="textMax"
        :broll-max="brollMax"
        :can-add-line="!full"
        :image-limits="imageLimits"
        :script-bytes="script.usage.image_bytes"
        :dragging="drag.dragging.value"
        :over="drag.over.value"
        :ghost="row.ghost"
        :remove-fn="() => removeLine(row.line.id)"
        :add-broll-fn="() => addBroll(row.line.id)"
        :remove-broll-fn="(brollId: string) => removeBroll(row.line.id, brollId)"
        :move-broll-fn="(brollId: string, delta: -1 | 1) => moveBroll(row.line.id, brollId, delta)"
        :debounce-ms="debounceMs"
        @saved="(s: WaveScript) => emit('lineSaved', s)"
        @duplicate="duplicateLine(row.line.id)"
        @move="(delta: -1 | 1) => move(row.line.id, delta)"
        @dragstart="(e: DragEvent) => drag.start(row.line.id, e)"
        @dragover="(e: DragEvent) => drag.hover(row.line.id, e)"
        @drop="(e: DragEvent) => drag.drop(row.line.id, e)"
        @dragend="drag.end"
        @images-changed="emit('imagesChanged')"
        @dismiss="dismissGhost(row.line.id)"
      />
    </div>
    <p class="lsec__live" role="status" aria-live="polite" data-test="script-lines-live">
      {{ announce }}
    </p>
  </section>
</template>

<style scoped>
.lsec {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.lsec__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding-top: 6px;
}
.lsec__title {
  margin: 0;
  font-size: 17px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
}
.lsec__tools {
  display: flex;
  align-items: center;
  gap: 12px;
}
.lsec__near {
  font-size: 12px;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.lsec__add {
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
.lsec__add :deep(.v-btn__prepend) {
  margin-inline: 0 8px;
}
.lsec__add :deep(.v-icon) {
  font-size: 14px;
  width: 14px;
  height: 14px;
}
.lsec__empty {
  margin: 0;
  padding: 16px;
  border: 1px dashed rgb(var(--v-theme-border-color));
  border-radius: 10px;
  font-size: 13px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.lsec__list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.lsec__live {
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
</style>
