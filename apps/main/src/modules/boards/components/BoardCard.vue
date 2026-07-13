<script setup lang="ts">
/**
 * The card face (Sprint 12 Chunk 2, D-10; board-card facelift). A card IS a
 * CampaignAssignment (§4.1). The face renders what `BoardCardResource` exposes
 * under `relationships.assignment.data`:
 *
 *   - a lead avatar (signed `creator.avatar_url`, initial fallback) + name
 *   - days-remaining derived from `posting_due_at` (right-aligned, row 1)
 *   - the status badge (+ a muted "declined, then re-invited" history tag when
 *     `previously_declined` and the status has moved on)
 *   - the agreed fee ("€200 / script"), anchored at the end of the chip row
 *
 * Concept-inspired chrome: a thin aurora accent bar across the top (the brand
 * utility gradient, `var(--brand-aurora-gradient)` — token path, never a hex,
 * so it clears no-hard-coded-colors; a class, not an inline style, so it clears
 * no-inline-color-styles). The per-column colour still reads from the column
 * header, so the card face no longer repeats it.
 *
 * `assignment.data` can be null (a card whose assignment failed to load); the
 * face is null-safe and renders a minimal "removed" tile rather than crashing.
 */

import { formatCurrency, type BoardCardResource } from '@catalyst/api-client'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  card: BoardCardResource
}>()

const { t, locale } = useI18n()

const assignment = computed(() => props.card.relationships.assignment.data)

const displayName = computed(
  () => assignment.value?.creator?.display_name ?? t('app.campaigns.board.card.unnamed'),
)

const avatarUrl = computed(() => assignment.value?.creator?.avatar_url ?? null)

const avatarInitial = computed(() => {
  const name = assignment.value?.creator?.display_name?.trim()
  return name ? name.charAt(0).toUpperCase() : '?'
})

const statusLabel = computed(() => {
  const status = assignment.value?.status
  return status ? t(`app.campaigns.assignmentStatus.${status}`) : null
})

const feeLabel = computed(() => {
  const a = assignment.value
  if (a?.agreed_fee_minor_units === null || a?.agreed_fee_minor_units === undefined) {
    return null
  }
  const money = formatCurrency(
    a.agreed_fee_minor_units,
    a.agreed_fee_currency ?? null,
    locale.value,
  )
  return a.fee_per ? `${money} / ${a.fee_per}` : money
})

// Only surface the history tag once the row has moved on from `declined` —
// while it's actually declined the live status chip already says so.
const showDeclinedHistory = computed(
  () => assignment.value?.previously_declined === true && assignment.value.status !== 'declined',
)

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
  <v-card class="board-card" variant="outlined" :data-test="`board-card-${card.id}`">
    <div class="board-card__accent" aria-hidden="true" />
    <div class="board-card__body pa-2">
      <template v-if="assignment">
        <!-- Row 1: avatar + name, with days-remaining anchored at the end. -->
        <div class="d-flex align-center ga-2">
          <v-avatar size="28" class="board-card__avatar flex-shrink-0">
            <v-img
              v-if="avatarUrl"
              :src="avatarUrl"
              :alt="displayName"
              :data-test="`board-card-avatar-${card.id}`"
            />
            <span v-else class="text-caption">{{ avatarInitial }}</span>
          </v-avatar>
          <div
            class="text-body-2 font-weight-medium text-truncate flex-grow-1"
            :data-test="`board-card-name-${card.id}`"
          >
            {{ displayName }}
          </div>
          <span
            v-if="dueInfo"
            class="text-caption flex-shrink-0"
            :class="dueInfo.overdue ? 'text-error' : 'text-medium-emphasis'"
            :data-test="`board-card-due-${card.id}`"
          >
            {{ dueInfo.label }}
          </span>
        </div>

        <!-- Row 2: status chips on the left, agreed fee at the far end. -->
        <div class="d-flex align-center ga-2 mt-2">
          <!-- History tag: this row was declined, then re-offered
               (re-offer-after-decline chunk). Reuses the declined status label. -->
          <v-chip
            v-if="showDeclinedHistory"
            size="x-small"
            variant="tonal"
            color="medium-emphasis"
            :data-test="`board-card-declined-history-${card.id}`"
          >
            {{ t('app.campaigns.assignmentStatus.declined') }}
          </v-chip>
          <v-chip
            v-if="statusLabel"
            size="x-small"
            variant="tonal"
            :data-test="`board-card-status-${card.id}`"
          >
            {{ statusLabel }}
          </v-chip>
          <v-spacer />
          <span
            v-if="feeLabel"
            class="text-caption text-medium-emphasis flex-shrink-0"
            :data-test="`board-card-fee-${card.id}`"
          >
            {{ feeLabel }}
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
  background: rgb(var(--v-theme-surface));
  transition:
    box-shadow 0.15s ease,
    transform 0.05s ease;
}
.board-card:hover {
  box-shadow: 0 2px 8px rgba(var(--v-theme-on-surface), 0.16);
}
.board-card:active {
  transform: scale(0.997);
}
/* Thin brand accent across the top (concept top-bar), consuming the aurora
   utility token — token path only, no raw hex. */
.board-card__accent {
  height: 3px;
  background: var(--brand-aurora-gradient);
}
.board-card__avatar {
  background: rgba(var(--v-theme-on-surface), 0.08);
}
.board-card__body {
  min-width: 0;
}
</style>
