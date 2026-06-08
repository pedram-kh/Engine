<script setup lang="ts">
/**
 * One board column (Sprint 12 Chunk 2). Header (colour dot + name + count +
 * config menu) over a vuedraggable card list — the FIRST DnD surface (D-5,
 * `group="board-cards"`). A card dropped from another column fires `@change`
 * with an `added` entry → the column emits `move`, which BoardView turns into the
 * optimistic store move (D-3).
 *
 * The column-config menu (rename / recolor / delete) is gated on `canEdit`
 * (board CONFIG = admin + manager); a staff member can still DRAG cards
 * (the `invite` execute ability) but sees no config affordances.
 */

import type { BoardCardResource, BoardColumnResource } from '@catalyst/api-client'
import draggable from 'vuedraggable'
import { useI18n } from 'vue-i18n'

import { boardColorHex } from '../support/boardTokens'
import BoardCard from './BoardCard.vue'

const props = defineProps<{
  column: BoardColumnResource
  cards: BoardCardResource[]
  canEdit: boolean
}>()

const emit = defineEmits<{
  move: [payload: { cardId: string; toColumnId: string }]
  'open-card': [card: BoardCardResource]
  edit: [column: BoardColumnResource]
  delete: [column: BoardColumnResource]
}>()

const { t } = useI18n()

interface CardChangeEvent {
  added?: { element: BoardCardResource; newIndex: number }
  removed?: { element: BoardCardResource; oldIndex: number }
  moved?: { element: BoardCardResource; oldIndex: number; newIndex: number }
}

/**
 * The drop trigger: only the `added` event on the DESTINATION column is a move.
 * `removed` (the origin side) + `moved` (intra-column, P2/inert) are ignored —
 * the optimistic re-home is driven once, from the destination.
 */
function onCardChange(evt: CardChangeEvent): void {
  if (evt.added !== undefined) {
    emit('move', { cardId: evt.added.element.id, toColumnId: props.column.id })
  }
}

// Exposed so the unit spec can drive the drop deterministically (real DnD is
// not exercisable in jsdom).
defineExpose({ onCardChange })
</script>

<template>
  <div class="board-column" :data-test="`board-column-${column.id}`">
    <div class="board-column__header d-flex align-center ga-2 mb-2">
      <v-icon
        v-if="canEdit"
        icon="mdi-drag"
        size="small"
        class="board-column__drag"
        :data-test="`board-column-drag-${column.id}`"
      />
      <span
        class="board-column__dot"
        :style="{ backgroundColor: boardColorHex(column.attributes.color_token) }"
        aria-hidden="true"
      />
      <span class="text-subtitle-2" :data-test="`board-column-name-${column.id}`">
        {{ column.attributes.name }}
      </span>
      <v-chip size="x-small" variant="tonal" :data-test="`board-column-count-${column.id}`">
        {{ cards.length }}
      </v-chip>
      <v-icon
        v-if="column.attributes.is_terminal_success"
        icon="mdi-flag-checkered"
        size="x-small"
        color="success"
        :data-test="`board-column-terminal-success-${column.id}`"
      />
      <v-icon
        v-if="column.attributes.is_terminal_failure"
        icon="mdi-flag-remove"
        size="x-small"
        color="error"
        :data-test="`board-column-terminal-failure-${column.id}`"
      />
      <v-spacer />
      <v-menu v-if="canEdit">
        <template #activator="{ props: menuProps }">
          <v-btn
            v-bind="menuProps"
            icon="mdi-dots-vertical"
            variant="text"
            size="x-small"
            :data-test="`board-column-menu-${column.id}`"
          />
        </template>
        <v-list density="compact">
          <v-list-item :data-test="`board-column-edit-${column.id}`" @click="emit('edit', column)">
            <v-list-item-title>{{ t('app.campaigns.board.column.edit') }}</v-list-item-title>
          </v-list-item>
          <v-list-item
            :data-test="`board-column-delete-${column.id}`"
            @click="emit('delete', column)"
          >
            <v-list-item-title>{{ t('app.campaigns.board.column.delete') }}</v-list-item-title>
          </v-list-item>
        </v-list>
      </v-menu>
    </div>

    <draggable
      :list="cards"
      item-key="id"
      group="board-cards"
      class="board-column__list d-flex flex-column ga-2"
      :class="{ 'board-column__list--empty': cards.length === 0 }"
      :data-test="`board-column-list-${column.id}`"
      @change="onCardChange"
    >
      <template #item="{ element }">
        <div @click="emit('open-card', element)">
          <BoardCard :card="element" :color-token="column.attributes.color_token" />
        </div>
      </template>
    </draggable>
  </div>
</template>

<style scoped>
.board-column {
  width: 300px;
  flex: 0 0 300px;
  display: flex;
  flex-direction: column;
  max-height: 100%;
  padding: 10px;
  border-radius: 12px;
  background: rgba(var(--v-theme-on-surface), 0.04);
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
.board-column__header {
  flex: 0 0 auto;
  padding: 2px 2px 0;
}
.board-column__dot {
  width: 10px;
  height: 10px;
  border-radius: 9999px;
  display: inline-block;
}
.board-column__drag {
  cursor: grab;
}
.board-column__list {
  /* fill the column so the whole body is a drop target; scroll when the card
     stack exceeds the column height */
  flex: 1 1 auto;
  min-height: 64px;
  overflow-y: auto;
  padding: 2px;
  /* room so the last card's hover shadow isn't clipped */
  margin: 0 -2px;
  border-radius: 8px;
}
/* an empty column still reads as a column + droppable zone */
.board-column__list--empty {
  border: 1px dashed rgba(var(--v-theme-on-surface), 0.12);
}
</style>
