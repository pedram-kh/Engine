<script setup lang="ts">
/**
 * CreatorJobDetailPage — one job, plus Apply (AH-056, D4/D5).
 *
 * The apply flow is one tap with an optional note, and every interesting case
 * is a server answer this page renders rather than a client rule it enforces:
 *
 *   404 → the job stopped qualifying between the board render and the click
 *         (delisted, ended, taken terminal, roster relation ended, brand
 *         hard-blacklist added). Shown as "no longer available", never as a
 *         crash, and never as a retry loop.
 *   409 `job.already_applied`      → a pending or accepted application exists.
 *   409 `job.application_rejected` → terminal. Apply never re-opens (D1).
 *
 * The Apply button is disabled once `application_status` is non-null, but that
 * is a courtesy: the server refuses regardless, which is why a stale tab that
 * missed a rejection still cannot slip a second application through.
 *
 * The footer renders the caller's own outcome, and chunk 4 (AH-058, D7) added
 * the third state that was missing: `accepted` links into the assignment the
 * agency created, so a creator is never left on a page saying "applied" while a
 * real offer waits on another surface.
 */

import { ApiError, type CreatorJobDetailResource } from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import { creatorJobsApi } from '../jobs.api'

const { t } = useI18n()
const route = useRoute()

const NOTE_MAX_LENGTH = 1000

const job = ref<CreatorJobDetailResource | null>(null)
const loading = ref(true)
const notFound = ref(false)
const loadFailed = ref(false)
const dialogOpen = ref(false)
const note = ref('')
const submitting = ref(false)
const snackbar = ref<{ color: string; text: string } | null>(null)

const ulid = computed(() => String(route.params.ulid ?? ''))
const status = computed(() => job.value?.attributes.application_status ?? null)
const assignmentUlid = computed(() => job.value?.attributes.assignment_ulid ?? null)
const canApply = computed(() => job.value !== null && status.value === null)

async function load(): Promise<void> {
  loading.value = true
  notFound.value = false
  loadFailed.value = false

  try {
    const res = await creatorJobsApi.show(ulid.value)
    job.value = res.data
  } catch (error) {
    job.value = null
    // A 404 is the fail-closed answer for every reason a job may be
    // unavailable, so it gets the "no longer available" copy — not the
    // "something went wrong, retry" copy, which would invite a pointless loop.
    if (error instanceof ApiError && error.status === 404) {
      notFound.value = true
    } else {
      loadFailed.value = true
    }
  } finally {
    loading.value = false
  }
}

function openDialog(): void {
  note.value = ''
  dialogOpen.value = true
}

/** Map the server's refusal code onto copy. Unknown codes fall through. */
function refusalToast(code: string): { color: string; text: string } {
  switch (code) {
    case 'job.application_rejected':
      return { color: 'error', text: t('creator.ui.jobs.toast.applicationRejected') }
    case 'job.already_applied':
      return { color: 'info', text: t('creator.ui.jobs.toast.alreadyApplied') }
    default:
      return { color: 'error', text: t('creator.ui.jobs.toast.error') }
  }
}

async function submitApplication(): Promise<void> {
  if (submitting.value) return
  submitting.value = true

  const trimmed = note.value.trim()

  try {
    await creatorJobsApi.apply(ulid.value, trimmed === '' ? {} : { note: trimmed })
    dialogOpen.value = false
    snackbar.value = { color: 'success', text: t('creator.ui.jobs.toast.applied') }
    // Re-read rather than patch locally: the row the server created is the
    // truth, and it also refreshes the applicant count this application moved.
    await load()
  } catch (error) {
    dialogOpen.value = false

    if (error instanceof ApiError && error.status === 404) {
      snackbar.value = { color: 'error', text: t('creator.ui.jobs.toast.gone') }
      await load()
    } else if (error instanceof ApiError && error.status === 409) {
      snackbar.value = refusalToast(error.code)
      await load()
    } else {
      snackbar.value = { color: 'error', text: t('creator.ui.jobs.toast.error') }
    }
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="job-detail" data-testid="creator-job-detail">
    <v-btn
      variant="text"
      size="small"
      prepend-icon="mdi-arrow-left"
      :to="{ name: 'creator.jobs' }"
      data-testid="creator-job-back"
    >
      {{ t('creator.ui.jobs.detail.back') }}
    </v-btn>

    <v-skeleton-loader
      v-if="loading"
      type="article, paragraph"
      data-testid="creator-job-detail-skeleton"
    />

    <v-alert v-else-if="notFound" type="info" variant="tonal" data-testid="creator-job-not-found">
      {{ t('creator.ui.jobs.detail.notFound') }}
    </v-alert>

    <v-alert
      v-else-if="loadFailed"
      type="error"
      variant="tonal"
      data-testid="creator-job-load-failed"
    >
      {{ t('creator.ui.jobs.detail.loadFailed') }}
      <template #append>
        <v-btn variant="text" size="small" @click="load()">
          {{ t('creator.ui.jobs.detail.retry') }}
        </v-btn>
      </template>
    </v-alert>

    <!-- AH-057 — the job body sits in ONE outlined card, the BrandDetailPage
         shape (back link outside, body framed) with the jobs-list border, so a
         creator arriving from a card lands on the same surface they clicked.
         The skeleton and the two failure alerts stay OUTSIDE it: a bordered card
         wrapping a tonal alert double-frames the message. -->
    <v-card
      v-else-if="job"
      variant="outlined"
      class="job-detail__card"
      data-testid="creator-job-detail-card"
    >
      <v-card-text class="job-detail__body">
        <header class="job-detail__head">
          <v-avatar v-if="job.attributes.brand?.logo_url" size="56" rounded="lg">
            <v-img :src="job.attributes.brand.logo_url" :alt="job.attributes.brand.name" />
          </v-avatar>
          <div>
            <p class="job-detail__brand" data-testid="creator-job-detail-brand">
              {{ job.attributes.brand?.name ?? '—' }}
            </p>
            <h1 class="text-h4" data-testid="creator-job-detail-name">{{ job.attributes.name }}</h1>
          </div>
        </header>

        <div class="job-detail__meta">
          <span v-if="job.attributes.listing_fee" data-testid="creator-job-detail-fee">
            {{ t('creator.ui.jobs.fee') }}: {{ job.attributes.listing_fee }}
          </span>
          <span v-if="job.attributes.listing_duration">
            {{ t('creator.ui.jobs.duration') }}: {{ job.attributes.listing_duration }}
          </span>
          <span data-testid="creator-job-detail-applicants">
            {{ t('creator.ui.jobs.applicants', job.attributes.applicant_count) }}
          </span>
        </div>

        <section v-if="job.attributes.description" class="job-detail__section">
          <h2 class="text-subtitle-1">{{ t('creator.ui.jobs.detail.about') }}</h2>
          <p class="job-detail__description" data-testid="creator-job-detail-description">
            {{ job.attributes.description }}
          </p>
        </section>

        <section v-if="job.attributes.listing_languages?.length" class="job-detail__section">
          <h2 class="text-subtitle-1">{{ t('creator.ui.jobs.detail.languages') }}</h2>
          <div class="job-detail__chips">
            <v-chip
              v-for="language in job.attributes.listing_languages"
              :key="language"
              size="small"
              variant="tonal"
            >
              {{ language.toUpperCase() }}
            </v-chip>
          </div>
        </section>

        <section v-if="job.attributes.listing_regions?.length" class="job-detail__section">
          <h2 class="text-subtitle-1">{{ t('creator.ui.jobs.detail.regions') }}</h2>
          <div class="job-detail__chips">
            <v-chip
              v-for="region in job.attributes.listing_regions"
              :key="region"
              size="small"
              variant="tonal"
            >
              {{ region }}
            </v-chip>
          </div>
        </section>

        <div class="job-detail__links">
          <!-- External, agency-authored links: rel="noopener" so the opened tab
               cannot reach back into this one via window.opener. -->
          <v-btn
            v-if="job.attributes.listing_examples_url"
            variant="outlined"
            size="small"
            append-icon="mdi-open-in-new"
            :href="job.attributes.listing_examples_url"
            target="_blank"
            rel="noopener noreferrer"
            data-testid="creator-job-examples"
          >
            {{ t('creator.ui.jobs.detail.examples') }}
          </v-btn>
          <v-btn
            v-if="job.attributes.brand?.website_url"
            variant="outlined"
            size="small"
            append-icon="mdi-open-in-new"
            :href="job.attributes.brand.website_url"
            target="_blank"
            rel="noopener noreferrer"
            data-testid="creator-job-website"
          >
            {{ t('creator.ui.jobs.detail.website') }}
          </v-btn>
        </div>
      </v-card-text>

      <!-- The outcome of the caller's own application, or the way to make one.
           In the footer because it is the card's ACTION, not its content. -->
      <v-card-actions class="job-detail__actions">
        <v-alert
          v-if="status === 'rejected'"
          type="info"
          variant="tonal"
          density="compact"
          data-testid="creator-job-rejected-notice"
        >
          {{ t('creator.ui.jobs.detail.rejectedNotice') }}
        </v-alert>

        <!-- The D7 bridge (AH-058) — an accepted applicant has a real offer
             waiting elsewhere, so this notice is the way there rather than a
             dead end saying "applied". The link appears only when the server
             gave a ULID: an accepted application whose assignment is gone
             degrades to the notice alone instead of a link into a 404. -->
        <v-alert
          v-else-if="status === 'accepted'"
          type="success"
          variant="tonal"
          density="compact"
          data-testid="creator-job-accepted-notice"
        >
          {{ t('creator.ui.jobs.detail.acceptedNotice') }}
          <template v-if="assignmentUlid" #append>
            <v-btn
              variant="text"
              size="small"
              :to="{ name: 'creator.assignment.detail', params: { ulid: assignmentUlid } }"
              data-testid="creator-job-accepted-link"
            >
              {{ t('creator.ui.jobs.detail.viewOffer') }}
            </v-btn>
          </template>
        </v-alert>

        <v-alert
          v-else-if="status !== null"
          type="success"
          variant="tonal"
          density="compact"
          data-testid="creator-job-applied-notice"
        >
          {{ t('creator.ui.jobs.detail.appliedNotice') }}
        </v-alert>

        <v-btn
          v-else
          color="primary"
          variant="flat"
          :disabled="!canApply"
          data-testid="creator-job-apply"
          @click="openDialog()"
        >
          {{ t('creator.ui.jobs.detail.apply') }}
        </v-btn>
      </v-card-actions>
    </v-card>

    <v-dialog v-model="dialogOpen" max-width="520" data-testid="creator-job-apply-dialog">
      <v-card>
        <v-card-title>{{ t('creator.ui.jobs.applyDialog.title') }}</v-card-title>
        <v-card-text>
          <p class="mb-3">{{ t('creator.ui.jobs.applyDialog.body') }}</p>
          <v-textarea
            v-model="note"
            :label="t('creator.ui.jobs.applyDialog.noteLabel')"
            :hint="t('creator.ui.jobs.applyDialog.noteHint')"
            :counter="NOTE_MAX_LENGTH"
            :maxlength="NOTE_MAX_LENGTH"
            rows="4"
            persistent-hint
            data-testid="creator-job-apply-note"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" data-testid="creator-job-apply-cancel" @click="dialogOpen = false">
            {{ t('creator.ui.jobs.applyDialog.cancel') }}
          </v-btn>
          <v-btn
            color="primary"
            variant="flat"
            :loading="submitting"
            data-testid="creator-job-apply-submit"
            @click="submitApplication()"
          >
            {{ t('creator.ui.jobs.applyDialog.submit') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar
      :model-value="snackbar !== null"
      :timeout="4000"
      :color="snackbar?.color"
      data-testid="creator-job-snackbar"
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
.job-detail {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 16px;
}

/* The page column is `align-items: flex-start` (so the back button and Apply
   hug their content); the card is the one child that must span it. */
.job-detail__card {
  align-self: stretch;
}

/* The body carries the column rhythm the sections used to get from `.job-detail`
   directly, now that a card sits between them. */
.job-detail__body {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.job-detail__actions {
  padding: 0 16px 16px;
}

.job-detail__head {
  display: flex;
  align-items: center;
  gap: 16px;
}

.job-detail__brand {
  margin: 0;
  font-size: 0.875rem;
  opacity: 0.75;
}

.job-detail__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 16px;
  font-size: 0.9375rem;
  opacity: 0.85;
}

.job-detail__section {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.job-detail__description {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
}

.job-detail__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.job-detail__links {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
</style>
