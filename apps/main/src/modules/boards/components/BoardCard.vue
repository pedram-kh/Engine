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
import { creatorAvatarUrl, creatorInitial, creatorLabel } from '@/core/creators/creatorLabel'

const props = defineProps<{
  card: BoardCardResource
  /**
   * PL-1.2: the slim face of a wave board — the creator's name and where they stand (Invited / Accepted / Declined).
   * No fee and no due date: the fee is the creator's own, the due date is not a wave concern. Never set on a normal
   * board, whose face is unchanged.
   */
  slim?: boolean
}>()

const { t, locale } = useI18n()

const assignment = computed(() => props.card.relationships.assignment.data)

const displayName = computed(() =>
  creatorLabel(assignment.value?.creator, t, t('app.campaigns.board.card.unnamed')),
)

const avatarUrl = computed(() => creatorAvatarUrl(assignment.value?.creator))

const avatarInitial = computed(() => creatorInitial(assignment.value?.creator))

// PL-1.2 (D3): on a wave board's Invited column the label says how the creator got there, not the raw status (an
// accepted creator is `contracted` by then). `invite_origin` is only sent for those cards. Everywhere else, and in the
// working columns, the label is the assignment status, as before.
const inviteLabelKey = computed<string | null>(() => {
  const state = props.card.invite_state
  if (state === undefined || props.card.invite_origin === undefined) return null
  const applied = props.card.invite_origin === 'application'
  if (state === 'declined') return 'app.campaigns.assignmentStatus.declined'
  if (state === 'invited') {
    return applied
      ? 'app.campaigns.board.inviteLabel.offerSent'
      : 'app.campaigns.assignmentStatus.invited'
  }
  return applied
    ? 'app.campaigns.board.inviteLabel.applicationAccepted'
    : 'app.campaigns.board.inviteLabel.acceptedInvite'
})

const statusLabel = computed(() => {
  if (inviteLabelKey.value !== null) return t(inviteLabelKey.value)
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

// PL-1.2 — Invited / Accepted / Declined on a wave board's invite stage. `undefined` keeps the chip exactly as it was.
const inviteColor = computed(() => {
  switch (props.card.invite_state) {
    case 'accepted':
      return 'success'
    case 'declined':
      return 'error'
    default:
      return undefined
  }
})
// The `scripts` slot is PL-1.3's seam: empty today, drawn on a wave board only, and it renders no element of its own.
// (An HTML comment in the template would show up in the dev DOM of every board, so the note lives here.)
const slimItems = computed<number[]>(() => (props.slim === true ? [1] : []))
// PL-3.1 — the "In wave" marker: the server sends `in_wave` only on a wave board with the sprint flag on, and true only for
// a creator who has a place in the wave's Creators step. A zero-or-one list (not `v-if`) so the flag-off DOM has no extra
// comment node.
const inWaveItems = computed<number[]>(() => (props.card.attributes.in_wave === true ? [1] : []))

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
            class="text-body-2 font-weight-bold text-truncate flex-grow-1"
            :data-test="`board-card-name-${card.id}`"
          >
            {{ displayName }}
          </div>
          <span
            v-if="dueInfo && !slim"
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
            :color="inviteColor"
            :data-test="`board-card-status-${card.id}`"
          >
            {{ statusLabel }}
          </v-chip>
          <v-chip
            v-for="n in inWaveItems"
            :key="n"
            size="x-small"
            variant="tonal"
            color="primary"
            :data-test="`board-card-in-wave-${card.id}`"
          >
            {{ t('app.strategy.creators.inWave') }}
          </v-chip>
          <v-spacer />
          <span
            v-if="feeLabel && !slim"
            class="text-caption text-medium-emphasis flex-shrink-0"
            :data-test="`board-card-fee-${card.id}`"
          >
            {{ feeLabel }}
          </span>
        </div>

        <slot v-for="n in slimItems" :key="n" name="scripts" :card="card" />
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
