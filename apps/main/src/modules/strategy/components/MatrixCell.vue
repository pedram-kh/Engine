<script setup lang="ts">
/**
 * PL-2.2 — one cell of the messaging matrix (frame 13:1375): a level pill with a chevron, the rationale and the
 * message line in a yellow-edged box. Priority tints the whole cell green; Low is muted. A cell that is not set shows
 * a dashed "Not set" pill (no frame for it). A pair with a manually added angle carries a small note: when its text
 * differs from the message line of a Priority or Support cell the note says so and offers "Use the matrix line"
 * (no frame; built from the cell's own note style); equal texts show nothing. The cell is one
 * button that opens the editor; every string from the server is drawn with `{{ }}`, never as HTML.
 */
import type { MatrixCell } from '@catalyst/api-client'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { manualNote, type ManualAngle } from '../matrixLogic'

const props = defineProps<{
  cell: MatrixCell | null
  /** The accessible name: "{usp}, {persona}: {level}". */
  label: string
  /** The editor is open on this cell. */
  active: boolean
  /** The hand-written angle on this pair, if there is one. */
  manual: ManualAngle | null
}>()

const emit = defineEmits<{ open: [el: HTMLElement]; useLine: [] }>()
const { t } = useI18n()

const level = computed(() => props.cell?.level ?? null)
const note = computed(() => manualNote(props.cell, props.manual))

function open(e: Event): void {
  emit('open', e.currentTarget as HTMLElement)
}
</script>

<template>
  <div
    class="mx-cell"
    :class="[
      level ? `mx-cell--${level}` : 'mx-cell--empty',
      { 'mx-cell--active': active, 'mx-cell--has-manual': note !== 'none' },
    ]"
    role="button"
    tabindex="0"
    :aria-label="label"
    aria-haspopup="dialog"
    :aria-expanded="active"
    data-test="matrix-cell"
    :data-level="level ?? 'none'"
    @click="open"
    @keydown.enter.prevent="open"
    @keydown.space.prevent="open"
  >
    <span class="mx-pill" data-test="matrix-pill">
      <span class="mx-pill__label">{{
        level ? t(`app.strategy.matrix.level.${level}`) : t('app.strategy.matrix.notSet')
      }}</span>
      <v-icon icon="mdi-chevron-down" size="12" aria-hidden="true" />
    </span>
    <template v-if="level">
      <p v-if="cell?.rationale" class="mx-cell__rationale" data-test="matrix-rationale">
        {{ cell.rationale }}
      </p>
      <div v-if="cell?.message_line" class="mx-cell__line" data-test="matrix-line">
        <p class="mx-cell__line-text">“{{ cell.message_line }}”</p>
      </div>
      <p v-if="note === 'kept'" class="mx-cell__note" data-test="matrix-manual-note">
        <v-icon icon="mdi-information-outline" size="13" aria-hidden="true" />
        <span>{{ t('app.strategy.matrix.handNote') }}</span>
      </p>
      <div
        v-else-if="note === 'conflict'"
        class="mx-cell__conflict"
        data-test="matrix-conflict-note"
      >
        <p class="mx-cell__note mx-cell__note--conflict">
          <v-icon icon="mdi-alert-outline" size="13" aria-hidden="true" />
          <span>{{ t('app.strategy.matrix.conflictNote') }}</span>
        </p>
        <button
          type="button"
          class="mx-cell__action"
          data-test="matrix-use-line"
          @click.stop="emit('useLine')"
          @keydown.stop
        >
          {{ t('app.strategy.matrix.useLine') }}
        </button>
      </div>
    </template>
  </div>
</template>

<style scoped>
/*
 * Values from frame 13:1375: cell padding 16 / 20 and gap 10; the pill radius 6, padding 4 / 8 / 4 / 10, gap 6, label
 * 11 / 700 with 0.6 tracking, chevron 12; rationale 13 / 19; the message box radius 6, padding 9 / 12, a 1.5 px yellow
 * border (80 % on Priority and Support, 35 % on Low), yellow 6 % fill, text 13 italic. The Priority cell is tinted
 * a 4 % green; a Low cell's rationale is muted. The level colours are the agency brand green and blue
 * (themed) and the neutral grey of the frame.
 */
.mx-cell {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 10px;
  padding: 16px 20px;
  border-left: 1px solid rgb(var(--v-theme-border-color));
  border-top: 1px solid rgb(var(--v-theme-border-color));
  min-width: 0;
  cursor: pointer;
  outline: none;
}
.mx-cell:hover,
.mx-cell--active {
  background: color-mix(in srgb, rgb(var(--v-theme-on-surface)) 4%, transparent);
}
.mx-cell--priority {
  background: var(--mx-priority-tint);
}
.mx-cell--priority:hover,
.mx-cell--priority.mx-cell--active {
  background: color-mix(in srgb, rgb(var(--v-theme-on-surface)) 4%, var(--mx-priority-tint));
}
.mx-cell:focus-visible {
  box-shadow: inset 0 0 0 2px rgb(var(--v-theme-primary));
}
.mx-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 8px 4px 10px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 700;
  line-height: normal;
  letter-spacing: 0.6px;
  text-transform: uppercase;
  white-space: nowrap;
}
.mx-cell--priority .mx-pill {
  background: var(--mx-priority-fill);
  color: var(--mx-priority);
}
.mx-cell--support .mx-pill {
  background: var(--mx-support-fill);
  color: var(--mx-support);
}
.mx-cell--low .mx-pill {
  background: var(--mx-low-fill);
  color: rgb(var(--v-theme-on-surface-variant));
}
.mx-cell--empty .mx-pill {
  border: 1px dashed rgb(var(--v-theme-border-color));
  padding: 3px 7px 3px 9px;
  color: rgb(var(--v-theme-text-tertiary));
  font-weight: 700;
}
.mx-cell__rationale {
  margin: 0;
  width: 100%;
  font-size: 13px;
  line-height: 19px;
  color: rgb(var(--v-theme-on-surface));
  overflow-wrap: anywhere;
}
.mx-cell--low .mx-cell__rationale {
  color: rgb(var(--v-theme-text-tertiary));
}
.mx-cell__line {
  width: 100%;
  padding: 9px 12px;
  border: 1.5px solid var(--mx-yellow-strong);
  border-radius: 6px;
  background: var(--mx-yellow-fill);
}
.mx-cell--low .mx-cell__line {
  border-color: var(--mx-yellow-soft);
}
.mx-cell__line-text {
  margin: 0;
  font-size: 13px;
  font-style: italic;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
  overflow-wrap: anywhere;
}
.mx-cell--low .mx-cell__line-text {
  color: rgb(var(--v-theme-on-surface-variant));
}
.mx-cell__note {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  color: rgb(var(--v-theme-text-tertiary));
}
.mx-cell__note .v-icon {
  flex: none;
  margin-top: 1px;
}
.mx-cell__conflict {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
  width: 100%;
}
.mx-cell__note--conflict {
  color: rgb(var(--v-theme-warning-text));
}
.mx-cell__action {
  padding: 4px 10px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 6px;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  font: inherit;
  font-size: 12px;
  font-weight: 600;
  line-height: normal;
  cursor: pointer;
}
.mx-cell__action:hover {
  background: color-mix(in srgb, rgb(var(--v-theme-on-surface)) 6%, rgb(var(--v-theme-surface)));
}
.mx-cell__action:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 1px;
}
</style>
