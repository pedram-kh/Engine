<script setup lang="ts">
/**
 * The reviewable content of a draft review (Sprint 9 Chunk 2, D-8; promoted
 * to a shared component in the eyes-on fix batch, 2026-08-17) — the latest
 * draft's preview + media gallery, the feedback field, the three review
 * actions (Approve / Request changes / Reject) with per-field 422 binding,
 * the reject confirmation guard, the round-by-round draft history, and the
 * posted content list.
 *
 * TWO surfaces mount this now: `ReviewDraftDrawer` (the campaign Drafts tab's
 * dedicated dialog) and the board card drawer's Drafts tab (embedded inline,
 * no separate dialog chrome) — so approving/rejecting/requesting changes
 * can't drift into two different implementations by having been copy-pasted.
 * Each parent owns its OWN fetch of the `AgencyAssignmentDetailResource`
 * (already true before this split — nothing new fetched) and passes it in as
 * `detail` + `loading` + `loadError`; this component owns none of the
 * loading, only the acting and the rendering.
 *
 * Resets local feedback/error state whenever the parent starts a fresh load
 * (`loading` flips false → true) — the same moment `ReviewDraftDrawer` used
 * to reset it on dialog-open, generalized to work for a component with no
 * "dialog open" of its own.
 */

import {
  ApiError,
  extractFieldErrors,
  type AgencyAssignmentDetailResource,
} from '@catalyst/api-client'
import { PortfolioGallery } from '@catalyst/ui'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { campaignsApi } from '../api/campaigns.api'
import { roundCardTextStyle, roundStateColor, roundStateKey } from '../draftRounds'

type ReviewField = 'review_feedback'
type ActionKind = 'approve' | 'revision' | 'reject'

interface GalleryItem {
  id: string
  kind: 'image' | 'video' | 'link'
  title: string | null
  description: string | null
  thumbnailUrl: string | null
  viewUrl: string | null
  externalUrl: string | null
  altText: string
}

const props = defineProps<{
  agencyId: string
  campaignId: string
  /** The assignment the draft(s) belong to — null while a card's own assignment failed to load. */
  assignmentId: string | null
  detail: AgencyAssignmentDetailResource | null
  loading: boolean
  loadError: boolean
  /**
   * The `review` ability. Required (not defaulted) so neither caller can
   * forget it: `ReviewDraftDrawer` is only ever mounted once its OWN parent
   * has already checked this, so it passes `true` unconditionally; the board
   * card drawer's Drafts tab has no such upstream gate — it is reachable
   * regardless of ability — so it must pass the real value through.
   */
  canReview: boolean
}>()

const emit = defineEmits<{
  reviewed: [message: string]
}>()

const { t } = useI18n()

const feedback = ref('')
const fieldErrors = ref<Partial<Record<ReviewField, readonly string[]>>>({})
const actionError = ref<string | null>(null)
const submitting = ref<ActionKind | null>(null)
const rejectConfirmOpen = ref(false)

// A fresh load starting is the one honest place to clear a previous round's
// stale feedback/errors — this component has no `modelValue` of its own to
// watch the way `ReviewDraftDrawer` used to.
watch(
  () => props.loading,
  (isLoading, wasLoading) => {
    if (isLoading && !wasLoading) {
      feedback.value = ''
      fieldErrors.value = {}
      actionError.value = null
      rejectConfirmOpen.value = false
    }
  },
)

const latestDraft = computed(() => props.detail?.relationships.drafts[0] ?? null)
const history = computed(() => props.detail?.relationships.drafts ?? [])
const postedContent = computed(() => props.detail?.relationships.posted_content ?? [])
const canAct = computed(
  () => props.canReview && props.detail?.attributes.status === 'draft_submitted',
)
// The round-state copy needs the assignment to tell "awaiting review" from a
// pending round nobody is looking at any more (AH-068).
const assignmentStatus = computed(() => props.detail?.attributes.status ?? null)

const galleryItems = computed<GalleryItem[]>(() => {
  const draft = latestDraft.value
  if (draft === null) return []
  return draft.attributes.media.map((m, index) => {
    const isVideo = m.kind === 'video'
    return {
      id: `${draft.id}-${index}`,
      kind: isVideo ? 'video' : 'image',
      title: null,
      description: null,
      // A video's `view_url` is the playable file, NOT an image — never feed it
      // to the gallery's <img> thumbnail (it renders broken). Only use a real
      // poster (`thumbnail_view_url`); when there is none, leave it null so the
      // gallery shows a clean play-tile. Images keep falling back to view_url.
      thumbnailUrl: m.thumbnail_view_url ?? (isVideo ? null : m.view_url),
      viewUrl: m.view_url,
      externalUrl: null,
      altText: draft.attributes.caption ?? `media-${index}`,
    }
  })
})

async function runAction(kind: ActionKind): Promise<void> {
  const assignmentId = props.assignmentId
  if (assignmentId === null || !props.canReview || submitting.value !== null) return

  submitting.value = kind
  fieldErrors.value = {}
  actionError.value = null
  try {
    if (kind === 'approve') {
      await campaignsApi.approveDraft(props.agencyId, props.campaignId, assignmentId)
      emit('reviewed', t('app.campaigns.review.toast.approved'))
    } else if (kind === 'revision') {
      await campaignsApi.requestRevision(props.agencyId, props.campaignId, assignmentId, {
        review_feedback: feedback.value.trim(),
      })
      emit('reviewed', t('app.campaigns.review.toast.revisionRequested'))
    } else {
      await campaignsApi.rejectDraft(props.agencyId, props.campaignId, assignmentId, {
        review_feedback: feedback.value.trim(),
      })
      emit('reviewed', t('app.campaigns.review.toast.rejected'))
    }
  } catch (err) {
    if (err instanceof ApiError) {
      fieldErrors.value = extractFieldErrors<ReviewField>(err)
    }
    if (Object.keys(fieldErrors.value).length === 0) {
      // No field-level (422) error to bind inline — surface the failure rather
      // than silently doing nothing (e.g. an unexpected 5xx). Kept visible so
      // the reviewer can see what happened and retry.
      actionError.value = t('app.campaigns.review.toast.error')
    }
  } finally {
    submitting.value = null
  }
}

// Reject is a DEDICATED TERMINAL transition (draft_submitted → rejected, no
// edge out) — one click permanently ends the assignment. The confirm dialog
// stands between the click and the API call; Approve / Request changes stay
// single-click (both are recoverable).
async function confirmReject(): Promise<void> {
  rejectConfirmOpen.value = false
  await runAction('reject')
}
</script>

<template>
  <v-skeleton-loader v-if="loading" type="article" data-test="review-skeleton" />

  <v-alert v-else-if="loadError" type="error" variant="tonal" data-test="review-load-error">
    {{ t('app.campaigns.review.loadFailed') }}
  </v-alert>

  <template v-else-if="latestDraft">
    <!-- Latest draft preview -->
    <div class="mb-4" data-test="review-draft-preview">
      <div class="text-subtitle-2 mb-1">
        {{ t('app.campaigns.review.draftRound', { n: latestDraft.attributes.version }) }}
      </div>
      <p class="text-body-2" data-test="review-caption">
        {{ latestDraft.attributes.caption || t('app.campaigns.review.noCaption') }}
      </p>

      <!-- External reference links on the draft (draft-composer facelift).
           The hashtags/mentions chip rows were dropped with the fields. -->
      <div
        v-if="latestDraft.attributes.links && latestDraft.attributes.links.length > 0"
        class="mt-2 d-flex flex-column ga-1"
        data-test="review-links"
      >
        <a
          v-for="(link, i) in latestDraft.attributes.links"
          :key="`${link.url}-${i}`"
          :href="link.url"
          target="_blank"
          rel="noopener noreferrer"
          class="text-body-2 d-inline-flex align-center ga-1"
          :data-test="`review-link-${i}`"
        >
          <v-icon icon="mdi-link-variant" size="x-small" />
          {{ link.name ?? link.url }}
        </a>
      </div>

      <div class="mt-3">
        <PortfolioGallery
          :items="galleryItems"
          :empty-label="t('app.campaigns.review.media.empty')"
          :video-label="t('app.campaigns.review.media.video')"
          :preview-label="t('app.campaigns.review.media.preview')"
          :close-label="t('app.campaigns.review.media.close')"
        />
      </div>
    </div>

    <!-- Review actions (only when a draft awaits review) -->
    <template v-if="canAct">
      <v-alert
        v-if="actionError"
        type="error"
        variant="tonal"
        class="mb-3"
        data-test="review-action-error"
      >
        {{ actionError }}
      </v-alert>
      <v-textarea
        v-model="feedback"
        :label="t('app.campaigns.review.feedbackLabel')"
        :hint="t('app.campaigns.review.feedbackHint')"
        persistent-hint
        variant="outlined"
        rows="3"
        auto-grow
        :error-messages="fieldErrors.review_feedback as string[]"
        data-test="review-feedback"
      />
      <div class="d-flex ga-2 flex-wrap mt-3">
        <v-btn
          color="error"
          variant="text"
          :loading="submitting === 'reject'"
          :disabled="submitting !== null"
          data-test="review-reject"
          @click="rejectConfirmOpen = true"
        >
          {{ t('app.campaigns.review.reject') }}
        </v-btn>
        <v-btn
          color="warning"
          variant="tonal"
          :loading="submitting === 'revision'"
          :disabled="submitting !== null"
          data-test="review-request-revision"
          @click="runAction('revision')"
        >
          {{ t('app.campaigns.review.requestRevision') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :loading="submitting === 'approve'"
          :disabled="submitting !== null"
          data-test="review-approve"
          @click="runAction('approve')"
        >
          {{ t('app.campaigns.review.approve') }}
        </v-btn>
      </div>
    </template>

    <!-- Draft history -->
    <v-card v-if="history.length > 0" variant="outlined" class="mt-4" data-test="review-history">
      <v-card-title class="text-subtitle-2">{{ t('app.campaigns.review.history') }}</v-card-title>
      <div class="d-flex flex-column ga-2 pa-3">
        <v-sheet
          v-for="draft in history"
          :key="draft.id"
          :color="roundStateColor(draft.attributes.review_status, assignmentStatus)"
          variant="tonal"
          rounded="lg"
          class="pa-2 px-3"
          :data-test="`review-history-${draft.attributes.version}`"
        >
          <div class="text-body-2 font-weight-bold">
            {{
              t(roundStateKey(draft.attributes.review_status, assignmentStatus), {
                n: draft.attributes.version,
              })
            }}
          </div>
          <div
            v-if="draft.attributes.review_feedback"
            class="text-body-2 mt-1 review-history-feedback"
            :style="roundCardTextStyle(draft.attributes.review_status, assignmentStatus)"
          >
            {{ draft.attributes.review_feedback }}
          </div>
        </v-sheet>
      </div>
    </v-card>

    <!-- Posted content (verification — labelled simulated, D-12) -->
    <v-card
      v-if="postedContent.length > 0"
      variant="outlined"
      class="mt-4"
      data-test="review-posted"
    >
      <v-card-title class="text-subtitle-2">{{
        t('app.campaigns.review.postedContent')
      }}</v-card-title>
      <v-list density="compact">
        <v-list-item v-for="post in postedContent" :key="post.id">
          <v-list-item-title>{{ post.attributes.post_url }}</v-list-item-title>
          <v-list-item-subtitle class="d-flex align-center ga-2 flex-wrap">
            <span>{{ post.attributes.platform }}</span>
            <v-chip size="x-small" variant="tonal" :data-test="`review-verification-${post.id}`">
              {{ t(`app.campaigns.review.verification.${post.attributes.verification_status}`) }}
            </v-chip>
            <span
              v-if="post.attributes.verification_status === 'verified'"
              class="text-caption text-medium-emphasis"
              data-test="review-simulated-label"
            >
              {{ t('app.campaigns.review.simulated') }}
            </span>
          </v-list-item-subtitle>
        </v-list-item>
      </v-list>
    </v-card>
  </template>

  <!-- An assignment with no drafts at all yet (reachable now that the board
       card drawer's Drafts tab isn't gated on `draft_submitted` the way
       `ReviewDraftDrawer`'s own open button always was). -->
  <p v-else class="text-medium-emphasis text-body-2" data-test="review-empty">
    {{ t('app.campaigns.drafts.empty.heading') }}
  </p>

  <!-- Terminal-action guard: rejecting has NO edge out of `rejected`, so
       the destructive call sits behind an explicit confirmation. The v-if
       keeps the dialog out of the DOM until asked for. -->
  <v-dialog
    v-if="rejectConfirmOpen"
    v-model="rejectConfirmOpen"
    max-width="440"
    data-test="review-reject-confirm"
  >
    <v-card>
      <v-card-title class="text-h6">
        {{ t('app.campaigns.review.rejectConfirm.title') }}
      </v-card-title>
      <v-card-text class="text-body-2">
        {{ t('app.campaigns.review.rejectConfirm.body') }}
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" data-test="review-reject-keep" @click="rejectConfirmOpen = false">
          {{ t('app.campaigns.review.rejectConfirm.keep') }}
        </v-btn>
        <v-btn
          color="error"
          variant="flat"
          data-test="review-reject-confirm-btn"
          @click="confirmReject"
        >
          {{ t('app.campaigns.review.reject') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.review-history-feedback {
  white-space: pre-wrap;
  word-break: break-word;
}
</style>
