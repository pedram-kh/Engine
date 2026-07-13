<script setup lang="ts">
/**
 * Agency draft-review drawer (Sprint 9 Chunk 2, D-8). A WIDE dialog (the
 * ReinviteDialog pattern — no v-navigation-drawer exists in this app) opened
 * from a `draft_submitted` row in the Creators tab. It:
 *
 *   - loads the agency-side assignment detail (latest draft + version history +
 *     posted content with signed media URLs);
 *   - previews the latest draft (caption / external links + the media via
 *     the shared PortfolioGallery lightbox);
 *   - offers the three review actions — Approve / Request changes / Reject —
 *     with per-field 422 binding on `review_feedback` (the canonical pattern).
 *
 * The post-verification state (D-12) is labelled "simulated" — it is the mock
 * SocialPlatformProvider behind the scenes, not a real platform check.
 */

import {
  ApiError,
  extractFieldErrors,
  type AgencyAssignmentDetailResource,
  type CampaignAssignmentResource,
} from '@catalyst/api-client'
import { PortfolioGallery } from '@catalyst/ui'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { campaignsApi } from '../api/campaigns.api'

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
  modelValue: boolean
  agencyId: string
  campaignId: string
  assignment: CampaignAssignmentResource | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  reviewed: [message: string]
}>()

const { t } = useI18n()

const detail = ref<AgencyAssignmentDetailResource | null>(null)
const loading = ref(false)
const loadError = ref<string | null>(null)
const feedback = ref('')
const fieldErrors = ref<Partial<Record<ReviewField, readonly string[]>>>({})
const actionError = ref<string | null>(null)
const submitting = ref<ActionKind | null>(null)

const latestDraft = computed(() => detail.value?.relationships.drafts[0] ?? null)
const history = computed(() => detail.value?.relationships.drafts ?? [])
const postedContent = computed(() => detail.value?.relationships.posted_content ?? [])
const canAct = computed(() => detail.value?.attributes.status === 'draft_submitted')

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

async function load(): Promise<void> {
  const assignment = props.assignment
  if (assignment === null) return
  loading.value = true
  loadError.value = null
  detail.value = null
  try {
    const res = await campaignsApi.showAssignment(props.agencyId, props.campaignId, assignment.id)
    detail.value = res.data
  } catch {
    loadError.value = t('app.campaigns.review.loadFailed')
  } finally {
    loading.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      feedback.value = ''
      fieldErrors.value = {}
      actionError.value = null
      void load()
    }
  },
)

function close(): void {
  emit('update:modelValue', false)
}

async function runAction(kind: ActionKind): Promise<void> {
  const assignment = props.assignment
  if (assignment === null || submitting.value !== null) return

  submitting.value = kind
  fieldErrors.value = {}
  actionError.value = null
  try {
    if (kind === 'approve') {
      await campaignsApi.approveDraft(props.agencyId, props.campaignId, assignment.id)
      emit('reviewed', t('app.campaigns.review.toast.approved'))
    } else if (kind === 'revision') {
      await campaignsApi.requestRevision(props.agencyId, props.campaignId, assignment.id, {
        review_feedback: feedback.value.trim(),
      })
      emit('reviewed', t('app.campaigns.review.toast.revisionRequested'))
    } else {
      await campaignsApi.rejectDraft(props.agencyId, props.campaignId, assignment.id, {
        review_feedback: feedback.value.trim(),
      })
      emit('reviewed', t('app.campaigns.review.toast.rejected'))
    }
    emit('update:modelValue', false)
  } catch (err) {
    if (err instanceof ApiError) {
      fieldErrors.value = extractFieldErrors<ReviewField>(err)
    }
    if (Object.keys(fieldErrors.value).length === 0) {
      // No field-level (422) error to bind inline — surface the failure rather
      // than silently closing the drawer (e.g. an unexpected 5xx). Keep it open
      // so the reviewer can see what happened and retry.
      actionError.value = t('app.campaigns.review.toast.error')
    }
  } finally {
    submitting.value = null
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="900"
    scrollable
    data-test="review-draft-drawer"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="d-flex align-center">
        <span class="text-h6">{{ t('app.campaigns.review.title') }}</span>
        <span
          v-if="assignment?.attributes.creator?.display_name"
          class="text-body-2 text-medium-emphasis ml-2"
        >
          · {{ assignment.attributes.creator.display_name }}
        </span>
        <v-spacer />
        <v-btn
          icon="mdi-close"
          variant="text"
          size="small"
          data-test="review-close"
          @click="close"
        />
      </v-card-title>

      <v-divider />

      <v-card-text>
        <v-skeleton-loader v-if="loading" type="article" data-test="review-skeleton" />

        <v-alert v-else-if="loadError" type="error" variant="tonal" data-test="review-load-error">
          {{ loadError }}
        </v-alert>

        <template v-else-if="latestDraft">
          <!-- Latest draft preview -->
          <div class="mb-4" data-test="review-draft-preview">
            <div class="text-subtitle-2 mb-1">
              {{ t('app.campaigns.review.draftVersion', { n: latestDraft.attributes.version }) }}
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
          </template>

          <!-- Version history -->
          <v-card
            v-if="history.length > 0"
            variant="outlined"
            class="mt-4"
            data-test="review-history"
          >
            <v-card-title class="text-subtitle-2">{{
              t('app.campaigns.review.history')
            }}</v-card-title>
            <v-list density="compact">
              <v-list-item
                v-for="draft in history"
                :key="draft.id"
                :data-test="`review-history-${draft.attributes.version}`"
              >
                <v-list-item-title>
                  {{ t('app.campaigns.review.draftVersion', { n: draft.attributes.version }) }}
                  <v-chip size="x-small" variant="tonal" class="ml-2">
                    {{ t(`app.campaigns.review.draftStatus.${draft.attributes.review_status}`) }}
                  </v-chip>
                </v-list-item-title>
                <v-list-item-subtitle v-if="draft.attributes.review_feedback">
                  {{ draft.attributes.review_feedback }}
                </v-list-item-subtitle>
              </v-list-item>
            </v-list>
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
                  <v-chip
                    size="x-small"
                    variant="tonal"
                    :data-test="`review-verification-${post.id}`"
                  >
                    {{
                      t(`app.campaigns.review.verification.${post.attributes.verification_status}`)
                    }}
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
      </v-card-text>

      <v-divider />

      <v-card-actions v-if="canAct">
        <v-btn variant="text" data-test="review-cancel" @click="close">
          {{ t('app.campaigns.review.close') }}
        </v-btn>
        <v-spacer />
        <v-btn
          color="error"
          variant="text"
          :loading="submitting === 'reject'"
          :disabled="submitting !== null"
          data-test="review-reject"
          @click="runAction('reject')"
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
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
