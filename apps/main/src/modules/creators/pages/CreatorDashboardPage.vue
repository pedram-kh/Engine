<script setup lang="ts">
/**
 * CreatorDashboardPage — post-submit creator surface.
 *
 * Sprint 3 Chunk 3 sub-step 8 (Refinement 5 — route path locked
 * at `/creator/dashboard` to avoid the agency-side `/dashboard`
 * namespace collision).
 *
 * Three render branches keyed by `creator.attributes.application_status`:
 *
 *   - `pending_review`: "Application under review" banner with
 *     the submitted-at timestamp + the completeness bar at 100%.
 *     This is the most common state on first land after a
 *     successful submit.
 *   - `approved`: "Welcome to Catalyst" banner + the read-only
 *     summary of the profile data (display name, country,
 *     completeness). Sprint 4+ adds the brand-collab surfaces
 *     here; Phase 1 is intentionally minimal.
 *   - `rejected`: "Application not approved" banner with the
 *     stored `rejection_reason` (when present) + a contact-support
 *     CTA. The creator cannot resubmit from the SPA — admin must
 *     reset the application status.
 *
 * On initial land (the SPA arrives here via the
 * `requireOnboardingAccess` guard after a successful submit),
 * `useOnboardingStore.bootstrap()` is called by the layout to
 * fetch the canonical state. This page only renders that state.
 *
 * a11y (F2=b): each banner uses `<v-alert>` with the appropriate
 * semantic type (info, success, error), so the chip colour is
 * never the only signal — icon + text are also distinct.
 */

import { formatDate } from '@catalyst/api-client'
import type { ConnectionRequestListItem } from '@catalyst/api-client'
import { CompletenessBar, CEmptyState } from '@catalyst/ui'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { resolveSubmitErrorKey } from '../../onboarding/composables/useSubmitErrorKey'
import { useOnboardingStore } from '../../onboarding/stores/useOnboardingStore'
import { connectionRequestsApi } from '../connectionRequests.api'
import { creatorAssignmentsApi } from '../assignments.api'

const { t, locale } = useI18n()
const router = useRouter()
const store = useOnboardingStore()

const status = computed(() => store.applicationStatus ?? 'incomplete')
const score = computed(() => store.completenessScore)
const completenessLabel = computed(() =>
  t('creator.ui.dashboard.completeness', { percent: score.value }),
)

const displayName = computed(() => store.creator?.attributes.display_name ?? '')
const submittedAt = computed(() => store.creator?.attributes.submitted_at)
// Sprint 4 Chunk 3 (Cluster 5 / D-c3-1): the reason is now surfaced on
// the creator-facing attributes (it used to be admin-only and thus null
// here). Read it directly from `attributes`.
const rejectionReason = computed(() => store.creator?.attributes.rejection_reason ?? null)

// Sprint 4 Chunk 3 (Cluster 6 / D-c3-9): creator-driven resubmit.
const isReopening = ref(false)
const reopenErrorKey = ref<string | null>(null)

async function resubmit(): Promise<void> {
  isReopening.value = true
  reopenErrorKey.value = null
  try {
    await store.reopen()
    await router.push({ name: 'onboarding.welcome-back' })
  } catch (error) {
    reopenErrorKey.value = resolveSubmitErrorKey(
      error,
      'creator.ui.dashboard.rejected.resubmit_failed',
    )
  } finally {
    isReopening.value = false
  }
}

// ── Connection requests inbox (Sprint 6.6c, D-d1) ──────────────────────────
// The incoming agency-request section, rendered ONLY in the approved branch
// (pending/rejected/incomplete creators have no agency relations). Component-
// local refs, no global store — re-fetch after a mutation so the actioned row
// drops from the pending set (the AvailabilityCalendar `onMutated → load()`
// precedent, D-d7).
const requests = ref<ConnectionRequestListItem[]>([])
const requestsLoading = ref(false)
const requestsLoadedOnce = ref(false)
/** The row currently being accepted/declined — drives its buttons' loading state. */
const actioningId = ref<string | null>(null)
const snackbar = ref<{ color: string; text: string } | null>(null)

const requestsEmpty = computed(() => requestsLoadedOnce.value && requests.value.length === 0)

/** "Sent {date}" for the row, localized; falls back when the timestamp is null. */
function sentLabel(iso: string | null): string {
  if (iso === null) return t('creator.ui.dashboard.requests.sent_unknown')
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return t('creator.ui.dashboard.requests.sent_unknown')
  return t('creator.ui.dashboard.requests.sent', {
    date: formatDate(date, locale.value),
  })
}

async function loadRequests(): Promise<void> {
  requestsLoading.value = true
  try {
    const res = await connectionRequestsApi.list()
    requests.value = res.data
  } catch {
    requests.value = []
  } finally {
    requestsLoading.value = false
    requestsLoadedOnce.value = true
  }
}

// ── Campaign-invitation teaser (Sprint 8 Chunk 2, D-10) ────────────────────
// A lightweight count of assignments awaiting the creator's response, linking
// to the dedicated /creator/assignments surface. Approved-only, like the inbox.
const invitedCount = ref(0)

async function loadInvitedCount(): Promise<void> {
  try {
    const res = await creatorAssignmentsApi.list()
    invitedCount.value = res.data.filter((a) => a.attributes.status === 'invited').length
  } catch {
    invitedCount.value = 0
  }
}

/**
 * Snackbar keyed on the backend's `meta.code` (D-d6), mirroring the
 * DiscoverProfilePage pattern. Accept names the agency; decline does not (there
 * is no creator-side connections surface to click through to).
 */
function snackbarFor(code: string, agencyName: string): { color: string; text: string } {
  switch (code) {
    case 'connection.accepted':
      return {
        color: 'success',
        text: t('creator.ui.dashboard.requests.toast.accepted', { agency: agencyName }),
      }
    case 'connection.declined':
    default:
      return { color: 'info', text: t('creator.ui.dashboard.requests.toast.declined') }
  }
}

async function acceptRequest(item: ConnectionRequestListItem): Promise<void> {
  if (actioningId.value !== null) return
  actioningId.value = item.id
  try {
    // POST the ROW's `id` (the relation ULID — D-d3), never the agency id.
    const res = await connectionRequestsApi.accept(item.id)
    snackbar.value = snackbarFor(res.meta.code, item.attributes.agency_name)
    await loadRequests()
  } catch {
    snackbar.value = { color: 'error', text: t('creator.ui.dashboard.requests.toast.error') }
  } finally {
    actioningId.value = null
  }
}

async function declineRequest(item: ConnectionRequestListItem): Promise<void> {
  if (actioningId.value !== null) return
  actioningId.value = item.id
  try {
    // Direct decline (no confirm — reversible via an agency re-request, D-d3).
    const res = await connectionRequestsApi.decline(item.id)
    snackbar.value = snackbarFor(res.meta.code, item.attributes.agency_name)
    await loadRequests()
  } catch {
    snackbar.value = { color: 'error', text: t('creator.ui.dashboard.requests.toast.error') }
  } finally {
    actioningId.value = null
  }
}

onMounted(async () => {
  if (!store.isBootstrapped) {
    await store.bootstrap()
  }
  // The inbox is approved-only — no fetch fires for any other branch (the
  // first creator-side fetch stays scoped to the surface that needs it).
  if (status.value === 'approved') {
    await loadRequests()
    await loadInvitedCount()
  }
})
</script>

<template>
  <section class="creator-dashboard" data-testid="creator-dashboard">
    <header class="creator-dashboard__header">
      <h1 class="text-h4">
        {{ t('creator.ui.dashboard.title') }}
      </h1>
      <p v-if="displayName" class="text-body-1 text-medium-emphasis">
        {{ t('creator.ui.dashboard.greeting', { name: displayName }) }}
      </p>
    </header>

    <v-alert
      v-if="status === 'pending'"
      type="info"
      variant="tonal"
      data-testid="dashboard-banner-pending"
    >
      <template #title>{{ t('creator.ui.dashboard.pending_review.title') }}</template>
      <p>{{ t('creator.ui.dashboard.pending_review.description') }}</p>
      <p v-if="submittedAt" class="text-caption mt-2" data-testid="dashboard-submitted-at">
        {{ t('creator.ui.dashboard.submitted_at', { datetime: submittedAt }) }}
      </p>
    </v-alert>

    <v-alert
      v-else-if="status === 'approved'"
      type="success"
      variant="tonal"
      data-testid="dashboard-banner-approved"
    >
      <template #title>{{ t('creator.ui.dashboard.approved.title') }}</template>
      <p>{{ t('creator.ui.dashboard.approved.description') }}</p>
    </v-alert>

    <v-alert
      v-else-if="status === 'rejected'"
      type="error"
      variant="tonal"
      data-testid="dashboard-banner-rejected"
    >
      <template #title>{{ t('creator.ui.dashboard.rejected.title') }}</template>
      <p>{{ t('creator.ui.dashboard.rejected.description') }}</p>
      <p v-if="rejectionReason" class="mt-2" data-testid="dashboard-rejection-reason">
        <strong>{{ t('creator.ui.dashboard.rejected.reason_label') }}</strong>
        {{ rejectionReason }}
      </p>
      <div class="mt-3">
        <v-btn
          color="primary"
          variant="flat"
          :loading="isReopening"
          data-testid="dashboard-resubmit"
          @click="resubmit"
        >
          {{ t('creator.ui.dashboard.rejected.resubmit') }}
        </v-btn>
      </div>
      <p
        v-if="reopenErrorKey"
        role="alert"
        class="mt-2 text-error"
        data-testid="dashboard-resubmit-error"
      >
        {{ t(reopenErrorKey) }}
      </p>
    </v-alert>

    <v-alert v-else type="warning" variant="tonal" data-testid="dashboard-banner-incomplete">
      <template #title>{{ t('creator.ui.dashboard.incomplete.title') }}</template>
      <p>{{ t('creator.ui.dashboard.incomplete.description') }}</p>
    </v-alert>

    <CompletenessBar
      :score="score"
      :label="completenessLabel"
      :color="score >= 100 ? 'success' : 'primary'"
    />

    <!-- Campaign-invitation teaser (Sprint 8 Chunk 2, D-10). Approved-only;
         links to the dedicated assignments surface. Shows a count badge when
         invitations await a response. -->
    <v-alert
      v-if="status === 'approved'"
      type="info"
      variant="tonal"
      border="start"
      data-testid="dashboard-assignments-teaser"
    >
      <div class="d-flex align-center justify-space-between flex-wrap ga-2">
        <div>
          <strong>{{ t('creator.ui.dashboard.assignments.title') }}</strong>
          <p class="text-body-2 mb-0">
            {{
              invitedCount > 0
                ? t('creator.ui.dashboard.assignments.pending', { count: invitedCount })
                : t('creator.ui.dashboard.assignments.none')
            }}
          </p>
        </div>
        <v-btn
          color="primary"
          variant="flat"
          size="small"
          :to="{ name: 'creator.assignments' }"
          data-testid="dashboard-assignments-cta"
        >
          {{ t('creator.ui.dashboard.assignments.cta') }}
        </v-btn>
      </div>
    </v-alert>

    <!-- Connection requests inbox (Sprint 6.6c, D-d1). Approved-only: the
         section never renders for pending/rejected/incomplete creators, who
         have no agency relations. A simple inserted block — the page's vertical
         flex accepts it without restructuring the banner logic. -->
    <section
      v-if="status === 'approved'"
      class="creator-dashboard__requests"
      data-testid="dashboard-requests"
    >
      <h2 class="text-h6">{{ t('creator.ui.dashboard.requests.title') }}</h2>

      <v-skeleton-loader
        v-if="requestsLoading && !requestsLoadedOnce"
        type="list-item-two-line, list-item-two-line"
        data-testid="dashboard-requests-skeleton"
      />

      <template v-else>
        <v-list v-if="!requestsEmpty" lines="two" data-testid="dashboard-requests-list">
          <v-list-item
            v-for="item in requests"
            :key="item.id"
            :title="item.attributes.agency_name"
            :subtitle="sentLabel(item.attributes.invitation_sent_at)"
            :data-testid="`dashboard-request-${item.id}`"
          >
            <template #append>
              <div class="d-flex ga-2">
                <v-btn
                  color="primary"
                  variant="flat"
                  size="small"
                  :loading="actioningId === item.id"
                  :disabled="actioningId !== null && actioningId !== item.id"
                  :data-testid="`dashboard-request-accept-${item.id}`"
                  @click="acceptRequest(item)"
                >
                  {{ t('creator.ui.dashboard.requests.accept') }}
                </v-btn>
                <v-btn
                  variant="tonal"
                  size="small"
                  :loading="actioningId === item.id"
                  :disabled="actioningId !== null && actioningId !== item.id"
                  :data-testid="`dashboard-request-decline-${item.id}`"
                  @click="declineRequest(item)"
                >
                  {{ t('creator.ui.dashboard.requests.decline') }}
                </v-btn>
              </div>
            </template>
          </v-list-item>
        </v-list>

        <CEmptyState
          v-else
          data-test="dashboard-requests-empty"
          :title="t('creator.ui.dashboard.requests.empty.title')"
          :body="t('creator.ui.dashboard.requests.empty.body')"
        >
          <template #icon>
            <v-icon icon="mdi-account-multiple-outline" size="64" color="medium-emphasis" />
          </template>
        </CEmptyState>
      </template>
    </section>

    <v-snackbar
      :model-value="snackbar !== null"
      :timeout="3000"
      :color="snackbar?.color"
      data-testid="dashboard-requests-snackbar"
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
.creator-dashboard {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

/* Aurora brand accent (Sprint 3.5 Chunk 4 — Decision D7, thin-accent-only):
 * a full-width 2px aurora rule along the header's bottom edge — the
 * creator-journey brand moment, reading as a deliberate header divider that
 * matches the auth card top-border + onboarding app-bar bottom-line (all
 * full-width aurora edge-lines). Consumes the authored utility var, never a
 * Vuetify theme.color (parity invariant 3 stays green). */
.creator-dashboard__header {
  padding-bottom: 16px;
  border-bottom: 2px solid transparent;
  border-image: var(--brand-aurora-gradient) 1;
}
</style>
