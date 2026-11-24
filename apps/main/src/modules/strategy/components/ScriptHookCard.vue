<script setup lang="ts">
/**
 * PL-3.2a — one hook card (frame 13:3108, node 13:3457): header with "Hook A" and a menu (duplicate, delete) and a collapse
 * control; the audio hook, the visual hook (a shot type from the ten, plus a description) and the optional overlay. English only
 * until PL-3.2b; no reference images and no drag handle yet, so none is drawn. The card autosaves as one block with its own
 * revision. All text is plain text (textareas, and interpolations in the collapsed line and the conflict box).
 */
import type { ScriptHook, ShotType, WaveScript } from '@catalyst/api-client'
import { SHOT_TYPES } from '@catalyst/api-client'
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { strategyApi, type WaveScope } from '../api/strategy.api'
import { useBlockAutosave } from '../composables/useBlockAutosave'
import { useRegisterBlock } from '../composables/useSaveRegistry'
import { MENU_PROPS } from '../constants'
import { hookPayload, hookValue, sameHook, type HookValue } from '../scriptsLogic'
import StrategyConfirmDialog from './StrategyConfirmDialog.vue'
import BlockConflict from './BlockConflict.vue'
import BlockStatusLine from './BlockStatusLine.vue'

const props = defineProps<{
  hook: ScriptHook
  scriptId: string
  scope: WaveScope
  canDelete: boolean
  canAdd: boolean
  limits: { audio: number; overlay: number; visual: number }
  /** Deletes the hook on the server; resolves with a message when it could not, null when it is gone. */
  removeFn: () => Promise<string | null>
  debounceMs?: number
}>()
const emit = defineEmits<{
  saved: [script: WaveScript]
  duplicate: []
}>()
const { t } = useI18n()

const block = useBlockAutosave<HookValue>({
  remote: () => ({ value: hookValue(props.hook), revision: props.hook.revision }),
  save: async (value, revision) => {
    const res = (
      await strategyApi.saveHook(
        props.scope,
        props.scriptId,
        props.hook.id,
        hookPayload(value, revision),
      )
    ).data
    emit('saved', res)
    const mine = res.hooks.find((h) => h.id === props.hook.id)
    if (mine === undefined) throw new Error('hook missing in the answer')
    return { value: hookValue(mine), revision: mine.revision }
  },
  same: sameHook,
  fromConflict: (current) =>
    'position' in current ? { value: hookValue(current), revision: current.revision } : null,
  clone: (v) => ({ ...v }),
  debounceMs: props.debounceMs,
})
useRegisterBlock(`hook:${props.hook.id}`, { status: block.status, flush: block.flush })
watch(
  () => props.hook,
  () => block.syncRemote(),
)

const open = ref(true)
async function toggle(): Promise<void> {
  if (open.value) await block.flush()
  open.value = !open.value
}
defineExpose({ flush: block.flush })

const overlayShown = ref(props.hook.texts.overlay.en !== undefined)
async function showOverlay(): Promise<void> {
  overlayShown.value = true
  await nextTick()
  document.getElementById(`hook-overlay-${props.hook.id}`)?.focus()
}

function setShot(code: ShotType): void {
  block.edit((d) => {
    d.shot = code
  })
}
function setField(field: 'audio' | 'overlay' | 'visual', v: string): void {
  block.edit((d) => {
    d[field] = v
  })
}

const d = computed(() => block.draft.value)

// Delete: a hook with anything in it (as the person sees it, saved or not) asks first; an empty one goes at once.
const confirmOpen = ref(false)
const removing = ref(false)
const removeError = ref<string | null>(null)
const hasContent = computed(
  () =>
    d.value.audio !== '' ||
    d.value.visual !== '' ||
    d.value.overlay !== '' ||
    d.value.shot !== null,
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
const summary = computed(() => d.value.audio.trim().replace(/\s+/g, ' '))
function counter(len: number, max: number): boolean {
  return len > max * 0.8
}

const lineFor = (v: HookValue): { label: string; text: string }[] => [
  { label: t('app.strategy.scripts.hooks.audio'), text: v.audio },
  {
    label: t('app.strategy.scripts.hooks.visual'),
    text: [v.shot === null ? '' : t(`app.strategy.scripts.shot.${v.shot}`), v.visual]
      .filter((x) => x !== '')
      .join(' · '),
  },
  { label: t('app.strategy.scripts.hooks.overlay'), text: v.overlay },
]
const mineLines = computed(() => lineFor(d.value))
const theirsLines = computed(() => (block.theirs.value === null ? [] : lineFor(block.theirs.value)))
</script>

<template>
  <article class="hcard" :data-test="`script-hook-${hook.id}`" :data-letter="hook.letter">
    <header class="hcard__head">
      <div class="hcard__title">
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
              :disabled="!canAdd"
              prepend-icon="mdi-content-copy"
              :data-test="`script-hook-duplicate-${hook.id}`"
              @click="emit('duplicate')"
            >
              <v-list-item-title>{{ t('app.strategy.scripts.hooks.duplicate') }}</v-list-item-title>
            </v-list-item>
            <v-list-item
              :disabled="!canDelete"
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

    <div v-show="open" class="hcard__body">
      <div class="hcard__row">
        <div class="hcard__label">
          <v-icon icon="mdi-microphone-outline" size="13" aria-hidden="true" />{{
            t('app.strategy.scripts.hooks.audio')
          }}
        </div>
        <div class="hcard__field">
          <span class="hcard__lang">EN</span>
          <v-textarea
            :model-value="d.audio"
            variant="plain"
            density="compact"
            auto-grow
            rows="1"
            hide-details
            class="hcard__input"
            :placeholder="t('app.strategy.scripts.hooks.audioPlaceholder')"
            :aria-label="t('app.strategy.scripts.hooks.audio')"
            :error="d.audio.length > limits.audio"
            :data-test="`script-hook-audio-${hook.id}`"
            @update:model-value="(v: string) => setField('audio', v)"
          />
          <span
            v-if="counter(d.audio.length, limits.audio)"
            class="hcard__count"
            :class="{ 'hcard__count--over': d.audio.length > limits.audio }"
            >{{ d.audio.length }} / {{ limits.audio }}</span
          >
        </div>
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
          <v-textarea
            :model-value="d.visual"
            variant="plain"
            density="compact"
            auto-grow
            rows="1"
            hide-details
            class="hcard__input"
            :placeholder="t('app.strategy.scripts.hooks.visualPlaceholder')"
            :aria-label="t('app.strategy.scripts.hooks.visual')"
            :error="d.visual.length > limits.visual"
            :data-test="`script-hook-visual-${hook.id}`"
            @update:model-value="(v: string) => setField('visual', v)"
          />
          <span
            v-if="counter(d.visual.length, limits.visual)"
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
        <div class="hcard__field">
          <template v-if="overlayShown || d.overlay !== ''">
            <span class="hcard__lang">EN</span>
            <v-textarea
              :id="`hook-overlay-${hook.id}`"
              :model-value="d.overlay"
              variant="plain"
              density="compact"
              auto-grow
              rows="1"
              hide-details
              class="hcard__input"
              :placeholder="t('app.strategy.scripts.hooks.overlayPlaceholder')"
              :aria-label="t('app.strategy.scripts.hooks.overlay')"
              :error="d.overlay.length > limits.overlay"
              :data-test="`script-hook-overlay-${hook.id}`"
              @update:model-value="(v: string) => setField('overlay', v)"
            />
            <span
              v-if="counter(d.overlay.length, limits.overlay)"
              class="hcard__count"
              :class="{ 'hcard__count--over': d.overlay.length > limits.overlay }"
              >{{ d.overlay.length }} / {{ limits.overlay }}</span
            >
          </template>
          <button
            v-else
            type="button"
            class="hcard__add-overlay"
            :data-test="`script-hook-add-overlay-${hook.id}`"
            @click="showOverlay"
          >
            + {{ t('app.strategy.scripts.hooks.addOverlay') }}
          </button>
        </div>
      </div>

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
      :body="t('app.strategy.scripts.hooks.deleteBody')"
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
.hcard__tools {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: none;
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
  padding: 0;
}
.hcard__input :deep(.v-field__input) {
  padding: 0;
  min-height: 0;
  font-size: 13px;
  line-height: 19px;
  color: rgb(var(--v-theme-on-surface));
}
.hcard__input :deep(.v-field__field) {
  align-items: flex-start;
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
    gap: 4px;
  }
  .hcard__label {
    width: auto;
  }
}
</style>
