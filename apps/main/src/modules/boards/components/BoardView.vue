<script setup lang="ts">
/**
 * The board surface (Sprint 12 Chunk 2, D-1). Owns the store lifecycle + the 30s
 * poll, and wires the columns/dialogs/drawer together:
 *
 *   - onMounted: `store.load()` (the initial fetch) + `poll.start()`.
 *   - The poll's tick calls `store.refresh()` → `reconcile()` (skip-while-pending).
 *   - onBeforeUnmount: the poll stops itself (its own hook) and the store resets.
 *     Mounted under `v-if="tab === 'board'"` (Q3), so leaving the tab tears the
 *     poll down — no background polling on an unviewed tab.
 *
 * Role gates: `canConfigure` (admin + manager) controls column CRUD + the
 * automations dialog; a staff member can still DRAG cards (the columns are not
 * disabled for them). The optimistic move/reorder reverts live in the store; a
 * rejected one surfaces here as a snackbar toast (load-bearing #1's UI half).
 */

import type { BoardCardResource, BoardColumnResource } from '@catalyst/api-client'
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { useBoardPoll } from '../composables/useBoardPoll'
import { useBoardStore } from '../stores/useBoardStore'
import BoardAutomationDialog from './BoardAutomationDialog.vue'
import BoardCardDrawer from './BoardCardDrawer.vue'
import BoardColumnDeleteDialog from './BoardColumnDeleteDialog.vue'
import BoardColumnDialog from './BoardColumnDialog.vue'
import BoardColumns from './BoardColumns.vue'

const props = defineProps<{
  agencyId: string
  campaignId: string
  canConfigure: boolean
}>()

const { t } = useI18n()
const store = useBoardStore()
const poll = useBoardPoll(() => store.refresh())

const toast = ref<string | null>(null)

const columnDialogOpen = ref(false)
const columnDialogTarget = ref<BoardColumnResource | null>(null)
const deleteDialogOpen = ref(false)
const deleteDialogTarget = ref<BoardColumnResource | null>(null)
const automationDialogOpen = ref(false)
const drawerOpen = ref(false)
const drawerCard = ref<BoardCardResource | null>(null)

async function onMove(payload: { cardId: string; toColumnId: string }): Promise<void> {
  const ok = await store.moveCard(payload.cardId, payload.toColumnId)
  if (!ok) {
    toast.value = t('app.campaigns.board.moveFailed')
  }
}

async function onReorder(orderedIds: string[]): Promise<void> {
  const ok = await store.reorderColumns(orderedIds)
  if (!ok) {
    toast.value = t('app.campaigns.board.reorderFailed')
  }
}

function onOpenCard(card: BoardCardResource): void {
  drawerCard.value = card
  drawerOpen.value = true
}

function onAddColumn(): void {
  columnDialogTarget.value = null
  columnDialogOpen.value = true
}

function onEditColumn(column: BoardColumnResource): void {
  columnDialogTarget.value = column
  columnDialogOpen.value = true
}

function onDeleteColumn(column: BoardColumnResource): void {
  deleteDialogTarget.value = column
  deleteDialogOpen.value = true
}

onMounted(() => {
  void store.load(props.agencyId, props.campaignId)
  poll.start()
})

onBeforeUnmount(() => {
  store.reset()
})
</script>

<template>
  <div class="board-view" data-test="board-view">
    <div class="board-view__toolbar d-flex align-center mb-4">
      <v-spacer />
      <v-btn
        v-if="canConfigure"
        variant="text"
        prepend-icon="mdi-cog-outline"
        data-test="board-automations-open"
        @click="automationDialogOpen = true"
      >
        {{ t('app.campaigns.board.automationsButton') }}
      </v-btn>
    </div>

    <v-skeleton-loader v-if="store.loading" type="card" data-test="board-loading" />

    <v-alert
      v-else-if="store.loadError"
      type="error"
      variant="tonal"
      density="compact"
      data-test="board-load-error"
    >
      {{ t('app.campaigns.board.loadError') }}
    </v-alert>

    <BoardColumns
      v-else
      class="board-view__columns"
      :columns="store.sortedColumns"
      :cards-by-column="store.cardsByColumn"
      :can-edit-columns="canConfigure"
      @move="onMove"
      @reorder="onReorder"
      @open-card="onOpenCard"
      @edit-column="onEditColumn"
      @delete-column="onDeleteColumn"
      @add-column="onAddColumn"
    />

    <BoardColumnDialog v-model="columnDialogOpen" :column="columnDialogTarget" />
    <BoardColumnDeleteDialog v-model="deleteDialogOpen" :column="deleteDialogTarget" />
    <BoardAutomationDialog v-model="automationDialogOpen" />
    <BoardCardDrawer
      v-model="drawerOpen"
      :agency-id="agencyId"
      :campaign-id="campaignId"
      :card="drawerCard"
    />

    <v-snackbar
      :model-value="toast !== null"
      color="error"
      timeout="4000"
      data-test="board-toast"
      @update:model-value="
        (v: boolean) => {
          if (!v) toast = null
        }
      "
    >
      {{ toast }}
    </v-snackbar>
  </div>
</template>

<style scoped>
.board-view {
  display: flex;
  flex-direction: column;
  /* fill to the viewport bottom: app bar + page heading + tabs ≈ 210px above. */
  height: calc(100vh - 210px);
  min-height: 360px;
}
.board-view__toolbar {
  flex: 0 0 auto;
}
.board-view__columns {
  flex: 1 1 auto;
  min-height: 0;
}
</style>
