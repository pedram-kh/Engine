<script setup lang="ts">
/**
 * PL-3.2b — the grip of a hook or a line (frame: a 14 px grip icon at the left of the header). Two ways to move the block: drag it
 * (the grip is the only draggable part, so text can still be selected), or focus the grip and press the up and down arrow keys. The
 * block's menu has "Move up" and "Move down" too. The list announces every move.
 */
import { useI18n } from 'vue-i18n'

defineProps<{ label: string; testId: string; disabled?: boolean }>()
const emit = defineEmits<{
  move: [delta: -1 | 1]
  dragstart: [event: DragEvent]
  dragend: []
}>()
const { t } = useI18n()

function onKey(e: KeyboardEvent): void {
  if (e.key === 'ArrowUp') {
    e.preventDefault()
    emit('move', -1)
  } else if (e.key === 'ArrowDown') {
    e.preventDefault()
    emit('move', 1)
  }
}
</script>

<template>
  <button
    type="button"
    class="grip"
    :draggable="!disabled"
    :aria-label="label"
    :aria-description="t('app.strategy.scripts.order.gripHint')"
    :data-test="testId"
    @keydown="onKey"
    @dragstart="emit('dragstart', $event)"
    @dragend="emit('dragend')"
  >
    <v-icon icon="mdi-drag-vertical" size="14" aria-hidden="true" />
  </button>
</template>

<style scoped>
.grip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 18px;
  height: 22px;
  padding: 0;
  border: 0;
  border-radius: 6px;
  background: none;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
  cursor: grab;
}
.grip:hover {
  background: rgb(var(--v-theme-surface-bright));
  color: rgb(var(--v-theme-on-surface));
}
.grip:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
}
.grip:active {
  cursor: grabbing;
}
</style>
