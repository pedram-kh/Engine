<script setup lang="ts">
/**
 * PL-2.5 — the amber "Notes" panel at the right of USPs, Personas and Concepts (frame 13:428, 300 px): a title with the
 * note icon in amber and an "Internal" pill with a lock, then the thread. Internal means agency users only.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import type { NoteStep } from '../api/strategy.api'

import NotesThread from './NotesThread.vue'

defineProps<{ step: NoteStep; market: string | null; expanded?: boolean }>()
const emit = defineEmits<{ changed: []; expand: [] }>()
const draft = defineModel<string>('draft', { default: '' })
const { t } = useI18n()

// Wide: the panel's height is what the window has left below the panel's place on the page, so the composer is on
// screen when the page opens, and it is never taller than the window once it has stuck under the app bar. It is
// measured from the panel's place in the DOCUMENT (not its place on screen), so scrolling never changes it and the
// page height stays put.
const root = ref<HTMLElement | null>(null)
const maxHeight = ref<string | null>(null)
let timer: ReturnType<typeof setTimeout> | undefined
function measure(): void {
  const cell = root.value?.parentElement
  if (!cell || window.innerWidth < 1100) {
    maxHeight.value = null
    return
  }
  const docTop = cell.getBoundingClientRect().top + window.scrollY
  const room = window.innerHeight - docTop - 16
  maxHeight.value = `${Math.min(window.innerHeight - 97, Math.max(360, Math.floor(room)))}px`
}
onMounted(() => {
  measure()
  timer = setTimeout(measure, 600)
  window.addEventListener('resize', measure)
})
onBeforeUnmount(() => {
  window.removeEventListener('resize', measure)
  clearTimeout(timer)
})

const expandBtn = ref<{ $el: HTMLElement } | null>(null)
/** The expanded view hands focus back here when it closes. */
function focusExpand(): void {
  expandBtn.value?.$el.focus()
}
defineExpose({ focusExpand })
</script>

<template>
  <aside
    ref="root"
    class="notes-panel"
    :style="maxHeight !== null ? { maxHeight } : undefined"
    :aria-label="t('app.strategy.notes.title')"
    data-test="notes-panel"
  >
    <header class="notes-panel__head">
      <h3 class="notes-panel__title">
        <v-icon icon="mdi-note-text-outline" size="16" aria-hidden="true" />
        {{ t('app.strategy.notes.title') }}
      </h3>
      <span class="notes-panel__pill" data-test="notes-internal">
        <v-icon icon="mdi-lock-outline" size="12" aria-hidden="true" />
        {{ t('app.strategy.notes.internal') }}
      </span>
      <v-btn
        ref="expandBtn"
        icon="mdi-arrow-expand-all"
        variant="text"
        size="x-small"
        class="notes-panel__expand"
        :aria-label="t('app.strategy.notes.expand')"
        :aria-expanded="expanded === true"
        data-test="notes-expand"
        @click="emit('expand')"
      />
    </header>
    <!-- While the expanded view is open the thread lives there (one composer, one draft); this panel keeps its header. -->
    <NotesThread
      v-if="expanded !== true"
      v-model:draft="draft"
      :step="step"
      :market="market"
      @changed="emit('changed')"
    />
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
  min-height: 0;
  /* PL-2.5 walk N1 (no frame): never taller than the window: the header stays on top, the list scrolls inside, the
     composer stays at the bottom, and the panel stays in view while the cards beside it scroll. 65px is the app bar. */
  position: sticky;
  top: 81px;
  max-height: calc(100dvh - 65px - 32px);
}
.notes-panel__head {
  flex: none;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.notes-panel__title {
  flex: 1;
}
/* Narrow: the panel sits below the step (no room beside it), so it is not sticky (it would cover the cards); its
   height is capped instead, and the list scrolls inside it. */
@media (max-width: 1099px) {
  .notes-panel {
    position: static;
    max-height: min(560px, 80dvh);
  }
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
