<script setup lang="ts">
/**
 * The board's Applications column (AH-059, D4) — a PSEUDO-column.
 *
 * ── What it is, and what it is emphatically not ─────────────────────────────
 *
 * This is a RENDERING OF APPLICATIONS ON THE BOARD SCREEN. It is not a board
 * column in the data, and §5.32 is why: `board_cards.assignment_id` is NOT NULL,
 * UNIQUE and CASCADE, so a card IS an assignment — and an application has no
 * assignment yet, by definition. Chunk 4's D1 annotation drew that line; this
 * extends it rather than crossing it. There is **zero diff** in the Boards
 * module's data layer, its migrations and its automation seeds, and the chunk's
 * review proves that by command output rather than by assertion.
 *
 * It borrows a real column's visual language on purpose — same width, same
 * header shape, its own pending count — because the applications ARE the leftmost
 * thing in the campaign's flow and burying them in a tab was the eyes-on
 * complaint. But it is deliberately distinguishable: the cards carry their status
 * and their actions on the card face, which is the affordance difference doing the
 * talking.
 *
 * ── NO DRAG IN, NO DRAG OUT — enforced by ABSENCE ───────────────────────────
 *
 * There is no `<draggable>` in this file, and this component is not a member of
 * `BoardColumns`'s `localColumns`. A `:disabled` flag would have been the obvious
 * implementation and the wrong one: a flag is one prop away from being flipped by
 * someone who does not know what it protects, whereas machinery that was never
 * wired cannot be un-disabled by accident. The board's `group="board-cards"` is
 * never joined, so sortablejs has no seam here to find.
 *
 * The rule it protects: a board drag is CONSEQUENCE-FREE, and answering an
 * application is not. Accept and reject are irreversible acts on a creator's
 * expectations; they belong behind the same two dialogs the Applications tab uses,
 * and they are those exact dialogs — {@link AcceptApplicationDialog} and
 * {@link RejectApplicationDialog}, shared via {@link useCampaignApplications}.
 *
 * ── The accept motion is ASSERTED, not built ────────────────────────────────
 *
 * On accept the application leaves this column (it is no longer pending) and the
 * new `invited` assignment appears in the board's Invited column — through the
 * listener and automation that already shipped in chunk 4. Nothing here creates a
 * card. This component only refetches BOTH surfaces afterwards, because the two
 * halves of that motion live in two different stores.
 */

import type { CampaignApplicationListItemResource } from '@catalyst/api-client'
import { formatDateTime } from '@catalyst/api-client'
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import AcceptApplicationDialog from '@/modules/campaigns/components/AcceptApplicationDialog.vue'
import RejectApplicationDialog from '@/modules/campaigns/components/RejectApplicationDialog.vue'
import { useCampaignApplications } from '@/modules/campaigns/composables/useCampaignApplications'

import { useBoardStore } from '../stores/useBoardStore'

const props = defineProps<{
  agencyId: string
  campaignId: string
  /** The execute tier (invite) — the same ability that gates the tab's actions. */
  canAct: boolean
  campaignCurrency: string | null
}>()

const { t, locale } = useI18n()
const boardStore = useBoardStore()

/**
 * PENDING ONLY, pinned in the composable rather than filtered here.
 *
 * A working surface answers the question "what still needs an answer". The
 * rejected rows are history and the accepted ones became assignments, both of
 * which the Applications tab still shows in full — that is the keep-both decision,
 * and it is why this column is allowed to be this narrow.
 */
const applications = useCampaignApplications(
  () => props.agencyId,
  () => props.campaignId,
  { initialFilter: 'pending', perPage: 50 },
)

const { rows, loading, loadError, actionError, hasRows, load, pendingTotal } = applications

const acceptDialog = ref(false)
const rejectDialog = ref(false)
const target = ref<CampaignApplicationListItemResource | null>(null)

function openAccept(row: CampaignApplicationListItemResource): void {
  target.value = row
  acceptDialog.value = true
}

function openReject(row: CampaignApplicationListItemResource): void {
  target.value = row
  rejectDialog.value = true
}

/**
 * The dual refetch, and both halves are necessary.
 *
 * An answer changes two things that live in two stores: this column's list (the
 * application is no longer pending) and the board's cards (an accept created an
 * `invited` assignment, which the listener + automation route into the Invited
 * column). Refetching only the list would leave the operator looking at a board
 * that has not caught up with the click they just made — the motion would be real
 * in the database and invisible on screen until the 30s poll.
 */
async function onAnswered(): Promise<void> {
  actionError.value = null
  await Promise.all([load(), boardStore.refresh()])
}

function appliedAt(iso: string | null): string {
  return formatDateTime(iso, locale.value)
}

function creatorName(row: CampaignApplicationListItemResource): string {
  return row.attributes.creator?.display_name ?? t('app.campaigns.invite.unnamed')
}

onMounted(() => {
  void load(true)
})
</script>

<template>
  <div class="applications-column" data-test="board-applications-column">
    <!-- A real column's header shape: marker, name, count. The marker is an ICON
         rather than a colour dot, because a colour dot is a board column's
         `color_token` and this pseudo-column has none to show. -->
    <div class="applications-column__header d-flex align-center ga-2 mb-2">
      <v-icon icon="mdi-account-arrow-right-outline" size="small" />
      <span class="text-subtitle-2 text-no-wrap text-truncate" data-test="board-applications-name">
        {{ t('app.campaigns.board.applications.title') }}
      </span>
      <v-chip size="x-small" variant="tonal" data-test="board-applications-count">
        {{ pendingTotal }}
      </v-chip>
    </div>

    <div class="applications-column__list d-flex flex-column ga-2">
      <v-skeleton-loader
        v-if="loading"
        type="list-item-two-line"
        data-test="board-applications-skeleton"
      />

      <v-alert
        v-else-if="loadError"
        type="error"
        variant="tonal"
        density="compact"
        data-test="board-applications-error"
      >
        {{ t('app.campaigns.applications.loadFailed') }}
      </v-alert>

      <template v-else>
        <v-alert
          v-if="actionError"
          type="error"
          variant="tonal"
          density="compact"
          data-test="board-applications-action-error"
        >
          {{ actionError }}
        </v-alert>

        <p
          v-if="!hasRows"
          class="applications-column__empty text-caption text-medium-emphasis"
          data-test="board-applications-empty"
        >
          {{ t('app.campaigns.board.applications.empty') }}
        </p>

        <!-- ⚠ NOT a <draggable>. See the file docblock: no drag in, no drag out,
             enforced by the absence of the machinery rather than by a flag. -->
        <v-card
          v-for="row in rows"
          :key="row.id"
          variant="outlined"
          class="application-card"
          :data-test="`board-application-${row.id}`"
        >
          <div class="d-flex align-center ga-2">
            <v-avatar size="28" color="surface-variant">
              <v-img
                v-if="row.attributes.creator?.avatar_url"
                :src="row.attributes.creator.avatar_url"
                :alt="creatorName(row)"
                cover
              />
              <span v-else class="text-caption">{{ creatorName(row)[0]?.toUpperCase() }}</span>
            </v-avatar>
            <span class="text-body-2 text-truncate">{{ creatorName(row) }}</span>
          </div>

          <!-- The status ON the card. A real board card gets its status from the
               column it sits in; this one has to say so itself, and that visible
               difference is what makes the missing drag affordance read as
               deliberate rather than broken. -->
          <div class="d-flex align-center ga-2 mt-2">
            <v-chip
              size="x-small"
              variant="tonal"
              color="primary"
              data-test="board-application-status"
            >
              {{ t('app.campaigns.applications.status.pending') }}
            </v-chip>
            <span class="text-caption text-medium-emphasis text-truncate">
              {{ appliedAt(row.attributes.applied_at) }}
            </span>
          </div>

          <p
            v-if="row.attributes.note"
            class="application-card__note text-caption mt-2 mb-0"
            :data-test="`board-application-note-${row.id}`"
          >
            {{ row.attributes.note }}
          </p>

          <!-- The actions on the card too, for the same reason. Same ability gate
               as the tab: accept and reject sit under one `invite` tier. -->
          <div v-if="canAct" class="d-flex ga-2 mt-3">
            <v-btn
              variant="outlined"
              size="x-small"
              :data-test="`board-application-reject-${row.id}`"
              @click="openReject(row)"
            >
              {{ t('app.campaigns.applications.reject.action') }}
            </v-btn>
            <v-btn
              color="primary"
              variant="flat"
              size="x-small"
              :data-test="`board-application-accept-${row.id}`"
              @click="openAccept(row)"
            >
              {{ t('app.campaigns.applications.accept.action') }}
            </v-btn>
          </div>
        </v-card>
      </template>
    </div>

    <!-- The SAME two dialogs the Applications tab opens — not copies. -->
    <AcceptApplicationDialog
      v-if="acceptDialog"
      v-model="acceptDialog"
      :agency-id="agencyId"
      :campaign-id="campaignId"
      :application="target"
      :campaign-currency="campaignCurrency"
      @accepted="onAnswered"
    />

    <RejectApplicationDialog
      v-if="rejectDialog"
      v-model="rejectDialog"
      :agency-id="agencyId"
      :campaign-id="campaignId"
      :application="target"
      @rejected="onAnswered"
      @refused="(message) => (actionError = message)"
    />
  </div>
</template>

<style scoped>
/* The real column's box, so the eye reads a column: same width, same radius,
   same surface tint. `BoardColumn`'s values, deliberately duplicated rather than
   shared through a mixin — this is a LOOKALIKE, and a shared style would invite
   the next reader to think it is the same thing. */
.applications-column {
  width: 300px;
  flex: 0 0 300px;
  display: flex;
  flex-direction: column;
  max-height: 100%;
  padding: 10px;
  border-radius: 12px;
  background: rgba(var(--v-theme-on-surface), 0.04);
  /* DASHED, where a real column is solid: the one deliberate visual tell that
     this column does not accept cards. */
  border: 1px dashed rgba(var(--v-theme-on-surface), 0.16);
}
.applications-column__header {
  flex: 0 0 auto;
  padding: 2px 2px 0;
}
.applications-column__list {
  flex: 1 1 auto;
  min-height: 64px;
  overflow-y: auto;
  padding: 2px;
  margin: 0 -2px;
}
/* Flex items in a column shrink to fit by default, which defeats the scroll
   above — the stack compresses instead of overflowing. `BoardColumn` carries
   the same declaration for the same reason. */
.applications-column__list > * {
  flex: 0 0 auto;
}
.applications-column__empty {
  margin: 0;
  padding: 8px 2px;
}
.application-card {
  padding: 10px;
}
.application-card__note {
  white-space: pre-wrap;
  word-break: break-word;
  opacity: 0.85;
}
</style>
