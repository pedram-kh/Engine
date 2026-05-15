<script setup lang="ts">
/**
 * Step9ReviewPage — wizard Step 9 (Review and submit).
 *
 * Sprint 3 Chunk 3 sub-step 8.
 *
 * Read-only summary of every completed step + a single "Submit
 * application" button. The backend's `submit` endpoint validates
 * that every step is complete (or has a flag-OFF "not required"
 * status) and flips `application_status` to `pending_review`,
 * which the SPA-level `requireOnboardingAccess` guard sees on
 * the next bootstrap and redirects to `/creator/dashboard`.
 *
 * Layout (top-to-bottom):
 *   1. CompletenessBar — overall progress (Sprint 3 visual
 *      orientation; backend uses this as a soft signal, not as
 *      a gate).
 *   2. Per-step summary cards — each card shows the step's
 *      `is_complete` status + a "Back to step" link.
 *   3. Submit button — disabled if any required step is still
 *      incomplete.
 *
 * a11y (F2=b): the submit button is disabled with an
 * accessible description that explains WHY (links to the first
 * incomplete step). The success state lands on the dashboard,
 * which announces "Application submitted" via the dashboard
 * layout's status region.
 */

import { ApiError } from '@catalyst/api-client'
import type { CreatorWizardStepId } from '@catalyst/api-client'
import { CompletenessBar } from '@catalyst/ui'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { WIZARD_STEP_ROUTE_NAMES } from '../routes'
import { useOnboardingStore } from '../stores/useOnboardingStore'

const { t } = useI18n()
const router = useRouter()
const store = useOnboardingStore()

const submitErrorKey = ref<string | null>(null)

const REVIEWABLE_STEPS: ReadonlyArray<CreatorWizardStepId> = [
  'profile',
  'social',
  'portfolio',
  'kyc',
  'tax',
  'payout',
  'contract',
]

const score = computed(() => store.completenessScore)
const completenessLabel = computed(() =>
  t('creator.ui.wizard.steps.review.completeness', { percent: score.value }),
)
const completenessColor = computed(() => (score.value >= 100 ? 'success' : 'primary'))

const stepRows = computed(() =>
  REVIEWABLE_STEPS.map((step) => ({
    id: step,
    name: t(`creator.ui.wizard.steps.${step}.name`),
    isComplete: store.stepCompletion[step] ?? false,
    routeName: WIZARD_STEP_ROUTE_NAMES[step],
  })),
)

const incompleteSteps = computed(() => stepRows.value.filter((row) => !row.isComplete))
const canSubmit = computed(() => incompleteSteps.value.length === 0 && !store.isSubmitted)

async function submit(): Promise<void> {
  submitErrorKey.value = null
  try {
    await store.submit()
    await router.push('/creator/dashboard')
  } catch (error) {
    submitErrorKey.value = error instanceof ApiError ? error.code : 'creator.wizard.incomplete'
  }
}

async function goToStep(step: CreatorWizardStepId): Promise<void> {
  await router.push({ name: WIZARD_STEP_ROUTE_NAMES[step] })
}
</script>

<template>
  <section class="review-step" data-testid="step-review">
    <header class="review-step__header">
      <h2 class="text-h5">{{ t('creator.ui.wizard.steps.review.title') }}</h2>
      <p class="text-body-2 text-medium-emphasis">
        {{ t('creator.ui.wizard.steps.review.description') }}
      </p>
    </header>

    <CompletenessBar :score="score" :label="completenessLabel" :color="completenessColor" />

    <ul class="review-step__rows" data-testid="review-step-rows">
      <li
        v-for="row in stepRows"
        :key="row.id"
        class="review-step__row"
        :data-testid="`review-row-${row.id}`"
        :data-complete="row.isComplete"
      >
        <v-icon
          :icon="row.isComplete ? 'mdi-check-circle' : 'mdi-circle-outline'"
          :color="row.isComplete ? 'success' : 'on-surface-variant'"
          size="20"
          aria-hidden="true"
        />
        <span class="review-step__row-name">{{ row.name }}</span>
        <v-btn
          variant="text"
          size="small"
          :data-testid="`review-edit-${row.id}`"
          @click="goToStep(row.id)"
        >
          {{ t('creator.ui.wizard.steps.review.edit_step') }}
        </v-btn>
      </li>
    </ul>

    <div
      v-if="submitErrorKey"
      role="alert"
      class="review-step__error"
      data-testid="review-submit-error"
    >
      {{ t(submitErrorKey) }}
    </div>

    <div class="review-step__actions">
      <v-btn
        color="primary"
        :loading="store.isSubmitting"
        :disabled="!canSubmit"
        data-testid="review-submit"
        @click="submit"
      >
        {{ t('creator.ui.wizard.steps.review.submit_button') }}
      </v-btn>
    </div>
  </section>
</template>

<style scoped>
.review-step {
  display: flex;
  flex-direction: column;
  gap: 20px;
  max-width: 720px;
}

.review-step__rows {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.review-step__row {
  display: grid;
  grid-template-columns: 24px 1fr auto;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border: 1px solid rgb(var(--v-theme-outline-variant, var(--v-theme-outline)));
  border-radius: 6px;
}

.review-step__row-name {
  font-weight: 500;
}

.review-step__error {
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}

.review-step__actions {
  display: flex;
  justify-content: flex-end;
}
</style>
