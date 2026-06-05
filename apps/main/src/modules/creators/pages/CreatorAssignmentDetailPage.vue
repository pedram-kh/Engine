<script setup lang="ts">
/**
 * CreatorAssignmentDetailPage — the per-assignment submission surface (Sprint 9
 * Chunk 1, D-9). The flat invitation list links here; this is the home for the
 * draft version history + the state-dependent, FAIL-CLOSED actions:
 *
 *   - producing / contracted  → submit a draft (caption + hashtags/mentions +
 *     presigned media upload). Backend lifts contracted → producing first (D-4).
 *   - revision_requested       → show the agency feedback (Chunk 2 writes it) +
 *     resubmit (a new version via the two-step machine path, D-6).
 *   - draft_submitted          → awaiting review (read-only).
 *   - approved                 → submit the post URL (approved → posted, D-7).
 *   - posted                   → awaiting verification (read-only — verifyLive is
 *     Chunk 2; the arc STOPS here at verification_status=pending).
 *   - anything else            → read-only (the list owns invited actions).
 *
 * Only the ONE legal action for the current status is shown (fail-closed UI; the
 * backend fail-closes too — a stale submit 422s).
 */

import {
  ApiError,
  extractFieldErrors,
  uploadToPresignedUrl,
  type CreatorAssignmentDetailResource,
  type DraftMediaInput,
} from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import { creatorAssignmentsApi } from '../assignments.api'

type DraftField = 'caption' | 'hashtags' | 'mentions' | 'media'
type PostedField = 'platform' | 'post_url'

type MediaUploadStatus = 'uploading' | 'done' | 'error'

interface MediaUploadItem {
  id: string
  fileName: string
  status: MediaUploadStatus
  s3_path: string | null
  mime_type: string | null
  kind: 'image' | 'video'
  duration_seconds: number | null
}

const PLATFORMS = ['instagram', 'tiktok', 'youtube'] as const

const { t, locale } = useI18n()
const route = useRoute()

const ulid = computed(() => String(route.params.ulid ?? ''))

const assignment = ref<CreatorAssignmentDetailResource | null>(null)
const loading = ref(false)
const loadedOnce = ref(false)
// Distinguish a true 404 (the assignment really isn't ours / doesn't exist)
// from any other failure (5xx, network) so a server error never masquerades
// as "not found" — the bare-catch trap that hid the missing-migration 500.
const loadError = ref<'not_found' | 'generic' | null>(null)
const snackbar = ref<{ color: string; text: string } | null>(null)

// Draft form state.
const caption = ref('')
const hashtagsInput = ref('')
const mentionsInput = ref('')
const media = ref<MediaUploadItem[]>([])
const draftFieldErrors = ref<Partial<Record<DraftField, readonly string[]>>>({})
const submittingDraft = ref(false)

// Posted-content form state.
const platform = ref<(typeof PLATFORMS)[number]>('instagram')
const postUrl = ref('')
const postedFieldErrors = ref<Partial<Record<PostedField, readonly string[]>>>({})
const submittingPosted = ref(false)

const status = computed(() => assignment.value?.attributes.status ?? null)
const drafts = computed(() => assignment.value?.relationships.drafts ?? [])
const postedContent = computed(() => assignment.value?.relationships.posted_content ?? [])

const canSubmitDraft = computed(
  () =>
    status.value === 'producing' ||
    status.value === 'contracted' ||
    status.value === 'revision_requested',
)
const isResubmit = computed(() => status.value === 'revision_requested')
const isAwaitingReview = computed(() => status.value === 'draft_submitted')
const canSubmitPosted = computed(() => status.value === 'approved')
const isAwaitingVerification = computed(() => status.value === 'posted')

/** The most recent agency feedback (Chunk 2 populates `review_feedback`). */
const revisionFeedback = computed<string | null>(() => {
  for (const draft of drafts.value) {
    if (draft.attributes.review_feedback) return draft.attributes.review_feedback
  }
  return null
})

const mediaUploading = computed(() => media.value.some((m) => m.status === 'uploading'))
const readyMedia = computed(() =>
  media.value.filter((m) => m.status === 'done' && m.s3_path !== null),
)
const draftSubmittable = computed(() => readyMedia.value.length > 0 && !mediaUploading.value)

function newId(): string {
  return `media-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
}

function tokenize(raw: string): string[] | null {
  const parts = raw
    .split(/[\s,]+/)
    .map((p) => p.trim())
    .filter((p) => p.length > 0)
  return parts.length > 0 ? parts : null
}

function formatMoney(minor: number | null, currency: string | null): string {
  if (minor === null) return '—'
  return `${(minor / 100).toLocaleString(locale.value, { minimumFractionDigits: 2 })} ${currency ?? ''}`.trim()
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    const res = await creatorAssignmentsApi.show(ulid.value)
    assignment.value = res.data
  } catch (err) {
    assignment.value = null
    loadError.value = err instanceof ApiError && err.status === 404 ? 'not_found' : 'generic'
  } finally {
    loading.value = false
    loadedOnce.value = true
  }
}

/**
 * Presigned two-phase upload per file: init → PUT (with the EXACT Content-Type
 * the backend signed, the latent-bug lesson) → complete (verify the object
 * landed) → record the storage path for the draft submission.
 */
/**
 * Patch a media item by id THROUGH the reactive ref array (not the captured
 * object) so the `readyMedia` / `mediaUploading` computeds recompute — mutating
 * the raw pushed object would silently bypass reactivity.
 */
function patchMedia(id: string, patch: Partial<MediaUploadItem>): void {
  const idx = media.value.findIndex((m) => m.id === id)
  if (idx === -1) return
  const current = media.value[idx]
  if (current === undefined) return
  media.value.splice(idx, 1, { ...current, ...patch })
}

async function onFilesSelected(files: File[] | File | null): Promise<void> {
  const list = files === null ? [] : Array.isArray(files) ? files : [files]
  for (const file of list) {
    const id = newId()
    media.value.push({
      id,
      fileName: file.name,
      status: 'uploading',
      s3_path: null,
      mime_type: file.type,
      kind: file.type.startsWith('image/') ? 'image' : 'video',
      duration_seconds: null,
    })
    try {
      const init = await creatorAssignmentsApi.initDraftMedia(ulid.value, {
        mime_type: file.type,
        declared_bytes: file.size,
      })
      // Content-Type MUST match the signed MIME (defaults to file.type; passed
      // explicitly to make the match unmissable).
      await uploadToPresignedUrl(init.data.upload_url, file, { contentType: file.type })
      const complete = await creatorAssignmentsApi.completeDraftMedia(ulid.value, {
        upload_id: init.data.upload_id,
      })
      patchMedia(id, { s3_path: complete.data.storage_path, status: 'done' })
    } catch {
      patchMedia(id, { status: 'error' })
    }
  }
}

function removeMedia(id: string): void {
  media.value = media.value.filter((m) => m.id !== id)
}

function resetDraftForm(): void {
  caption.value = ''
  hashtagsInput.value = ''
  mentionsInput.value = ''
  media.value = []
  draftFieldErrors.value = {}
}

async function submitDraft(): Promise<void> {
  if (submittingDraft.value || !draftSubmittable.value) return
  submittingDraft.value = true
  draftFieldErrors.value = {}
  try {
    const payloadMedia: DraftMediaInput[] = readyMedia.value.map((m) => ({
      s3_path: m.s3_path as string,
      mime_type: m.mime_type ?? 'application/octet-stream',
      kind: m.kind,
      duration_seconds: m.duration_seconds,
    }))
    await creatorAssignmentsApi.submitDraft(ulid.value, {
      caption: caption.value.trim() === '' ? null : caption.value.trim(),
      hashtags: tokenize(hashtagsInput.value),
      mentions: tokenize(mentionsInput.value),
      media: payloadMedia,
    })
    snackbar.value = {
      color: 'success',
      text: t('creator.ui.assignments.detail.toast.draftSubmitted'),
    }
    resetDraftForm()
    await load()
  } catch (err) {
    if (err instanceof ApiError) {
      draftFieldErrors.value = extractFieldErrors<DraftField>(err)
    }
    if (Object.keys(draftFieldErrors.value).length === 0) {
      snackbar.value = { color: 'error', text: t('creator.ui.assignments.detail.toast.error') }
    }
  } finally {
    submittingDraft.value = false
  }
}

async function submitPostedContent(): Promise<void> {
  if (submittingPosted.value || postUrl.value.trim() === '') return
  submittingPosted.value = true
  postedFieldErrors.value = {}
  try {
    await creatorAssignmentsApi.submitPostedContent(ulid.value, {
      platform: platform.value,
      post_url: postUrl.value.trim(),
    })
    snackbar.value = { color: 'success', text: t('creator.ui.assignments.detail.toast.posted') }
    postUrl.value = ''
    await load()
  } catch (err) {
    if (err instanceof ApiError) {
      postedFieldErrors.value = extractFieldErrors<PostedField>(err)
    }
    if (Object.keys(postedFieldErrors.value).length === 0) {
      snackbar.value = { color: 'error', text: t('creator.ui.assignments.detail.toast.error') }
    }
  } finally {
    submittingPosted.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="creator-assignment-detail" data-testid="creator-assignment-detail">
    <RouterLink
      :to="{ name: 'creator.assignments' }"
      class="text-body-2"
      data-testid="assignment-detail-back"
    >
      ← {{ t('creator.ui.assignments.detail.back') }}
    </RouterLink>

    <v-skeleton-loader
      v-if="loading && !loadedOnce"
      type="article"
      data-testid="assignment-detail-skeleton"
    />

    <template v-else-if="assignment !== null">
      <header class="d-flex align-center ga-3 flex-wrap">
        <h1 class="text-h4">{{ assignment.attributes.campaign?.name ?? '—' }}</h1>
        <v-chip size="small" variant="tonal" data-testid="assignment-detail-status">
          {{ t(`app.campaigns.assignmentStatus.${assignment.attributes.status}`) }}
        </v-chip>
      </header>
      <p class="text-body-2 text-medium-emphasis">
        <span v-if="assignment.attributes.campaign?.brand_name">
          {{ assignment.attributes.campaign.brand_name }} ·
        </span>
        {{ t('creator.ui.assignments.fee') }}:
        {{
          formatMoney(
            assignment.attributes.agreed_fee_minor_units,
            assignment.attributes.agreed_fee_currency,
          )
        }}
      </p>

      <!-- revision_requested feedback (Chunk 2 writes the feedback text) -->
      <v-alert
        v-if="isResubmit"
        type="warning"
        variant="tonal"
        data-testid="assignment-revision-feedback"
      >
        <div class="font-weight-medium">
          {{ t('creator.ui.assignments.detail.revision.title') }}
        </div>
        <div v-if="revisionFeedback">{{ revisionFeedback }}</div>
        <div v-else class="text-medium-emphasis">
          {{ t('creator.ui.assignments.detail.revision.noFeedback') }}
        </div>
      </v-alert>

      <!-- Draft submit / resubmit form (producing / contracted / revision_requested) -->
      <v-card v-if="canSubmitDraft" data-testid="assignment-draft-form">
        <v-card-title class="text-h6">
          {{
            isResubmit
              ? t('creator.ui.assignments.detail.draft.resubmitTitle')
              : t('creator.ui.assignments.detail.draft.submitTitle')
          }}
        </v-card-title>
        <v-card-text class="d-flex flex-column ga-3">
          <v-textarea
            v-model="caption"
            :label="t('creator.ui.assignments.detail.draft.caption')"
            variant="outlined"
            rows="3"
            auto-grow
            :error-messages="draftFieldErrors.caption as string[]"
            data-testid="assignment-draft-caption"
          />
          <v-text-field
            v-model="hashtagsInput"
            :label="t('creator.ui.assignments.detail.draft.hashtags')"
            :hint="t('creator.ui.assignments.detail.draft.hashtagsHint')"
            persistent-hint
            variant="outlined"
            density="compact"
            data-testid="assignment-draft-hashtags"
          />
          <v-text-field
            v-model="mentionsInput"
            :label="t('creator.ui.assignments.detail.draft.mentions')"
            :hint="t('creator.ui.assignments.detail.draft.mentionsHint')"
            persistent-hint
            variant="outlined"
            density="compact"
            data-testid="assignment-draft-mentions"
          />

          <v-file-input
            :label="t('creator.ui.assignments.detail.draft.media')"
            variant="outlined"
            density="compact"
            multiple
            prepend-icon="mdi-paperclip"
            :error-messages="draftFieldErrors.media as string[]"
            data-testid="assignment-draft-media-input"
            @update:model-value="onFilesSelected"
          />

          <v-list
            v-if="media.length > 0"
            density="compact"
            data-testid="assignment-draft-media-list"
          >
            <v-list-item
              v-for="m in media"
              :key="m.id"
              :data-testid="`assignment-draft-media-${m.id}`"
            >
              <v-list-item-title>{{ m.fileName }}</v-list-item-title>
              <v-list-item-subtitle>
                {{ t(`creator.ui.assignments.detail.draft.mediaStatus.${m.status}`) }}
              </v-list-item-subtitle>
              <template #append>
                <v-btn
                  icon="mdi-close"
                  size="x-small"
                  variant="text"
                  :disabled="m.status === 'uploading'"
                  @click="removeMedia(m.id)"
                />
              </template>
            </v-list-item>
          </v-list>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn
            color="primary"
            variant="flat"
            :loading="submittingDraft"
            :disabled="!draftSubmittable"
            data-testid="assignment-draft-submit"
            @click="submitDraft"
          >
            {{
              isResubmit
                ? t('creator.ui.assignments.detail.draft.resubmit')
                : t('creator.ui.assignments.detail.draft.submit')
            }}
          </v-btn>
        </v-card-actions>
      </v-card>

      <!-- Awaiting review (draft_submitted) -->
      <v-alert
        v-else-if="isAwaitingReview"
        type="info"
        variant="tonal"
        data-testid="assignment-awaiting-review"
      >
        {{ t('creator.ui.assignments.detail.awaitingReview') }}
      </v-alert>

      <!-- Posted-content form (approved → posted) -->
      <v-card v-else-if="canSubmitPosted" data-testid="assignment-posted-form">
        <v-card-title class="text-h6">
          {{ t('creator.ui.assignments.detail.posted.title') }}
        </v-card-title>
        <v-card-text class="d-flex flex-column ga-3">
          <v-select
            v-model="platform"
            :items="PLATFORMS"
            :label="t('creator.ui.assignments.detail.posted.platform')"
            variant="outlined"
            density="compact"
            :error-messages="postedFieldErrors.platform as string[]"
            data-testid="assignment-posted-platform"
          />
          <v-text-field
            v-model="postUrl"
            :label="t('creator.ui.assignments.detail.posted.url')"
            variant="outlined"
            density="compact"
            :error-messages="postedFieldErrors.post_url as string[]"
            data-testid="assignment-posted-url"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn
            color="primary"
            variant="flat"
            :loading="submittingPosted"
            :disabled="postUrl.trim() === ''"
            data-testid="assignment-posted-submit"
            @click="submitPostedContent"
          >
            {{ t('creator.ui.assignments.detail.posted.submit') }}
          </v-btn>
        </v-card-actions>
      </v-card>

      <!-- Awaiting verification (posted) — the arc STOPS here this chunk -->
      <v-alert
        v-else-if="isAwaitingVerification"
        type="success"
        variant="tonal"
        data-testid="assignment-awaiting-verification"
      >
        {{ t('creator.ui.assignments.detail.awaitingVerification') }}
      </v-alert>

      <!-- Draft version history (always shown when versions exist, D-6) -->
      <v-card v-if="drafts.length > 0" variant="outlined" data-testid="assignment-draft-history">
        <v-card-title class="text-subtitle-1">
          {{ t('creator.ui.assignments.detail.history.title') }}
        </v-card-title>
        <v-list density="compact">
          <v-list-item
            v-for="draft in drafts"
            :key="draft.id"
            :data-testid="`assignment-draft-version-${draft.attributes.version}`"
          >
            <v-list-item-title>
              {{
                t('creator.ui.assignments.detail.history.version', { n: draft.attributes.version })
              }}
              <v-chip size="x-small" variant="tonal" class="ml-2">
                {{
                  t(`creator.ui.assignments.detail.reviewStatus.${draft.attributes.review_status}`)
                }}
              </v-chip>
            </v-list-item-title>
            <v-list-item-subtitle v-if="draft.attributes.caption">
              {{ draft.attributes.caption }}
            </v-list-item-subtitle>
          </v-list-item>
        </v-list>
      </v-card>

      <!-- Posted content summary -->
      <v-card
        v-if="postedContent.length > 0"
        variant="outlined"
        data-testid="assignment-posted-summary"
      >
        <v-card-title class="text-subtitle-1">
          {{ t('creator.ui.assignments.detail.posted.summaryTitle') }}
        </v-card-title>
        <v-list density="compact">
          <v-list-item v-for="post in postedContent" :key="post.id">
            <v-list-item-title>{{ post.attributes.post_url }}</v-list-item-title>
            <v-list-item-subtitle>
              {{ post.attributes.platform }} ·
              {{
                t(
                  `creator.ui.assignments.detail.verificationStatus.${post.attributes.verification_status}`,
                )
              }}
            </v-list-item-subtitle>
          </v-list-item>
        </v-list>
      </v-card>
    </template>

    <v-alert
      v-else-if="loadError === 'not_found'"
      type="error"
      variant="tonal"
      data-testid="assignment-detail-not-found"
    >
      {{ t('creator.ui.assignments.detail.notFound') }}
    </v-alert>

    <v-alert v-else type="error" variant="tonal" data-testid="assignment-detail-load-error">
      <div class="d-flex align-center ga-3 flex-wrap">
        <span>{{ t('creator.ui.assignments.detail.loadFailed') }}</span>
        <v-btn
          size="small"
          variant="tonal"
          :loading="loading"
          data-testid="assignment-detail-retry"
          @click="load"
        >
          {{ t('creator.ui.assignments.detail.retry') }}
        </v-btn>
      </div>
    </v-alert>

    <v-snackbar
      :model-value="snackbar !== null"
      :timeout="3000"
      :color="snackbar?.color"
      data-testid="assignment-detail-snackbar"
      @update:model-value="
        (v) => {
          if (!v) snackbar = null
        }
      "
    >
      {{ snackbar?.text }}
    </v-snackbar>
  </section>
</template>

<style scoped>
.creator-assignment-detail {
  display: flex;
  flex-direction: column;
  gap: 20px;
}
</style>
