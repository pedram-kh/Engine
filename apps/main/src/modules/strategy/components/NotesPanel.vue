<script setup lang="ts">
/**
 * PL-2.5 — the amber "Notes" panel at the right of USPs, Personas and Concepts (frame 13:428, 300 px): a title with the
 * note icon in amber and an "Internal" pill with a lock, then the thread. Internal means agency users only.
 */
import { useI18n } from 'vue-i18n'

import type { NoteStep } from '../api/strategy.api'

import NotesThread from './NotesThread.vue'

defineProps<{ step: NoteStep; market: string | null }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
</script>

<template>
  <aside class="notes-panel" :aria-label="t('app.strategy.notes.title')" data-test="notes-panel">
    <header class="notes-panel__head">
      <h3 class="notes-panel__title">
        <v-icon icon="mdi-note-text-outline" size="16" aria-hidden="true" />
        {{ t('app.strategy.notes.title') }}
      </h3>
      <span class="notes-panel__pill" data-test="notes-internal">
        <v-icon icon="mdi-lock-outline" size="12" aria-hidden="true" />
        {{ t('app.strategy.notes.internal') }}
      </span>
    </header>
    <NotesThread :step="step" :market="market" @changed="emit('changed')" />
  </aside>
</template>

<style scoped>
/* Frame 13:764: amber 7% fill, amber 35% border, 18px padding, 10px radius, 10px gap; title 15/600 amber; the
   Internal pill is 11/500 on a 15% grey fill with a 12px lock. */
.notes-panel {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 18px;
  border: 1px solid color-mix(in srgb, rgb(var(--v-theme-warning)) 35%, transparent);
  border-radius: 10px;
  background: color-mix(in srgb, rgb(var(--v-theme-warning)) 7%, transparent);
  min-width: 0;
}
.notes-panel__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.notes-panel__title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-warning));
}
.notes-panel__pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 2px 8px;
  border-radius: 9999px;
  background: color-mix(in srgb, rgb(var(--v-theme-on-surface-variant)) 15%, transparent);
  font-size: 11px;
  font-weight: 500;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
  white-space: nowrap;
}
</style>
