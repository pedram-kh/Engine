<script setup lang="ts">
/**
 * The horizontal columns container (Sprint 12 Chunk 2). Hosts the SECOND DnD
 * surface (D-6): the columns themselves are draggable (`group="board-columns"`,
 * a drag handle on the header) → `reorder` with the full ordered ULID list →
 * PATCH columns/reorder. This is a SEPARATE vuedraggable group from the cards'
 * `board-cards`, so the two surfaces never fight (no nested-draggable capture).
 *
 * Column dragging + the "Add column" button are gated on `canEditColumns` (board
 * CONFIG = admin + manager). Card dragging inside each column is independent
 * (the `invite` execute ability) and handled by BoardColumn.
 */

import type { BoardCardResource, BoardColumnResource } from '@catalyst/api-client'
import draggable from 'vuedraggable'
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BoardColumn from './BoardColumn.vue'

const props = defineProps<{
  columns: BoardColumnResource[]
  cardsByColumn: Record<string, BoardCardResource[]>
  canEditColumns: boolean
}>()

const emit = defineEmits<{
  move: [payload: { cardId: string; toColumnId: string }]
  reorder: [orderedIds: string[]]
  'open-card': [card: BoardCardResource]
  'edit-column': [column: BoardColumnResource]
  'delete-column': [column: BoardColumnResource]
  'add-column': []
}>()

const { t } = useI18n()

// vuedraggable mutates the bound list in place; keep a local copy synced from
// the store so the store stays the single source of truth (optimistic reorder +
// revert both flow store → props → here).
const localColumns = ref<BoardColumnResource[]>([...props.columns])
watch(
  () => props.columns,
  (next) => {
    localColumns.value = [...next]
  },
)

function onColumnChange(): void {
  emit(
    'reorder',
    localColumns.value.map((c) => c.id),
  )
}

defineExpose({ onColumnChange, localColumns })
</script>

<template>
  <div class="board-columns-root">
    <draggable
      v-model="localColumns"
      item-key="id"
      group="board-columns"
      handle=".board-column__drag"
      :disabled="!canEditColumns"
      class="d-flex align-stretch ga-4 board-columns"
      data-test="board-columns"
      @change="onColumnChange"
    >
      <template #item="{ element }">
        <BoardColumn
          :column="element"
          :cards="cardsByColumn[element.id] ?? []"
          :can-edit="canEditColumns"
          @move="(p) => emit('move', p)"
          @open-card="(c) => emit('open-card', c)"
          @edit="(c) => emit('edit-column', c)"
          @delete="(c) => emit('delete-column', c)"
        />
      </template>
    </draggable>

    <button
      v-if="canEditColumns"
      type="button"
      class="board-columns__add"
      data-test="board-add-column"
      @click="emit('add-column')"
    >
      <v-icon icon="mdi-plus" size="small" />
      <span class="text-body-2">{{ t('app.campaigns.board.column.add') }}</span>
    </button>
  </div>
</template>

<style scoped>
.board-columns-root {
  display: flex;
  align-items: stretch;
  gap: 16px;
  flex: 1 1 auto;
  min-height: 0;
  overflow-x: auto;
  overflow-y: hidden;
  /* keep the horizontal scrollbar clear of the last row of cards */
  padding-bottom: 8px;
}
.board-columns {
  align-items: stretch;
}
.board-columns__add {
  flex: 0 0 260px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  border-radius: 12px;
  border: 1px dashed rgba(var(--v-theme-on-surface), 0.16);
  color: rgba(var(--v-theme-on-surface), 0.7);
  background: transparent;
  cursor: pointer;
  transition:
    background-color 0.15s ease,
    border-color 0.15s ease;
}
.board-columns__add:hover {
  background: rgba(var(--v-theme-on-surface), 0.04);
  border-color: rgba(var(--v-theme-on-surface), 0.28);
}
</style>
