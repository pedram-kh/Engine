<script setup lang="ts">
/**
 * PL-3.2a, PL-3.2b — one hook card (frame 13:3108, node 13:3457): header with a grip, "Hook A" and a menu (duplicate, move up, move
 * down, delete) and a collapse control; the audio hook and the optional overlay in the languages of the view (one column, or two
 * side by side), the visual hook (a shot type from the ten, plus a description: English only) and the reference images. The
 * card autosaves its texts, shot type and description as one block with its own revision; the images are separate requests (they
 * save as they are added). All text is plain text.
 */
import type { ScriptHook, ScriptImage, ShotType, WaveScript } from '@catalyst/api-client'
import { SHOT_TYPES } from '@catalyst/api-client'
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { strategyApi, type WaveScope } from '../api/strategy.api'
import { useBlockAutosave } from '../composables/useBlockAutosave'
import { useRegisterBlock } from '../composables/useSaveRegistry'
import { MENU_PROPS } from '../constants'
import {
  EN,
  hookPayload,
  hookValue,
  localLanguages,
  quoteOf,
  sameHook,
  viewLanguages,
  type HookValue,
  type LanguageView,
} from '../scriptsLogic'
import AutoGrowTextarea from './AutoGrowTextarea.vue'
import BlockConflict from './BlockConflict.vue'
import BlockGone from './BlockGone.vue'
import BlockGrip from './BlockGrip.vue'
import BlockStatusLine from './BlockStatusLine.vue'
import ScriptImageField from './ScriptImageField.vue'
import ScriptTextColumns from './ScriptTextColumns.vue'
import StrategyConfirmDialog from './StrategyConfirmDialog.vue'

const props = defineProps<{
  hook: ScriptHook
  scriptId: string
  scope: WaveScope
  canDelete: boolean
  canAdd: boolean
  limits: { audio: number; overlay: number; visual: number }
  /** The script's languages (`en` first) and what the view shows of them. */
  languages: string[]
  view: LanguageView
  /** Where this hook is in the list (for "Move up" and "Move down"). */
  index: number
  count: number
  /** The image limits and the bytes the whole script holds. */
  imageLimits: { images: number; bytes: number }
  scriptBytes: number
  /** The hook being dragged and the hook it is over (the list owns both). */
  dragging: string | null
  over: string | null
  /** Deletes the hook on the server; resolves with a message when it could not, null when it is gone. */
  removeFn: () => Promise<string | null>
  /** The hook was deleted by someone else while this card held the person's text: the card stays, read-only for structure. */
  ghost?: boolean
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
  /** An image was added or removed (the editor reads the script again). */
  imagesChanged: []
  /** The person closed the "deleted by someone else" box. */
  dismiss: []
}>()
const { t } = useI18n()

const block = useBlockAutosave<HookValue>({
  remote: () => ({ value: hookValue(props.hook, props.languages), revision: props.hook.revision }),
  save: async (value, revision) => {
    const res = (
      await strategyApi.saveHook(
        props.scope,
        props.scriptId,
        props.hook.id,
        hookPayload(value, revision, props.languages),
      )
    ).data
    emit('saved', res)
    const mine = res.hooks.find((h) => h.id === props.hook.id)
    if (mine === undefined) throw new Error('hook missing in the answer')
    return { value: hookValue(mine, props.languages), revision: mine.revision }
  },
  same: sameHook,
  fromConflict: (current) =>
    'letter' in current
      ? { value: hookValue(current, props.languages), revision: current.revision }
      : null,
  clone: (v) => ({
    ...v,
    local: { audio: { ...v.local.audio }, overlay: { ...v.local.overlay } },
  }),
  debounceMs: props.debounceMs,
})
useRegisterBlock(`hook:${props.hook.id}`, { status: block.status, flush: block.flush })
watch(
  () => [props.hook, props.languages] as const,
  () => {
    if (props.ghost !== true) block.syncRemote()
  },
)

const open = ref(true)
async function toggle(): Promise<void> {
  if (open.value) await block.flush()
  open.value = !open.value
}
defineExpose({ flush: block.flush })

const d = computed(() => block.draft.value)
const gone = computed(() => block.status.value === 'gone' || props.ghost === true)
// A person's own words, to copy when the hook is gone.
function goneText(): string {
  const parts: string[] = []
  const v = d.value
  if (v.shot !== null || v.visual !== '')
    parts.push(
      `${t('app.strategy.scripts.hooks.visual')}: ${[v.shot === null ? '' : t(`app.strategy.scripts.shot.${v.shot}`), v.visual].filter((x) => x !== '').join(' - ')}`,
    )
  const rows: [string, Record<string, string>][] = [
    [t('app.strategy.scripts.hooks.audio'), { [EN]: v.audio, ...v.local.audio }],
    [t('app.strategy.scripts.hooks.overlay'), { [EN]: v.overlay, ...v.local.overlay }],
  ]
  for (const [label, texts] of rows)
    for (const [l, text] of Object.entries(texts))
      if (text !== '') parts.push(`${label} (${l.toUpperCase()}): ${text}`)
  return parts.join('\n')
}
const columns = computed(() => viewLanguages(props.view))

// The texts of every language, as the columns read them.
const audioTexts = computed<Record<string, string>>(() => ({
  [EN]: d.value.audio,
  ...d.value.local.audio,
}))
const overlayTexts = computed<Record<string, string>>(() => ({
  [EN]: d.value.overlay,
  ...d.value.local.overlay,
}))
const hasOverlay = computed(() => Object.values(overlayTexts.value).some((x) => x !== ''))
const overlayShown = ref(Object.values(overlayTexts.value).some((x) => x !== ''))
async function showOverlay(): Promise<void> {
  overlayShown.value = true
  await nextTick()
  document.getElementById(`hook-overlay-${props.hook.id}`)?.focus()
}

function setShot(code: ShotType): void {
  block.edit((v) => {
    v.shot = code
  })
}
function setVisual(v: string): void {
  block.edit((x) => {
    x.visual = v
  })
}
function setText(field: 'audio' | 'overlay', language: string, v: string): void {
  block.edit((x) => {
    if (language === EN) x[field] = v
    else x.local[field][language] = v
  })
}

// Delete: a hook with anything in it (as the person sees it, saved or not), or with images, asks first; an empty one goes at once.
const confirmOpen = ref(false)
const removing = ref(false)
const removeError = ref<string | null>(null)
const hasContent = computed(
  () =>
    d.value.visual !== '' ||
    d.value.shot !== null ||
    Object.values(audioTexts.value).some((x) => x !== '') ||
    hasOverlay.value ||
    props.hook.images.length > 0,
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
const shotLabel = computed(() =>
  d.value.shot === null
    ? t('app.strategy.scripts.hooks.noShot')
    : t(`app.strategy.scripts.shot.${d.value.shot}`),
)
const summary = computed(() => quoteOf(audioTexts.value, props.languages))
const images = computed<ScriptImage[]>(() => props.hook.images)

const lineFor = (v: HookValue): { label: string; text: string }[] => [
  { label: t('app.strategy.scripts.hooks.audio'), text: v.audio },
  ...localLanguages(props.languages).map((l) => ({
    label: `${t('app.strategy.scripts.hooks.audio')} (${l.toUpperCase()})`,
    text: v.local.audio[l] ?? '',
  })),
  {
    label: t('app.strategy.scripts.hooks.visual'),
    text: [v.shot === null ? '' : t(`app.strategy.scripts.shot.${v.shot}`), v.visual]
      .filter((x) => x !== '')
      .join(' · '),
  },
  { label: t('app.strategy.scripts.hooks.overlay'), text: v.overlay },
  ...localLanguages(props.languages).map((l) => ({
    label: `${t('app.strategy.scripts.hooks.overlay')} (${l.toUpperCase()})`,
    text: v.local.overlay[l] ?? '',
  })),
]
const mineLines = computed(() => lineFor(d.value))
const theirsLines = computed(() => (block.theirs.value === null ? [] : lineFor(block.theirs.value)))
</script>

<template>
  <article
    class="hcard"
    :class="{
      'hcard--over': over === hook.id && dragging !== null && dragging !== hook.id,
      'hcard--dragging': dragging === hook.id,
      'hcard--gone': gone,
    }"
    :data-test="`script-hook-${hook.id}`"
    :data-letter="hook.letter"
    data-block="hook"
    @dragover="emit('dragover', $event)"
    @drop="emit('drop', $event)"
  >
    <header class="hcard__head">
      <div class="hcard__title">
        <BlockGrip
          :label="
            t('app.strategy.scripts.order.grip', {
              name: t('app.strategy.scripts.hooks.hook', { letter: hook.letter }),
            })
          "
          :test-id="`script-hook-grip-${hook.id}`"
          @move="(delta) => emit('move', delta)"
          @dragstart="(e) => emit('dragstart', e)"
          @dragend="emit('dragend')"
        />
        <button
          type="button"
          class="hcard__collapse"
          :aria-expanded="open"
          :aria-label="
            open ? t('app.strategy.scripts.hooks.collapse') : t('app.strategy.scripts.hooks.expand')
          "
          :data-test="`script-hook-toggle-${hook.id}`"
          @click="toggle"
        >
          <v-icon
            :icon="open ? 'mdi-chevron-down' : 'mdi-chevron-right'"
            size="16"
            aria-hidden="true"
          />
        </button>
        <h3 class="hcard__name">
          {{ t('app.strategy.scripts.hooks.hook', { letter: hook.letter }) }}
        </h3>
        <span v-if="!open" class="hcard__summary" :data-test="`script-hook-summary-${hook.id}`">{{
          summary === '' ? t('app.strategy.scripts.hooks.empty') : `“${summary}”`
        }}</span>
        <span
          v-if="!open && images.length > 0"
          class="hcard__imgchip"
          :data-test="`script-hook-imgchip-${hook.id}`"
        >
          <v-icon icon="mdi-image-outline" size="12" aria-hidden="true" />{{
            t('app.strategy.scripts.lines.imagesCount', { count: images.length })
          }}
        </span>
      </div>
      <div class="hcard__tools">
        <BlockStatusLine
          :status="block.status.value"
          :failure="block.failure.value"
          :test-id="`script-hook-status-${hook.id}`"
          @retry="block.retry()"
        />
        <v-menu v-bind="MENU_PROPS" location="bottom end">
          <template #activator="{ props: act }">
            <button
              v-bind="act"
              type="button"
              class="hcard__dots"
              :aria-label="t('app.strategy.scripts.hooks.menu', { letter: hook.letter })"
              :data-test="`script-hook-menu-${hook.id}`"
            >
              <v-icon icon="mdi-dots-horizontal" size="16" aria-hidden="true" />
            </button>
          </template>
          <v-list density="compact">
            <v-list-item
              :disabled="!canAdd || gone"
              prepend-icon="mdi-content-copy"
              :data-test="`script-hook-duplicate-${hook.id}`"
              @click="emit('duplicate')"
            >
              <v-list-item-title>{{ t('app.strategy.scripts.hooks.duplicate') }}</v-list-item-title>
            </v-list-item>
            <v-list-item
              :disabled="index === 0 || gone"
              prepend-icon="mdi-arrow-up"
              :data-test="`script-hook-up-${hook.id}`"
              @click="emit('move', -1)"
            >
              <v-list-item-title>{{ t('app.strategy.scripts.order.up') }}</v-list-item-title>
            </v-list-item>
            <v-list-item
              :disabled="index >= count - 1 || gone"
              prepend-icon="mdi-arrow-down"
              :data-test="`script-hook-down-${hook.id}`"
              @click="emit('move', 1)"
            >
              <v-list-item-title>{{ t('app.strategy.scripts.order.down') }}</v-list-item-title>
            </v-list-item>
            <v-list-item
              :disabled="!canDelete || gone"
              prepend-icon="mdi-delete-outline"
              :data-test="`script-hook-delete-${hook.id}`"
              @click="askRemove"
            >
              <v-list-item-title>{{ t('app.strategy.scripts.hooks.delete') }}</v-list-item-title>
            </v-list-item>
          </v-list>
        </v-menu>
      </div>
    </header>

    <BlockGone
      v-if="gone"
      :id="hook.id"
      prefix="script-hook"
      :title="t('app.strategy.scripts.hooks.goneTitle')"
      :hint="t('app.strategy.scripts.hooks.goneHint')"
      :text="goneText"
      @dismiss="emit('dismiss')"
    />

    <div v-show="open" class="hcard__body">
      <div class="hcard__row">
        <div class="hcard__label">
          <v-icon icon="mdi-microphone-outline" size="13" aria-hidden="true" />{{
            t('app.strategy.scripts.hooks.audio')
          }}
        </div>
        <ScriptTextColumns
          :languages="columns"
          :texts="audioTexts"
          :max="limits.audio"
          :placeholder="t('app.strategy.scripts.hooks.audioPlaceholder')"
          :label="t('app.strategy.scripts.hooks.audio')"
          :test-id="`script-hook-audio-${hook.id}`"
          @edit="(language, v) => setText('audio', language, v)"
        />
      </div>

      <div class="hcard__row">
        <div class="hcard__label">
          <v-icon icon="mdi-video-outline" size="13" aria-hidden="true" />{{
            t('app.strategy.scripts.hooks.visual')
          }}
        </div>
        <div class="hcard__field">
          <v-menu v-bind="MENU_PROPS" location="bottom start">
            <template #activator="{ props: act }">
              <button
                v-bind="act"
                type="button"
                class="hcard__shot"
                :class="{ 'hcard__shot--none': d.shot === null }"
                :data-test="`script-hook-shot-${hook.id}`"
              >
                <v-icon icon="mdi-video-outline" size="12" aria-hidden="true" />
                <span>{{ shotLabel }}</span>
                <v-icon icon="mdi-chevron-down" size="11" aria-hidden="true" />
              </button>
            </template>
            <v-list
              density="compact"
              class="hcard__shots"
              :data-test="`script-hook-shot-menu-${hook.id}`"
            >
              <v-list-item
                v-for="code in SHOT_TYPES"
                :key="code"
                :active="code === d.shot"
                :data-test="`script-shot-${code}`"
                @click="setShot(code)"
              >
                <v-list-item-title>{{ t(`app.strategy.scripts.shot.${code}`) }}</v-list-item-title>
                <template v-if="code === d.shot" #append>
                  <v-icon icon="mdi-check" size="14" aria-hidden="true" />
                </template>
              </v-list-item>
            </v-list>
          </v-menu>
          <AutoGrowTextarea
            :model-value="d.visual"
            class="hcard__input"
            :placeholder="t('app.strategy.scripts.hooks.visualPlaceholder')"
            :aria-label="t('app.strategy.scripts.hooks.visual')"
            :error="d.visual.length > limits.visual"
            :data-test="`script-hook-visual-${hook.id}`"
            @update:model-value="setVisual"
          />
          <span
            v-if="d.visual.length > limits.visual * 0.8"
            class="hcard__count"
            :class="{ 'hcard__count--over': d.visual.length > limits.visual }"
            >{{ d.visual.length }} / {{ limits.visual }}</span
          >
        </div>
      </div>

      <div class="hcard__row">
        <div class="hcard__label">
          <v-icon icon="mdi-format-text" size="13" aria-hidden="true" />{{
            t('app.strategy.scripts.hooks.overlay')
          }}
          <span class="hcard__opt">{{ t('app.strategy.scripts.hooks.optional') }}</span>
        </div>
        <ScriptTextColumns
          v-if="overlayShown || hasOverlay"
          :languages="columns"
          :texts="overlayTexts"
          :max="limits.overlay"
          :box-id="`hook-overlay-${hook.id}`"
          :placeholder="t('app.strategy.scripts.hooks.overlayPlaceholder')"
          :label="t('app.strategy.scripts.hooks.overlay')"
          :test-id="`script-hook-overlay-${hook.id}`"
          @edit="(language, v) => setText('overlay', language, v)"
        />
        <div v-else class="hcard__field">
          <button
            type="button"
            class="hcard__add-overlay"
            :data-test="`script-hook-add-overlay-${hook.id}`"
            @click="showOverlay"
          >
            + {{ t('app.strategy.scripts.hooks.addOverlay') }}
          </button>
        </div>
      </div>

      <ScriptImageField
        :scope="scope"
        :script-id="scriptId"
        block="hook"
        :block-id="hook.id"
        :images="images"
        :script-bytes="scriptBytes"
        :limits="imageLimits"
        :test-id="`script-hook-${hook.id}`"
        @changed="emit('imagesChanged')"
      />

      <BlockConflict
        v-if="block.status.value === 'conflict'"
        :theirs="theirsLines"
        :mine="mineLines"
        :test-id="`script-hook-conflict-${hook.id}`"
        @keep="block.keepMine()"
        @theirs="block.useTheirs()"
      />
    </div>
    <StrategyConfirmDialog
      v-model="confirmOpen"
      :title="t('app.strategy.scripts.hooks.deleteTitle', { letter: hook.letter })"
      :body="
        images.length > 0
          ? t('app.strategy.scripts.hooks.deleteBodyImages', { images: images.length })
          : t('app.strategy.scripts.hooks.deleteBody')
      "
      :confirm-label="t('app.strategy.scripts.hooks.deleteConfirm')"
      :busy="removing"
      :error="removeError"
      :test-id="`script-hook-delete-dialog-${hook.id}`"
      @confirm="doRemove"
    />
  </article>
</template>

<style scoped>
.hcard {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
}
.hcard__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  min-height: 18px;
}
.hcard__title {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.hcard__collapse,
.hcard--gone {
  border-color: rgb(var(--v-theme-error));
}
.hcard__dots {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  padding: 0;
  border: 0;
  border-radius: 6px;
  background: none;
  color: rgb(var(--v-theme-on-surface-variant));
  cursor: pointer;
}
.hcard__collapse:hover,
.hcard__dots:hover {
  background: rgb(var(--v-theme-surface-bright));
  color: rgb(var(--v-theme-on-surface));
}
.hcard__name {
  margin: 0;
  flex: none;
  font-size: 15px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
}
.hcard__summary {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 13px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.hcard__imgchip {
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
.hcard__tools {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: none;
}
.hcard--over {
  box-shadow: 0 -3px 0 0 rgb(var(--v-theme-primary));
}
.hcard--dragging {
  opacity: 0.5;
}
.hcard__body {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.hcard__row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  min-width: 0;
}
.hcard__label {
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
.hcard__opt {
  font-size: 10px;
  font-weight: 400;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.hcard__field {
  display: flex;
  flex: 1 1 0;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}
.hcard__lang {
  font-size: 10px;
  font-weight: 700;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.hcard__input {
  margin: 0;
}
.hcard__count {
  align-self: flex-end;
  font-size: 11px;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.hcard__count--over {
  color: rgb(var(--v-theme-error));
  font-weight: 600;
}
.hcard__shot {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  align-self: flex-start;
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
.hcard__shot--none {
  color: rgb(var(--v-theme-on-surface-variant));
  font-weight: 400;
}
.hcard__shot:hover {
  border-color: rgb(var(--v-theme-on-surface-variant));
}
.hcard__shots {
  min-width: 210px;
  padding: 6px;
  border-radius: 8px;
  background: rgb(var(--v-theme-surface-bright));
}
.hcard__shots :deep(.v-list-item) {
  min-height: 0;
  padding: 6px 10px;
  border-radius: 5px;
}
.hcard__shots :deep(.v-list-item-title) {
  font-size: 12px;
  line-height: normal;
}
.hcard__shots :deep(.v-list-item--active) {
  background: color-mix(in srgb, rgb(var(--v-theme-primary)) 10%, transparent);
  color: rgb(var(--v-theme-primary));
}
.hcard__shots :deep(.v-list-item--active .v-list-item__overlay) {
  opacity: 0;
}
.hcard__add-overlay {
  align-self: flex-start;
  padding: 2px 0;
  border: 0;
  background: none;
  font: inherit;
  font-size: 12px;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
  cursor: pointer;
}
.hcard__add-overlay:hover {
  color: rgb(var(--v-theme-on-surface));
}
@media (max-width: 599px) {
  .hcard__row {
    flex-direction: column;
    align-items: stretch;
    gap: 4px;
  }
  .hcard__label {
    width: auto;
  }
}
</style>
