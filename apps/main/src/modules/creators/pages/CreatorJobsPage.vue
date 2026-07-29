<script setup lang="ts">
/**
 * CreatorJobsPage — the creator's job board (Jobs Board chunk 3, AH-056, D4/D9).
 *
 * The listed campaigns of every agency that has this creator on its roster,
 * newest listing first. The server owns visibility entirely: this page renders
 * whatever `GET /creators/me/jobs` returns and filters nothing, because a
 * client-side filter is a second predicate that would drift from the first.
 *
 * An empty board is a legitimate state, not an error — an unapproved creator,
 * a creator on nobody's roster, and a roster whose agencies have listed nothing
 * all land on the same empty state.
 *
 * The recency chip reads `listed_at`, which the backend stamps only on the
 * listing flip. It is never `updated_at`: a campaign whose Settings tab was
 * saved this morning has not been "listed today", and claiming so would be a
 * small lie told to every creator on the roster.
 */

import { type CreatorJobCardResource, type JobLifecycleState } from '@catalyst/api-client'
import { CEmptyState } from '@catalyst/ui'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { creatorJobsApi } from '../jobs.api'

const { t } = useI18n()

/**
 * The reflection's chip colours (AH-059, D5). `ended` is deliberately neutral
 * rather than `error`: an engagement can end by the creator's own decline, and
 * painting their own choice red would read as a reprimand.
 *
 * Typed as a total record over the union, so a fourth state cannot ship a chip
 * with no colour — `tsc` refuses the incomplete map, which is the same
 * exhaustiveness posture the API-side `match` has.
 */
const LIFECYCLE_COLOR: Record<JobLifecycleState, string> = {
  in_progress: 'primary',
  completed: 'success',
  ended: 'default',
}

const jobs = ref<CreatorJobCardResource[]>([])
const loading = ref(false)
const loadedOnce = ref(false)
const page = ref(1)
const lastPage = ref(1)

const isEmpty = computed(() => loadedOnce.value && jobs.value.length === 0)

/**
 * "Listed today" / "Listed N days ago", from the flip stamp. Null renders no
 * chip at all rather than a placeholder — the honest answer to "when was this
 * listed?" is sometimes "we don't know".
 */
function recencyLabel(listedAt: string | null): string | null {
  if (listedAt === null) return null

  const listed = new Date(listedAt)
  if (Number.isNaN(listed.getTime())) return null

  const days = Math.max(0, Math.floor((Date.now() - listed.getTime()) / (24 * 60 * 60 * 1000)))

  return days === 0 ? t('creator.ui.jobs.listedToday') : t('creator.ui.jobs.listedDaysAgo', days)
}

/**
 * The card's engagement stage, or null when the pair has no assignment.
 *
 * A named accessor rather than an inline property read, because the template
 * consults it three times (guard, colour, label) and the branch-ordering rule
 * above is easier to keep honest when there is one thing to order over.
 */
function lifecycleState(job: CreatorJobCardResource): JobLifecycleState | null {
  return job.attributes.assignment_state
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const res = await creatorJobsApi.list({ page: page.value })
    jobs.value = res.data
    lastPage.value = res.meta.last_page
  } catch {
    jobs.value = []
  } finally {
    loading.value = false
    loadedOnce.value = true
  }
}

async function goToPage(next: number): Promise<void> {
  page.value = next
  await load()
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="creator-jobs" data-testid="creator-jobs">
    <header>
      <h1 class="text-h4">{{ t('creator.ui.jobs.title') }}</h1>
      <p class="text-body-1 text-medium-emphasis">{{ t('creator.ui.jobs.subtitle') }}</p>
    </header>

    <v-skeleton-loader
      v-if="loading && !loadedOnce"
      type="card, card"
      data-testid="creator-jobs-skeleton"
    />

    <template v-else>
      <div v-if="!isEmpty" class="jobs" data-testid="creator-jobs-list">
        <v-card
          v-for="job in jobs"
          :key="job.id"
          class="job"
          variant="outlined"
          :to="{ name: 'creator.job.detail', params: { ulid: job.id } }"
          :data-testid="`creator-job-${job.id}`"
        >
          <div class="job__head">
            <v-avatar v-if="job.attributes.brand?.logo_url" size="40" rounded="lg">
              <v-img
                :src="job.attributes.brand.logo_url"
                :alt="job.attributes.brand.name"
                :data-testid="`creator-job-logo-${job.id}`"
              />
            </v-avatar>
            <div class="job__headings">
              <span class="job__brand" :data-testid="`creator-job-brand-${job.id}`">
                {{ job.attributes.brand?.name ?? '—' }}
              </span>
              <span class="job__name">{{ job.attributes.name }}</span>
            </div>
            <!-- ⚠ BRANCH ORDER IS THE FIX (AH-059, D1). The engagement's stage
                 renders FIRST, and the application's own answer only when there
                 is no engagement to report. Swap these two and "Not selected"
                 reappears beside a live invitation for the same campaign, which
                 is the contradiction Pedram found. Both chips are `v-if` /
                 `v-else-if` on one element chain rather than two independent
                 `v-if`s, so they cannot both render. -->
            <v-chip
              v-if="lifecycleState(job) !== null"
              size="small"
              variant="tonal"
              :color="LIFECYCLE_COLOR[lifecycleState(job)!]"
              :data-testid="`creator-job-lifecycle-${job.id}`"
            >
              {{ t(`creator.ui.jobs.lifecycle.${lifecycleState(job)}`) }}
            </v-chip>
            <v-chip
              v-else-if="job.attributes.application_status !== null"
              size="small"
              variant="tonal"
              :color="job.attributes.application_status === 'rejected' ? 'error' : 'primary'"
              :data-testid="`creator-job-applied-${job.id}`"
            >
              {{ t(`creator.ui.jobs.status.${job.attributes.application_status}`) }}
            </v-chip>
          </div>

          <div class="job__meta">
            <span v-if="job.attributes.listing_fee" :data-testid="`creator-job-fee-${job.id}`">
              {{ t('creator.ui.jobs.fee') }}: {{ job.attributes.listing_fee }}
            </span>
            <span v-if="job.attributes.listing_duration">
              {{ t('creator.ui.jobs.duration') }}: {{ job.attributes.listing_duration }}
            </span>
            <span :data-testid="`creator-job-applicants-${job.id}`">
              {{ t('creator.ui.jobs.applicants', job.attributes.applicant_count) }}
            </span>
          </div>

          <v-chip
            v-if="recencyLabel(job.attributes.listed_at) !== null"
            size="x-small"
            variant="text"
            :data-testid="`creator-job-recency-${job.id}`"
          >
            {{ recencyLabel(job.attributes.listed_at) }}
          </v-chip>
        </v-card>
      </div>

      <CEmptyState
        v-else
        data-test="creator-jobs-empty"
        :title="t('creator.ui.jobs.empty.title')"
        :body="t('creator.ui.jobs.empty.body')"
      >
        <template #icon>
          <v-icon icon="mdi-briefcase-search-outline" size="64" color="medium-emphasis" />
        </template>
      </CEmptyState>

      <v-pagination
        v-if="lastPage > 1"
        :model-value="page"
        :length="lastPage"
        density="comfortable"
        data-testid="creator-jobs-pagination"
        @update:model-value="goToPage"
      />
    </template>
  </section>
</template>

<style scoped>
.creator-jobs {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.jobs {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.job {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 16px;
}

.job__head {
  display: flex;
  align-items: center;
  gap: 12px;
}

.job__headings {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1 1 auto;
}

.job__brand {
  font-size: 0.8125rem;
  opacity: 0.75;
}

.job__name {
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.job__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 16px;
  font-size: 0.875rem;
  opacity: 0.85;
}

@media (max-width: 599px) {
  .job__name {
    white-space: normal;
  }
}
</style>
