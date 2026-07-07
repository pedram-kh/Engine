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

import { CompletenessBar } from '@catalyst/ui'
import { computed, ref } from 'vue'

import { type WizardStepStatus } from '../composables/useFeatureFlags'
import { resolveSubmitErrorKey } from '../composables/useSubmitErrorKey'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import {
  VISIBLE_UX_STEPS,
  resolveUxStepComplete,
  resolveUxStepStatus,
  uxStepTitleKey,
} from '../composables/useWizardSteps'
import { useOnboardingStore } from '../stores/useOnboardingStore'

const { t } = useI18n()
const router = useRouter()
const store = useOnboardingStore()

const submitErrorKey = ref<string | null>(null)

const score = computed(() => store.completenessScore)
const completenessLabel = computed(() =>
  t('creator.ui.wizard.steps.review.completeness', { percent: score.value }),
)
const completenessColor = computed(() => (score.value >= 100 ? 'success' : 'primary'))

/**
 * Sprint 3 stabilization (May 19, 2026): rows now carry their visible
 * status (Completed / Skipped / Not started) AND the page surfaces an
 * inline list of incomplete step names when Submit is disabled. The
 * previous version showed a status ICON per row but no text + a
 * disabled button with no explanation, leaving creators stuck staring
 * at a greyed-out CTA wondering which step needed attention.
 */
const STATUS_I18N_KEY: Record<WizardStepStatus, string> = {
  completed: 'creator.ui.wizard.progress.completed',
  skipped: 'creator.ui.wizard.progress.skipped',
  'not-started': 'creator.ui.wizard.progress.pending',
}

interface StepRow {
  id: string
  /** Ordinal within the full visible step list — matches the side rail. */
  position: number
  name: string
  isComplete: boolean
  status: WizardStepStatus
  statusLabel: string
  routeName: string
}

// Review rows are DERIVED from the visible UX step list (AH-003): the
// static "Account created" row (always complete, non-editable — there is
// no in-wizard route back to sign-up), profile, the merged "connections"
// step, and contract. Hidden steps (kyc/tax/payout) never appear, and
// social/portfolio collapse into one row whose completion requires BOTH
// sub-sections. The position is each step's ordinal in the full visible
// list so the numbering matches the side rail (review itself is the last
// ordinal but renders as this page, not as a row).
const stepRows = computed<StepRow[]>(() =>
  VISIBLE_UX_STEPS.filter((step) => step.id !== 'review').map((step) => {
    const isComplete = resolveUxStepComplete(step, store.stepCompletion)
    const status = resolveUxStepStatus(
      step,
      store.stepCompletion,
      store.flags,
      store.clickThroughAccepted,
    )
    return {
      id: step.id,
      position: VISIBLE_UX_STEPS.indexOf(step) + 1,
      name: t(uxStepTitleKey(step)),
      isComplete,
      status,
      statusLabel: t(STATUS_I18N_KEY[status]),
      routeName: step.routeName ?? '',
    }
  }),
)

const incompleteSteps = computed(() => stepRows.value.filter((row) => !row.isComplete))
const canSubmit = computed(() => incompleteSteps.value.length === 0 && !store.isSubmitted)

/**
 * Joined human-readable names of every incomplete step, in display
 * order. Localised so pt-BR / it-IT see "Profile basics, Tax
 * information" with the same step names the side rail uses.
 */
const incompleteStepNames = computed(() => incompleteSteps.value.map((row) => row.name).join(', '))

async function submit(): Promise<void> {
  submitErrorKey.value = null
  try {
    await store.submit()
    await router.push('/creator/dashboard')
  } catch (error) {
    submitErrorKey.value = resolveSubmitErrorKey(error, 'creator.wizard.incomplete')
  }
}

async function goToStep(routeName: string): Promise<void> {
  if (routeName === '') return
  await router.push({ name: routeName })
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
        :class="{
          'review-step__row--incomplete': row.status === 'not-started',
          'review-step__row--skipped': row.status === 'skipped',
        }"
        :data-testid="`review-row-${row.id}`"
        :data-complete="row.isComplete"
        :data-status="row.status"
      >
        <v-icon
          :icon="row.isComplete ? 'mdi-check-circle' : 'mdi-alert-circle-outline'"
          :color="
            row.status === 'completed'
              ? 'success'
              : row.status === 'skipped'
                ? 'on-surface-variant'
                : 'warning'
          "
          size="20"
          aria-hidden="true"
        />
        <span
          class="review-step__row-number text-caption text-medium-emphasis"
          :data-testid="`review-row-number-${row.id}`"
        >
          {{ row.position }}
        </span>
        <span class="review-step__row-name">{{ row.name }}</span>
        <span
          class="review-step__row-status text-caption"
          :data-testid="`review-row-status-${row.id}`"
        >
          {{ row.statusLabel }}
        </span>
        <!-- The account row is not navigable (no in-wizard route back to
             sign-up), so it renders without an Edit affordance. -->
        <v-btn
          v-if="row.routeName !== ''"
          variant="text"
          size="small"
          :data-testid="`review-edit-${row.id}`"
          @click="goToStep(row.routeName)"
        >
          {{ t('creator.ui.wizard.steps.review.edit_step') }}
        </v-btn>
        <span v-else class="review-step__row-no-action" aria-hidden="true"></span>
      </li>
    </ul>

    <div
      v-if="!canSubmit && incompleteSteps.length > 0"
      role="status"
      class="review-step__blocker"
      data-testid="review-incomplete-blocker"
    >
      <v-icon icon="mdi-alert-circle-outline" color="warning" size="20" aria-hidden="true" />
      <span>
        {{
          t(
            'creator.ui.wizard.steps.review.incomplete_blocker',
            { count: incompleteSteps.length, names: incompleteStepNames },
            incompleteSteps.length,
          )
        }}
      </span>
    </div>

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
  grid-template-columns: 24px auto 1fr auto auto;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border: 1px solid rgb(var(--v-theme-outline-variant, var(--v-theme-outline)));
  border-radius: 6px;
}

.review-step__row-number {
  min-width: 1ch;
  text-align: right;
  font-variant-numeric: tabular-nums;
}

/* Visible "this is why submit is disabled" cue on the offending row. */
.review-step__row--incomplete {
  border-color: rgb(var(--v-theme-warning));
  background-color: rgb(var(--v-theme-warning) / 0.06);
}

.review-step__row--skipped {
  background-color: rgb(var(--v-theme-surface-variant) / 0.4);
}

.review-step__row-name {
  font-weight: 500;
}

.review-step__row-status {
  color: rgb(var(--v-theme-on-surface-variant));
}

.review-step__row--incomplete .review-step__row-status {
  color: rgb(var(--v-theme-warning));
  font-weight: 500;
}

.review-step__blocker {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  border: 1px solid rgb(var(--v-theme-warning));
  background-color: rgb(var(--v-theme-warning) / 0.06);
  border-radius: 6px;
  color: rgb(var(--v-theme-on-surface));
  font-size: 0.875rem;
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
