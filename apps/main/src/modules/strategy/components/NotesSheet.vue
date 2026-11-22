<script setup lang="ts">
/**
 * PL-2.5 — the matrix has no room for a column of notes beside its table, so its notes open in a side sheet from the
 * "Notes (n)" button. The thread inside is mounted only while the sheet is open, so every open starts from a fresh
 * load and a quick close and reopen leaves nothing behind.
 */
import { onBeforeUnmount, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import type { NoteStep } from '../api/strategy.api'

import NotesThread from './NotesThread.vue'

const props = defineProps<{
  modelValue: boolean
  step: NoteStep
  market: string | null
  marketName: string
}>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; changed: [] }>()
const { t } = useI18n()

// A temporary drawer closes on its scrim but not on Escape; the sheet must close from the keyboard too. An open
// dialog (the delete confirm) takes the key first.
function onKey(e: KeyboardEvent): void {
  if (e.key !== 'Escape' || e.defaultPrevented) return
  if (document.querySelector('.v-dialog.v-overlay--active') !== null) return
  emit('update:modelValue', false)
}
watch(
  () => props.modelValue,
  (open) => {
    if (open) document.addEventListener('keydown', onKey)
    else document.removeEventListener('keydown', onKey)
  },
  { immediate: true },
)
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <v-navigation-drawer
    :model-value="modelValue"
    location="right"
    temporary
    width="360"
    class="notes-sheet"
    data-test="notes-sheet"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <div class="notes-sheet__body">
      <header class="notes-sheet__head">
        <h3 class="notes-sheet__title" data-test="notes-sheet-title">
          <v-icon icon="mdi-note-text-outline" size="16" aria-hidden="true" />
          {{ t('app.strategy.notes.sheetTitle', { market: marketName }) }}
        </h3>
        <span class="notes-sheet__pill">
          <v-icon icon="mdi-lock-outline" size="12" aria-hidden="true" />
          {{ t('app.strategy.notes.internal') }}
        </span>
        <v-btn
          icon="mdi-close"
          variant="text"
          size="small"
          :aria-label="t('app.strategy.close')"
          data-test="notes-sheet-close"
          @click="emit('update:modelValue', false)"
        />
      </header>
      <NotesThread v-if="modelValue" :step="step" :market="market" @changed="emit('changed')" />
    </div>
  </v-navigation-drawer>
</template>

<style scoped>
.notes-sheet__body {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 18px;
}
.notes-sheet__head {
  display: flex;
  align-items: center;
  gap: 8px;
}
.notes-sheet__title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  flex: 1;
  min-width: 0;
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  color: rgb(var(--v-theme-warning));
  overflow-wrap: anywhere;
}
.notes-sheet__pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 2px 8px;
  border-radius: 9999px;
  background: color-mix(in srgb, rgb(var(--v-theme-on-surface-variant)) 15%, transparent);
  font-size: 11px;
  font-weight: 500;
  color: rgb(var(--v-theme-on-surface-variant));
  white-space: nowrap;
}
</style>
