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
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { resolveSubmitErrorKey } from '../../onboarding/composables/useSubmitErrorKey'
import { useOnboardingStore } from '../../onboarding/stores/useOnboardingStore'

const { t } = useI18n()
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
