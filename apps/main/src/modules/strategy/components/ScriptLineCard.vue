<script setup lang="ts">
/**
 * PL-3.2b — one script line (frame 13:3108, nodes 13:3696, 13:3803, 13:3916, 13:3936), open and collapsed in one component. Open: a
 * header (grip, "Line N", the CTA chip, the status and a menu: duplicate, move up, move down, delete), the spoken text in the
 * languages of the view, the B-roll shots (code, shot type, English description; add, delete, move within the line), the CTA flag
 * and the reference images. Collapsed: the quoted text, "Shots: n", an image count chip. The line is its OWN save block: its texts, CTA
 * flag and the fields of its B-roll rows autosave together with the line's revision; adding, deleting, moving and the images are
 * separate requests. A line somebody deleted while it was open keeps the person's text on screen (a message, Copy my text, Close).
 */
import type { ScriptImage, ScriptLine, ShotType, WaveScript } from '@catalyst/api-client'
import { SHOT_TYPES } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { strategyApi, type WaveScope } from '../api/strategy.api'
import { useBlockAutosave } from '../composables/useBlockAutosave'
import { useRegisterBlock } from '../composables/useSaveRegistry'
import { MENU_PROPS } from '../constants'
import {
  BROLL_NEAR,
  linePayload,
  lineValue,
  nearLimit,
  quoteOf,
  reconcileLine,
  sameLine,
  viewLanguages,
  type LanguageView,
  type LineValue,
} from '../scriptsLogic'
import AutoGrowTextarea from './AutoGrowTextarea.vue'
import BlockConflict from './BlockConflict.vue'
import BlockGrip from './BlockGrip.vue'
import BlockStatusLine from './BlockStatusLine.vue'
import ScriptImageField from './ScriptImageField.vue'
import ScriptTextColumns from './ScriptTextColumns.vue'
import StrategyConfirmDialog from './StrategyConfirmDialog.vue'

const props = defineProps<{
  line: ScriptLine
  scriptId: string
  scope: WaveScope
  languages: string[]
  view: LanguageView
  index: number
  count: number
  /** True for a line that was just added (it opens), or while the script is short. */
  initiallyOpen: boolean
  textMax: number
  brollMax: number
  /** B-roll rows in the whole script and the most it may hold. */
  brollUsed: number
  brollLimit: number
  canAddLine: boolean
  imageLimits: { images: number; bytes: number }
  scriptBytes: number
  dragging: string | null
  over: string | null
  /** The line was deleted elsewhere and this card only keeps the person's text on screen. */
  ghost: boolean
  removeFn: () => Promise<string | null>
  addBrollFn: () => Promise<string | null>
  removeBrollFn: (brollId: string) => Promise<string | null>
  moveBrollFn: (brollId: string, delta: -1 | 1) => Promise<string | null>
  debounceMs?: number
}>()
const emit = defineEmits<{
  saved: [script: WaveScript]
  duplicate: []
  move: [delta: -1 | 1]
  dragstart: [event: DragEvent]
  dragover: [event: DragEvent]
  drop: [event: DragEvent]
  dragend: []
  imagesChanged: []
  /** The person closed the "this line was deleted" box. */
  dismiss: []
}>()
const { t } = useI18n()

const block = useBlockAutosave<LineValue>({
  remote: () => ({ value: lineValue(props.line, props.languages), revision: props.line.revision }),
  save: async (value, revision, base) => {
    const res = (
      await strategyApi.saveLine(
        props.scope,
        props.scriptId,
        props.line.id,
        linePayload(value, base, revision, props.languages),
      )
    ).data
    emit('saved', res)
    const mine = res.lines.find((l) => l.id === props.line.id)
    if (mine === undefined) throw new Error('line missing in the answer')
    return { value: lineValue(mine, props.languages), revision: mine.revision }
  },
  same: sameLine,
  fromConflict: (current) =>
    'number' in current
      ? { value: lineValue(current, props.languages), revision: current.revision }
      : null,
  clone: (v) => ({
    texts: { ...v.texts },
    cta: v.cta,
    broll: Object.fromEntries(Object.entries(v.broll).map(([id, r]) => [id, { ...r }])),
  }),
  reconcile: reconcileLine,
  debounceMs: props.debounceMs,
})
useRegisterBlock(`line:${props.line.id}`, { status: block.status, flush: block.flush })
watch(
  () => [props.line, props.languages] as const,
  () => {
    if (!props.ghost) block.syncRemote()
  },
)

const open = ref(props.initiallyOpen)
async function toggle(): Promise<void> {
  if (open.value) await block.flush()
  open.value = !open.value
}
defineExpose({ flush: block.flush })

const d = computed(() => block.draft.value)
const columns = computed(() => viewLanguages(props.view))
const images = computed<ScriptImage[]>(() => props.line.images)
const gone = computed(() => block.status.value === 'gone' || props.ghost)

function setText(language: string, v: string): void {
  block.edit((x) => {
    x.texts[language] = v
  })
}
function setCta(v: boolean): void {
  block.edit((x) => {
    x.cta = v
  })
}

// The B-roll rows read their fields from the draft when it has them, else from the server's row.
function rowShot(id: string, server: ShotType | null): ShotType | null {
  const r = d.value.broll[id]
  return r === undefined ? server : r.shot
}
function rowText(id: string, server: string | null): string {
  const r = d.value.broll[id]
  return r === undefined ? (server ?? '') : r.description
}
function editRow(
  id: string,
  change: (r: { shot: ShotType | null; description: string }) => void,
): void {
  const row = props.line.broll.find((r) => r.id === id)
  block.edit((x) => {
    const cur = x.broll[id] ?? { shot: row?.shot_type ?? null, description: row?.description ?? '' }
    change(cur)
    x.broll[id] = cur
  })
}
function setRowShot(id: string, code: ShotType): void {
  editRow(id, (r) => {
    r.shot = code
  })
}
function setRowText(id: string, v: string): void {
  editRow(id, (r) => {
    r.description = v
  })
}
function shotName(code: ShotType | null): string {
  return code === null
    ? t('app.strategy.scripts.hooks.noShot')
    : t(`app.strategy.scripts.shot.${code}`)
}

const brollError = ref<string | null>(null)
const brollBusy = ref(false)
async function brollAction(call: () => Promise<string | null>): Promise<void> {
  if (brollBusy.value) return
  brollBusy.value = true
  brollError.value = await call()
  brollBusy.value = false
}
const brollFull = computed(() => props.brollUsed >= props.brollLimit)
const brollNear = computed(() => nearLimit(props.brollUsed, props.brollLimit, BROLL_NEAR))

// Texts as the columns read them.
const texts = computed<Record<string, string>>(() => ({ ...d.value.texts }))
const summary = computed(() => quoteOf(texts.value, props.languages))

// Delete: a line with anything in it asks first and says what goes with it; an empty one goes at once.
const confirmOpen = ref(false)
const removing = ref(false)
const removeError = ref<string | null>(null)
const hasContent = computed(
  () =>
    Object.values(d.value.texts).some((x) => x !== '') ||
    props.line.broll.length > 0 ||
    images.value.length > 0 ||
    d.value.cta,
)
async function askRemove(): Promise<void> {
  removeError.value = null
  if (hasContent.value) {
    confirmOpen.value = true
    return
  }
  await doRemove()
}
async function doRemove(): Promise<void> {
  if (removing.value) return
  removing.value = true
  removeError.value = null
  const err = await props.removeFn()
  removing.value = false
  if (err === null) confirmOpen.value = false
  else removeError.value = err
}

const title = computed(() =>
  d.value.cta
    ? t('app.strategy.scripts.lines.lineCta', { n: props.line.number })
    : t('app.strategy.scripts.lines.line', { n: props.line.number }),
)

// A person's own words, to copy when the line is gone.
const copied = ref(false)
const copyFailed = ref(false)
async function copyMine(): Promise<void> {
  copyFailed.value = false
  const parts: string[] = []
  for (const l of props.languages) {
    const text = d.value.texts[l] ?? ''
    if (text !== '') parts.push(props.languages.length > 1 ? `${l.toUpperCase()}: ${text}` : text)
  }
  for (const row of props.line.broll) {
    const r = d.value.broll[row.id]
    if (r !== undefined && r.description !== '') {
      parts.push(`${row.code} ${shotName(r.shot)}: ${r.description}`)
    }
  }
  try {
    await navigator.clipboard.writeText(parts.join('\n'))
    copied.value = true
  } catch {
    copyFailed.value = true
    copied.value = false
  }
}

const lineSummary = (v: LineValue): { label: string; text: string }[] => [
  ...props.languages.map((l) => ({
    label: `${t('app.strategy.scripts.lines.script')} (${l.toUpperCase()})`,
    text: v.texts[l] ?? '',
  })),
  ...props.line.broll.map((r) => {
    const row = v.broll[r.id]
    return {
      label: t('app.strategy.scripts.lines.shotLabel', { code: r.code }),
      text:
        row === undefined
          ? ''
          : [row.shot === null ? '' : shotName(row.shot), row.description]
              .filter((x) => x !== '')
              .join(' · '),
    }
  }),
  { label: t('app.strategy.scripts.lines.cta'), text: v.cta ? '✓' : '' },
]
const mineLines = computed(() => lineSummary(d.value))
const theirsLines = computed(() =>
  block.theirs.value === null ? [] : lineSummary(block.theirs.value),
)
</script>

<template>
  <article
    class="lcard"
    :class="{
      'lcard--over': over === line.id && dragging !== null && dragging !== line.id,
      'lcard--dragging': dragging === line.id,
      'lcard--gone': gone,
      'lcard--shut': !open,
    }"
    :data-test="`script-line-${line.id}`"
    :data-number="line.number"
    data-block="line"
    @dragover="emit('dragover', $event)"
    @drop="emit('drop', $event)"
  >
    <header class="lcard__head">
      <div class="lcard__title">
        <BlockGrip
          :label="
            t('app.strategy.scripts.order.grip', {
              name: t('app.strategy.scripts.lines.line', { n: line.number }),
            })
          "
          :test-id="`script-line-grip-${line.id}`"
          :disabled="gone"
          @move="(delta) => emit('move', delta)"
          @dragstart="(e) => emit('dragstart', e)"
          @dragend="emit('dragend')"
        />
        <h3 class="lcard__name" :data-test="`script-line-name-${line.id}`">{{ title }}</h3>
        <span v-if="d.cta" class="lcard__cta" :data-test="`script-line-cta-chip-${line.id}`">{{
          t('app.strategy.scripts.lines.cta')
        }}</span>
        <template v-if="!open">
          <span class="lcard__summary" :data-test="`script-line-summary-${line.id}`">{{
            summary === '' ? t('app.strategy.scripts.lines.empty') : `“${summary}”`
          }}</span>
          <span class="lcard__shots" :data-test="`script-line-shots-${line.id}`">{{
            line.broll.length === 0
              ? `· ${t('app.strategy.scripts.lines.noShots')}`
              : `· ${t('app.strategy.scripts.lines.shotsCount', { count: line.broll.length })}`
          }}</span>
          <span
            v-if="images.length > 0"
            class="lcard__imgchip"
            :data-test="`script-line-imgchip-${line.id}`"
          >
            <v-icon icon="mdi-image-outline" size="12" aria-hidden="true" />{{
              t('app.strategy.scripts.lines.imagesCount', { count: images.length })
            }}
          </span>
        </template>
      </div>
      <div class="lcard__tools">
        <BlockStatusLine
          :status="block.status.value"
          :failure="block.failure.value"
          :test-id="`script-line-status-${line.id}`"
          @retry="block.retry()"
        />
        <button
          type="button"
          class="lcard__collapse"
          :aria-expanded="open"
          :aria-label="
            open ? t('app.strategy.scripts.lines.collapse') : t('app.strategy.scripts.lines.expand')
          "
          :data-test="`script-line-toggle-${line.id}`"
          @click="toggle"
        >
          <v-icon
            :icon="open ? 'mdi-chevron-up' : 'mdi-chevron-down'"
            size="16"
            aria-hidden="true"
          />
        </button>
        <v-menu v-bind="MENU_PROPS" location="bottom end">
          <template #activator="{ props: act }">
            <button
              v-bind="act"
              type="button"
              class="lcard__dots"
              :aria-label="t('app.strategy.scripts.lines.menu', { n: line.number })"
              :data-test="`script-line-menu-${line.id}`"
            >
              <v-icon icon="mdi-dots-horizontal" size="16" aria-hidden="true" />
            </button>
          </template>
          <v-list density="compact">
            <v-list-item
              :disabled="!canAddLine || gone"
              prepend-icon="mdi-content-copy"
              :data-test="`script-line-duplicate-${line.id}`"
              @click="emit('duplicate')"
            >
              <v-list-item-title>{{ t('app.strategy.scripts.lines.duplicate') }}</v-list-item-title>
            </v-list-item>
            <v-list-item
              :disabled="index === 0 || gone"
              prepend-icon="mdi-arrow-up"
              :data-test="`script-line-up-${line.id}`"
              @click="emit('move', -1)"
            >
              <v-list-item-title>{{ t('app.strategy.scripts.order.up') }}</v-list-item-title>
            </v-list-item>
            <v-list-item
              :disabled="index >= count - 1 || gone"
              prepend-icon="mdi-arrow-down"
              :data-test="`script-line-down-${line.id}`"
              @click="emit('move', 1)"
            >
              <v-list-item-title>{{ t('app.strategy.scripts.order.down') }}</v-list-item-title>
            </v-list-item>
            <v-list-item
              :disabled="gone"
              prepend-icon="mdi-delete-outline"
              :data-test="`script-line-delete-${line.id}`"
              @click="askRemove"
            >
              <v-list-item-title>{{ t('app.strategy.scripts.lines.delete') }}</v-list-item-title>
            </v-list-item>
          </v-list>
        </v-menu>
      </div>
    </header>

    <div v-if="gone" class="lcard__gone" role="alert" :data-test="`script-line-gone-${line.id}`">
      <p class="lcard__gone-title">{{ t('app.strategy.scripts.lines.goneTitle') }}</p>
      <p class="lcard__gone-hint">{{ t('app.strategy.scripts.lines.goneHint') }}</p>
      <div class="lcard__gone-actions">
        <v-btn
          size="small"
          variant="outlined"
          :data-test="`script-line-gone-copy-${line.id}`"
          @click="copyMine"
          >{{
            copied
              ? t('app.strategy.scripts.conflict.copied')
              : t('app.strategy.scripts.conflict.copy')
          }}</v-btn
        >
        <v-btn
          size="small"
          variant="text"
          :data-test="`script-line-gone-close-${line.id}`"
          @click="emit('dismiss')"
          >{{ t('app.strategy.close') }}</v-btn
        >
      </div>
      <p v-if="copyFailed" class="lcard__gone-hint">
        {{ t('app.strategy.scripts.conflict.copyFailed') }}
      </p>
    </div>

    <div v-show="open" class="lcard__body">
      <div class="lcard__row">
        <div class="lcard__label">
          <v-icon icon="mdi-microphone-outline" size="13" aria-hidden="true" />{{
            t('app.strategy.scripts.lines.script')
          }}
        </div>
        <ScriptTextColumns
          :languages="columns"
          :texts="texts"
          :max="textMax"
          :placeholder="t('app.strategy.scripts.lines.scriptPlaceholder')"
          :label="t('app.strategy.scripts.lines.script')"
          :test-id="`script-line-text-${line.id}`"
          @edit="setText"
        />
      </div>

      <div class="lcard__row">
        <div class="lcard__label">
          <v-icon icon="mdi-video-outline" size="13" aria-hidden="true" />{{
            t('app.strategy.scripts.lines.broll')
          }}
        </div>
        <div class="lcard__broll" :data-test="`script-line-broll-${line.id}`">
          <div
            v-for="(row, i) in line.broll"
            :key="row.id"
            class="brow"
            :data-test="`script-broll-${row.id}`"
            :data-code="row.code"
          >
            <span class="brow__code" :data-test="`script-broll-code-${row.id}`">{{
              row.code
            }}</span>
            <v-menu v-bind="MENU_PROPS" location="bottom start">
              <template #activator="{ props: act }">
                <button
                  v-bind="act"
                  type="button"
                  class="brow__shot"
                  :class="{ 'brow__shot--none': rowShot(row.id, row.shot_type) === null }"
                  :aria-label="t('app.strategy.scripts.lines.shotType', { code: row.code })"
                  :data-test="`script-broll-shot-${row.id}`"
                >
                  <v-icon icon="mdi-video-outline" size="12" aria-hidden="true" />
                  <span>{{ shotName(rowShot(row.id, row.shot_type)) }}</span>
                  <v-icon icon="mdi-chevron-down" size="11" aria-hidden="true" />
                </button>
              </template>
              <v-list
                density="compact"
                class="brow__shots"
                :data-test="`script-broll-shot-menu-${row.id}`"
              >
                <v-list-item
                  v-for="code in SHOT_TYPES"
                  :key="code"
                  :active="code === rowShot(row.id, row.shot_type)"
                  :data-test="`script-broll-shot-${row.id}-${code}`"
                  @click="setRowShot(row.id, code)"
                >
                  <v-list-item-title>{{
                    t(`app.strategy.scripts.shot.${code}`)
                  }}</v-list-item-title>
                  <template v-if="code === rowShot(row.id, row.shot_type)" #append>
                    <v-icon icon="mdi-check" size="14" aria-hidden="true" />
                  </template>
                </v-list-item>
              </v-list>
            </v-menu>
            <AutoGrowTextarea
              :model-value="rowText(row.id, row.description)"
              class="brow__text"
              :placeholder="t('app.strategy.scripts.lines.shotPlaceholder')"
              :aria-label="t('app.strategy.scripts.lines.shotDescription', { code: row.code })"
              :error="rowText(row.id, row.description).length > brollMax"
              :data-test="`script-broll-text-${row.id}`"
              @update:model-value="(v: string) => setRowText(row.id, v)"
            />
            <div class="brow__tools">
              <button
                type="button"
                class="brow__btn"
                :disabled="i === 0 || brollBusy"
                :aria-label="t('app.strategy.scripts.lines.shotUp', { code: row.code })"
                :data-test="`script-broll-up-${row.id}`"
                @click="brollAction(() => moveBrollFn(row.id, -1))"
              >
                <v-icon icon="mdi-arrow-up" size="14" aria-hidden="true" />
              </button>
              <button
                type="button"
                class="brow__btn"
                :disabled="i >= line.broll.length - 1 || brollBusy"
                :aria-label="t('app.strategy.scripts.lines.shotDown', { code: row.code })"
                :data-test="`script-broll-down-${row.id}`"
                @click="brollAction(() => moveBrollFn(row.id, 1))"
              >
                <v-icon icon="mdi-arrow-down" size="14" aria-hidden="true" />
              </button>
              <button
                type="button"
                class="brow__btn"
                :disabled="brollBusy"
                :aria-label="t('app.strategy.scripts.lines.shotDelete', { code: row.code })"
                :data-test="`script-broll-delete-${row.id}`"
                @click="brollAction(() => removeBrollFn(row.id))"
              >
                <v-icon icon="mdi-close" size="14" aria-hidden="true" />
              </button>
            </div>
          </div>
          <button
            type="button"
            class="lcard__add-shot"
            :disabled="brollFull || brollBusy || gone"
            :data-test="`script-line-add-shot-${line.id}`"
            @click="brollAction(addBrollFn)"
          >
            <v-icon icon="mdi-plus" size="12" aria-hidden="true" />{{
              brollFull
                ? t('app.strategy.scripts.lines.shotLimit')
                : t('app.strategy.scripts.lines.addShot')
            }}
          </button>
          <p v-if="brollNear" class="lcard__note" :data-test="`script-line-broll-usage-${line.id}`">
            {{ t('app.strategy.scripts.lines.shotsUsage', { n: brollUsed, max: brollLimit }) }}
          </p>
          <p
            v-if="brollError !== null"
            class="lcard__note lcard__note--error"
            role="alert"
            :data-test="`script-line-broll-error-${line.id}`"
          >
            {{ brollError }}
          </p>
        </div>
      </div>

      <div class="lcard__row">
        <div class="lcard__label">
          <v-icon icon="mdi-bullhorn-outline" size="13" aria-hidden="true" />{{
            t('app.strategy.scripts.lines.cta')
          }}
        </div>
        <label class="lcard__ctabox">
          <input
            type="checkbox"
            :checked="d.cta"
            :data-test="`script-line-cta-${line.id}`"
            @change="(e) => setCta((e.target as HTMLInputElement).checked)"
          />
          <span>{{ t('app.strategy.scripts.lines.ctaLabel') }}</span>
        </label>
      </div>

      <ScriptImageField
        :scope="scope"
        :script-id="scriptId"
        block="line"
        :block-id="line.id"
        :images="images"
        :script-bytes="scriptBytes"
        :limits="imageLimits"
        :test-id="`script-line-${line.id}`"
        @changed="emit('imagesChanged')"
      />

      <BlockConflict
        v-if="block.status.value === 'conflict'"
        :theirs="theirsLines"
        :mine="mineLines"
        :test-id="`script-line-conflict-${line.id}`"
        @keep="block.keepMine()"
        @theirs="block.useTheirs()"
      />
    </div>

    <StrategyConfirmDialog
      v-model="confirmOpen"
      :title="t('app.strategy.scripts.lines.deleteTitle', { n: line.number })"
      :body="
        t('app.strategy.scripts.lines.deleteBody', {
          broll: line.broll.length,
          images: images.length,
        })
      "
      :confirm-label="t('app.strategy.scripts.lines.deleteConfirm')"
      :busy="removing"
      :error="removeError"
      :test-id="`script-line-delete-dialog-${line.id}`"
      @confirm="doRemove"
    />
  </article>
</template>

<style scoped>
.lcard {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
}
.lcard--shut {
  /* Frame 13:3916: 48 high (14 + 18 content + border); the 22 px grip and buttons need 12 here for the same height. */
  padding-block: 12px;
}
.lcard--over {
  box-shadow: 0 -3px 0 0 rgb(var(--v-theme-primary));
}
.lcard--dragging {
  opacity: 0.5;
}
.lcard--gone {
  border-color: rgb(var(--v-theme-error));
}
.lcard__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  min-height: 18px;
}
.lcard__title {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.lcard__collapse,
.lcard__dots {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 22px;
  height: 22px;
  padding: 0;
  border: 0;
  border-radius: 6px;
  background: none;
  color: rgb(var(--v-theme-on-surface-variant));
  cursor: pointer;
}
.lcard__collapse:hover,
.lcard__dots:hover {
  background: rgb(var(--v-theme-surface-bright));
  color: rgb(var(--v-theme-on-surface));
}
.lcard__name {
  margin: 0;
  flex: none;
  font-size: 15px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
}
.lcard__cta {
  flex: none;
  padding: 2px 7px;
  border-radius: 9999px;
  background: color-mix(in srgb, rgb(var(--v-theme-primary)) 15%, transparent);
  font-size: 10px;
  font-weight: 500;
  line-height: normal;
  color: rgb(var(--v-theme-primary));
}
.lcard__summary {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 13px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.lcard__shots {
  flex: none;
  font-size: 12px;
  line-height: normal;
  white-space: nowrap;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.lcard__imgchip {
  display: inline-flex;
  flex: none;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  border-radius: 9999px;
  background: rgb(var(--v-theme-surface-bright));
  font-size: 11px;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
}
.lcard__tools {
  display: flex;
  align-items: center;
  flex: none;
  gap: 8px;
}
.lcard__body {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.lcard__row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  min-width: 0;
}
.lcard__label {
  display: flex;
  align-items: center;
  flex: none;
  gap: 6px;
  width: 112px;
  padding-top: 2px;
  font-size: 12px;
  font-weight: 500;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
}
.lcard__broll {
  display: flex;
  flex: 1 1 0;
  flex-direction: column;
  align-items: stretch;
  gap: 6px;
  min-width: 0;
}
.brow {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
  padding: 6px 8px;
  border-radius: 6px;
  background: rgb(var(--v-theme-background));
}
.brow__code {
  flex: none;
  min-width: 28px;
  font-size: 12px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.brow__shot {
  display: inline-flex;
  flex: none;
  align-items: center;
  gap: 5px;
  padding: 3px 8px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 6px;
  background: rgb(var(--v-theme-surface-bright));
  font: inherit;
  font-size: 12px;
  font-weight: 500;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
  cursor: pointer;
}
.brow__shot--none {
  color: rgb(var(--v-theme-on-surface-variant));
  font-weight: 400;
}
.brow__shot:hover {
  border-color: rgb(var(--v-theme-on-surface-variant));
}
.brow__shots {
  min-width: 210px;
  padding: 6px;
  border-radius: 8px;
  background: rgb(var(--v-theme-surface-bright));
}
.brow__shots :deep(.v-list-item) {
  min-height: 0;
  padding: 6px 10px;
  border-radius: 5px;
}
.brow__shots :deep(.v-list-item-title) {
  font-size: 12px;
  line-height: normal;
}
.brow__shots :deep(.v-list-item--active) {
  background: color-mix(in srgb, rgb(var(--v-theme-primary)) 10%, transparent);
  color: rgb(var(--v-theme-primary));
}
.brow__shots :deep(.v-list-item--active .v-list-item__overlay) {
  opacity: 0;
}
.brow__text {
  flex: 1 1 0;
  min-width: 0;
  margin: 0;
}
.brow__tools {
  display: flex;
  flex: none;
  gap: 2px;
}
.brow__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  padding: 0;
  border: 0;
  border-radius: 6px;
  background: none;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
  cursor: pointer;
}
.brow__btn:hover:not(:disabled) {
  background: rgb(var(--v-theme-surface-bright));
  color: rgb(var(--v-theme-on-surface));
}
.brow__btn:disabled {
  cursor: default;
  opacity: 0.35;
}
.brow__btn:focus-visible,
.lcard__add-shot:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
}
.lcard__add-shot {
  display: inline-flex;
  align-items: center;
  align-self: flex-start;
  gap: 6px;
  padding: 4px 8px;
  border: 0;
  border-radius: 6px;
  background: none;
  font: inherit;
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
  cursor: pointer;
}
.lcard__add-shot:hover:not(:disabled) {
  color: rgb(var(--v-theme-on-surface));
}
.lcard__add-shot:disabled {
  cursor: default;
  opacity: 0.6;
}
.lcard__note {
  margin: 0;
  font-size: 11.5px;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.lcard__note--error {
  color: rgb(var(--v-theme-error));
}
.lcard__ctabox {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding-top: 1px;
  font-size: 13px;
  color: rgb(var(--v-theme-on-surface));
  cursor: pointer;
}
.lcard__ctabox input {
  width: 16px;
  height: 16px;
  accent-color: rgb(var(--v-theme-primary));
}
.lcard__gone {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 10px 12px;
  border: 1px solid rgb(var(--v-theme-error));
  border-radius: 8px;
}
.lcard__gone-title {
  margin: 0;
  font-size: 13px;
  font-weight: 600;
  color: rgb(var(--v-theme-error));
}
.lcard__gone-hint {
  margin: 0;
  font-size: 12px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.lcard__gone-actions {
  display: flex;
  gap: 8px;
}
@media (max-width: 599px) {
  .lcard__row {
    flex-direction: column;
    align-items: stretch;
    gap: 4px;
  }
  .lcard__label {
    width: auto;
  }
  .brow {
    flex-wrap: wrap;
  }
  .brow__text {
    flex: 1 1 100%;
    order: 3;
  }
  .brow__tools {
    margin-left: auto;
  }
  .lcard__summary,
  .lcard__shots {
    display: none;
  }
}
</style>
