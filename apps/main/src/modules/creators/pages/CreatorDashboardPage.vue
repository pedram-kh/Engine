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

import { CompletenessBar } from '@catalyst/ui'
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'

import { useOnboardingStore } from '../../onboarding/stores/useOnboardingStore'

const { t } = useI18n()
const store = useOnboardingStore()

const status = computed(() => store.applicationStatus ?? 'incomplete')
const score = computed(() => store.completenessScore)
const completenessLabel = computed(() =>
  t('creator.ui.dashboard.completeness', { percent: score.value }),
)

const displayName = computed(() => store.creator?.attributes.display_name ?? '')
const submittedAt = computed(() => store.creator?.attributes.submitted_at)
const rejectionReason = computed(() => {
  const admin = (
    store.creator as unknown as { admin_attributes?: { rejection_reason?: string | null } }
  )?.admin_attributes
  return admin?.rejection_reason ?? null
})

onMounted(async () => {
  if (!store.isBootstrapped) {
    await store.bootstrap()
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
  </section>
</template>

<style scoped>
.creator-dashboard {
  display: flex;
  flex-direction: column;
  gap: 20px;
}
</style>
