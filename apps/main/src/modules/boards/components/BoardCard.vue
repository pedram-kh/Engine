<script setup lang="ts">
/**
 * The reduced card face (Sprint 12 Chunk 2, D-10). A card IS a
 * CampaignAssignment (§4.1) — the face renders ONLY what the closed Chunk 1
 * `BoardCardResource` exposes (under `relationships.assignment.data`):
 *
 *   - creator display name
 *   - the assignment status badge
 *   - days-remaining derived from `posting_due_at`
 *   - the column colour strip (the `color_token` → boardStatus hex, D-11)
 *
 * §4.2's richer wants (avatar, platform icon, unread count) are NOT exposed and
 * we do NOT reopen the Chunk 1 Resource for them — they're logged as tech-debt.
 * `assignment.data` can be null (a card whose assignment failed to load); the
 * face is null-safe and renders a minimal "removed" tile rather than crashing.
 *
 * The colour strip binds `:style="{ backgroundColor: boardColorHex(token) }"` —
 * the hex lives in `boardTokens.ts`, never here (clears no-hard-coded-colors),
 * and the camelCase object-binding clears no-inline-color-styles (Q1).
 */

import type { BoardCardResource } from '@catalyst/api-client'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { boardColorHex } from '../support/boardTokens'

const props = defineProps<{
  card: BoardCardResource
  colorToken: string
}>()

const { t } = useI18n()

const assignment = computed(() => props.card.relationships.assignment.data)

const stripStyle = computed(() => ({ backgroundColor: boardColorHex(props.colorToken) }))

const displayName = computed(
  () => assignment.value?.creator?.display_name ?? t('app.campaigns.board.card.unnamed'),
)

const statusLabel = computed(() => {
  const status = assignment.value?.status
  return status ? t(`app.campaigns.assignmentStatus.${status}`) : null
})

interface DueInfo {
  label: string
  overdue: boolean
}

const dueInfo = computed<DueInfo | null>(() => {
  const due = assignment.value?.posting_due_at
  if (due === null || due === undefined) {
    return null
  }
  const dueMs = new Date(due).getTime()
  if (Number.isNaN(dueMs)) {
    return null
  }
  const startOfToday = new Date()
  startOfToday.setHours(0, 0, 0, 0)
  const dueDay = new Date(dueMs)
  dueDay.setHours(0, 0, 0, 0)
  const days = Math.round((dueDay.getTime() - startOfToday.getTime()) / 86_400_000)
  if (days < 0) {
    return { label: t('app.campaigns.board.card.overdue'), overdue: true }
  }
  if (days === 0) {
    return { label: t('app.campaigns.board.card.dueToday'), overdue: false }
  }
  return { label: t('app.campaigns.board.card.daysLeft', { n: days }), overdue: false }
})
</script>

<template>
  <v-card class="board-card d-flex" variant="outlined" :data-test="`board-card-${card.id}`">
    <div class="board-card__strip" :style="stripStyle" aria-hidden="true" />
    <div class="board-card__body pa-2">
      <template v-if="assignment">
        <div class="text-body-2 font-weight-medium" :data-test="`board-card-name-${card.id}`">
          {{ displayName }}
        </div>
        <div class="d-flex align-center ga-2 mt-1 flex-wrap">
          <v-chip
            v-if="statusLabel"
            size="x-small"
            variant="tonal"
            :data-test="`board-card-status-${card.id}`"
          >
            {{ statusLabel }}
          </v-chip>
          <span
            v-if="dueInfo"
            class="text-caption"
            :class="dueInfo.overdue ? 'text-error' : 'text-medium-emphasis'"
            :data-test="`board-card-due-${card.id}`"
          >
            {{ dueInfo.label }}
          </span>
        </div>
      </template>
      <div
        v-else
        class="text-caption text-medium-emphasis"
        :data-test="`board-card-removed-${card.id}`"
      >
        {{ t('app.campaigns.board.card.removed') }}
      </div>
    </div>
  </v-card>
</template>

<style scoped>
.board-card {
  cursor: pointer;
  overflow: hidden;
}
.board-card__strip {
  width: 4px;
  flex: 0 0 4px;
}
.board-card__body {
  flex: 1 1 auto;
  min-width: 0;
}
</style>
