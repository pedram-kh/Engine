<script setup lang="ts">
/**
 * PL-2.2 — the editor of one cell (the frame draws only the level menu; the two text fields and the buttons are
 * built from the design system). A popover anchored to the clicked cell, a bottom sheet under 720 px.
 *
 * Level: the three rows of frame 04b (dot, label, hint; the selected row tinted). Rationale and message line: 140
 * characters each. A Priority or Support cell cannot be saved without a message line (Q1); a Low cell can. Save sends
 * only the fields that changed; with nothing changed it just closes. "Save and next" saves and opens the next cell in
 * reading order. Clear deletes the cell. Errors sit above the buttons and wrap: nothing is cut off.
 *
 * The inside is keyed by a counter that moves on every open, so a quick close and reopen starts clean.
 */
import type { MatrixCell, MatrixLevel, StrategyLimits } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'
import { VBottomSheet, VMenu } from 'vuetify/components'

import { fieldErrorsOf, strategyErrorMessage } from '../strategyErrors'
import { changeFor, fieldValue, LEVELS, needsMessageLine, type EditorState } from '../matrixLogic'

const props = defineProps<{
  modelValue: boolean
  /** The clicked cell, the popover's anchor. */
  anchor: HTMLElement | null
  cell: MatrixCell | null
  /** What the editor says it edits: "{usp} · {persona}". */
  heading: string
  limits: StrategyLimits
  /** Is there a cell after this one (shows "Save and next")? */
  hasNext: boolean
  /** Save one change; resolves when the server has answered. Throws the API error. */
  save: (state: EditorState) => Promise<void>
  clear: () => Promise<void>
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  /** After a save (or Clear) succeeded: open the next cell, or just close. */
  done: [next: boolean]
}>()

const { t } = useI18n()
const { width } = useDisplay()
const narrow = computed(() => width.value < 720)

const session = ref(0)
const level = ref<MatrixLevel | null>(null)
const rationale = ref('')
const messageLine = ref('')
const busy = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})
const touched = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    session.value++
    busy.value = false
    error.value = null
    fieldErrors.value = {}
    touched.value = false
    if (!open) return
    level.value = props.cell?.level ?? null
    rationale.value = props.cell?.rationale ?? ''
    messageLine.value = props.cell?.message_line ?? ''
  },
  { immediate: true },
)

const max = computed(() => props.limits.matrix_line)
const isSet = computed(() => props.cell?.level != null)
const lineMissing = computed(
  () => needsMessageLine(level.value) && fieldValue(messageLine.value) === null,
)
const rationaleLong = computed(() => Array.from(rationale.value).length > max.value)
const lineLong = computed(() => Array.from(messageLine.value).length > max.value)
const canSave = computed(
  () =>
    level.value !== null &&
    !lineMissing.value &&
    !rationaleLong.value &&
    !lineLong.value &&
    !busy.value,
)

const lineError = computed<string | null>(() => {
  if (fieldErrors.value['message_line']) return fieldErrors.value['message_line']
  if (lineLong.value) return t('app.strategy.validation.tooLong', { max: max.value })
  if (lineMissing.value) return t('app.strategy.matrix.editor.lineRequired')
  return null
})
const rationaleError = computed<string | null>(() => {
  if (fieldErrors.value['rationale']) return fieldErrors.value['rationale']
  if (rationaleLong.value) return t('app.strategy.validation.tooLong', { max: max.value })
  return null
})

function close(): void {
  if (!busy.value) emit('update:modelValue', false)
}

async function run(action: () => Promise<void>, next: boolean): Promise<void> {
  busy.value = true
  error.value = null
  fieldErrors.value = {}
  try {
    await action()
    emit('done', next)
  } catch (err) {
    const fields = fieldErrorsOf(err)
    // The server names a cell field as `cells.0.message_line`: keep the last segment.
    for (const [k, v] of Object.entries(fields)) {
      const name = k.split('.').pop() ?? k
      if (name === 'message_line' || name === 'rationale' || name === 'level') {
        fieldErrors.value[name] = v
      }
    }
    error.value = strategyErrorMessage(err, t)
    if (Object.keys(fieldErrors.value).length > 0) error.value = null
  } finally {
    busy.value = false
  }
}

function state(): EditorState {
  return { level: level.value, rationale: rationale.value, messageLine: messageLine.value }
}

function submit(next: boolean): void {
  touched.value = true
  if (!canSave.value) return
  void run(async () => {
    // Nothing changed: no request. `changeFor` is the single reading of that rule.
    const unchanged =
      props.cell !== null &&
      props.cell.level !== null &&
      changeFor('p', 'u', props.cell, state()) === null
    if (unchanged) return
    await props.save(state())
  }, next)
}

function doClear(): void {
  void run(() => props.clear(), false)
}
</script>

<template>
  <component
    :is="narrow ? VBottomSheet : VMenu"
    :model-value="modelValue"
    v-bind="
      narrow
        ? { inset: true, contentClass: 'mx-editor-overlay mx-editor-overlay--sheet' }
        : {
            target: anchor ?? undefined,
            location: 'bottom start',
            offset: 6,
            closeOnContentClick: false,
            zIndex: 2500,
            contentClass: 'mx-editor-overlay',
            maxHeight: 'calc(100dvh - 24px)',
          }
    "
    @update:model-value="
      (v: boolean) => {
        if (!v) close()
      }
    "
  >
    <div
      :key="session"
      class="mx-editor"
      role="dialog"
      :aria-label="heading"
      data-test="matrix-editor"
      @keydown.esc.stop="close"
    >
      <p class="mx-editor__heading" data-test="matrix-editor-heading">{{ heading }}</p>

      <div
        class="mx-editor__levels"
        role="radiogroup"
        :aria-label="t('app.strategy.matrix.editor.level')"
      >
        <button
          v-for="l in LEVELS"
          :key="l"
          type="button"
          role="radio"
          class="mx-level"
          :class="[`mx-level--${l}`, { 'mx-level--on': level === l }]"
          :aria-checked="level === l"
          :data-test="`matrix-level-${l}`"
          @click="level = l"
        >
          <span class="mx-level__dot" aria-hidden="true" />
          <span class="mx-level__label">{{ t(`app.strategy.matrix.level.${l}`) }}</span>
          <span class="mx-level__hint">{{ t(`app.strategy.matrix.legend.${l}Hint`) }}</span>
        </button>
      </div>

      <label class="mx-field">
        <span class="mx-field__label">{{ t('app.strategy.matrix.editor.rationale') }}</span>
        <textarea
          v-model="rationale"
          class="mx-field__input"
          rows="2"
          data-test="matrix-rationale-input"
          :aria-invalid="rationaleError !== null"
        />
        <span class="mx-field__foot">
          <span v-if="rationaleError" class="mx-field__error" data-test="matrix-rationale-error">{{
            rationaleError
          }}</span>
          <span class="mx-field__count" :class="{ 'mx-field__count--over': rationaleLong }">{{
            t('app.strategy.matrix.editor.counter', {
              count: Array.from(rationale).length,
              max,
            })
          }}</span>
        </span>
      </label>

      <label class="mx-field">
        <span class="mx-field__label">{{ t('app.strategy.matrix.editor.messageLine') }}</span>
        <textarea
          v-model="messageLine"
          class="mx-field__input"
          rows="2"
          data-test="matrix-line-input"
          :aria-invalid="lineError !== null && (touched || lineLong)"
        />
        <span class="mx-field__foot">
          <span
            v-if="lineError && (touched || lineLong || fieldErrors['message_line'])"
            class="mx-field__error"
            data-test="matrix-line-error"
            >{{ lineError }}</span
          >
          <span v-else-if="lineMissing" class="mx-field__hint" data-test="matrix-line-hint">{{
            t('app.strategy.matrix.editor.lineRequired')
          }}</span>
          <span class="mx-field__count" :class="{ 'mx-field__count--over': lineLong }">{{
            t('app.strategy.matrix.editor.counter', {
              count: Array.from(messageLine).length,
              max,
            })
          }}</span>
        </span>
      </label>

      <p v-if="error" class="mx-editor__error" role="alert" data-test="matrix-editor-error">
        {{ error }}
      </p>
      <p v-if="fieldErrors['level']" class="mx-editor__error" role="alert">
        {{ fieldErrors['level'] }}
      </p>

      <div class="mx-editor__actions">
        <v-btn
          size="small"
          color="primary"
          variant="flat"
          :disabled="!canSave"
          :loading="busy"
          data-test="matrix-save"
          @click="submit(false)"
        >
          {{ t('app.strategy.save') }}
        </v-btn>
        <v-btn
          v-if="hasNext"
          size="small"
          variant="outlined"
          :disabled="!canSave"
          data-test="matrix-save-next"
          @click="submit(true)"
        >
          {{ t('app.strategy.matrix.editor.saveNext') }}
        </v-btn>
        <v-btn
          size="small"
          variant="text"
          :disabled="busy"
          data-test="matrix-cancel"
          @click="close"
        >
          {{ t('app.strategy.cancel') }}
        </v-btn>
        <v-spacer />
        <v-btn
          v-if="isSet"
          size="small"
          variant="text"
          color="error"
          :disabled="busy"
          data-test="matrix-clear"
          @click="doClear"
        >
          {{ t('app.strategy.matrix.editor.clear') }}
        </v-btn>
      </div>
    </div>
  </component>
</template>

<style>
/*
 * Not scoped: the overlay is teleported out of the component. The level rows are frame 04b's menu (fill, border,
 * radius 8, padding 6, gap 2, the 12 / 32 shadow; items padding 10 / 7, radius 6, dot 8, label 13 / 500, hint 12).
 */
.mx-editor-overlay.v-overlay__content {
  width: 360px;
  max-width: calc(100vw - 24px);
}
.mx-editor-overlay--sheet.v-overlay__content {
  width: 100%;
  max-width: 640px;
  max-height: 92dvh;
}
.mx-editor {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 14px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  box-shadow: 0 12px 32px var(--agency-matrix-shadow);
  max-height: inherit;
  overflow-y: auto;
}
.mx-editor__heading {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
  line-height: normal;
  overflow-wrap: anywhere;
}
.mx-editor__levels {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 6px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 8px;
  background: rgb(var(--v-theme-surface));
}
.mx-level {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 7px 10px;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}
.mx-level:hover {
  background: color-mix(in srgb, rgb(var(--v-theme-on-surface)) 5%, transparent);
}
.mx-level:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}
.mx-level--on {
  background: color-mix(in srgb, rgb(var(--v-theme-primary)) 8%, transparent);
}
.mx-level__dot {
  flex: none;
  width: 8px;
  height: 8px;
  border-radius: 9999px;
}
.mx-level--priority .mx-level__dot {
  background: var(--mx-priority);
}
.mx-level--support .mx-level__dot {
  background: var(--mx-support);
}
.mx-level--low .mx-level__dot {
  background: var(--mx-low);
}
.mx-level__label {
  font-size: 13px;
  font-weight: 500;
  line-height: normal;
}
.mx-level__hint {
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary));
}
.mx-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.mx-field__label {
  font-size: 12px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
}
.mx-field__input {
  width: 100%;
  resize: vertical;
  min-height: 52px;
  padding: 8px 10px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 8px;
  background: rgb(var(--v-theme-surface-bright));
  color: rgb(var(--v-theme-on-surface));
  font: inherit;
  font-size: 13px;
  line-height: 19px;
}
.mx-field__input:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -1px;
}
.mx-field__input[aria-invalid='true'] {
  border-color: rgb(var(--v-theme-error));
}
.mx-field__foot {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  font-size: 12px;
  line-height: 16px;
}
.mx-field__count {
  margin-left: auto;
  flex: none;
  color: rgb(var(--v-theme-text-tertiary));
}
.mx-field__count--over,
.mx-field__error {
  color: rgb(var(--v-theme-error));
}
.mx-field__hint {
  color: rgb(var(--v-theme-text-tertiary));
}
.mx-editor__error {
  margin: 0;
  padding: 8px 10px;
  border: 1px solid rgb(var(--v-theme-error));
  border-radius: 8px;
  font-size: 13px;
  line-height: 18px;
  color: rgb(var(--v-theme-error));
  overflow-wrap: anywhere;
}
.mx-editor__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
</style>
