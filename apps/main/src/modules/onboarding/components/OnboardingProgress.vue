<script setup lang="ts">
/**
 * OnboardingProgress — the per-step progress indicator (Sprint 3
 * Chunk 3 sub-step 2).
 *
 * Renders each wizard step as a row with:
 *   - Status icon (done / current / pending / skipped)
 *   - Localized step name
 *   - Optional "Skipped" badge per Decision E1=c (flag-OFF steps
 *     surface a documented skip-explanation; status icon paired
 *     with color so colour is never the only signal — 01-UI-UX § 9)
 *
 * Sourcing:
 *   - Step list: `useOnboardingStore.creator.wizard.steps` (backend
 *     authoritative; the order is the backend's `WizardStep::ordered()`).
 *   - Per-step completion: `stepCompletion` getter.
 *   - Flag state: `flags` getter (for Skipped rendering — when a
 *     vendor-gated step is flag-OFF the step is auto-completed by
 *     the backend's stepCompletion logic, so we render "Skipped"
 *     visually distinct from "Completed").
 *
 * a11y (F2=b):
 *   - The progress list is a `<nav aria-label="Onboarding progress">`.
 *   - Each step button has an aria-current="step" when active.
 *   - Status icons carry an aria-hidden attribute (text label is the
 *     accessible name).
 */

import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'

import { useOnboardingStore } from '../stores/useOnboardingStore'
import { WIZARD_STEP_ROUTE_NAMES } from '../routes'
import type { CreatorWizardStepId } from '@catalyst/api-client'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const onboardingStore = useOnboardingStore()

const { creator, stepCompletion, flags } = storeToRefs(onboardingStore)

interface StepView {
  id: CreatorWizardStepId
  routeName: string
  isComplete: boolean
  isCurrent: boolean
  isSkipped: boolean
  position: number
}

const FLAG_BY_STEP: Partial<Record<CreatorWizardStepId, keyof NonNullable<typeof flags.value>>> = {
  kyc: 'kyc_verification_enabled',
  payout: 'creator_payout_method_enabled',
  contract: 'contract_signing_enabled',
}

const steps = computed<StepView[]>(() => {
  // Display order: the eight `WizardStep::ordered()` cases, with
  // Review being the final "submit" landing. We don't render Review
  // as a navigable step in the progress indicator — it's reached via
  // "Submit for approval" on the last substantive step.
  const baseList: CreatorWizardStepId[] = [
    'profile',
    'social',
    'portfolio',
    'kyc',
    'tax',
    'payout',
    'contract',
  ]

  return baseList.map((id, index) => {
    const isComplete = stepCompletion.value[id] ?? false
    const flagKey = FLAG_BY_STEP[id]
    const isFlagOff =
      flagKey !== undefined && flags.value !== null && flags.value[flagKey] === false
    return {
      id,
      routeName: WIZARD_STEP_ROUTE_NAMES[id],
      isComplete,
      isCurrent: route.name === WIZARD_STEP_ROUTE_NAMES[id],
      // A step is "skipped" only when (a) the flag is off AND (b) the
      // creator has not vendor-completed it (e.g. NotRequired stamp).
      // Vendor-completed steps render "Completed" regardless of flag
      // state — the chunk-2 forensic distinction.
      isSkipped: isFlagOff && isComplete,
      position: index + 2, // Step 1 (account creation) is implicit
    }
  })
})

function navigateTo(stepRouteName: string): void {
  void router.push({ name: stepRouteName })
}

function statusIcon(step: StepView): string {
  if (step.isSkipped) {
    return 'mdi-minus-circle-outline'
  }
  if (step.isComplete) {
    return 'mdi-check-circle'
  }
  if (step.isCurrent) {
    return 'mdi-circle-slice-3'
  }
  return 'mdi-circle-outline'
}

function statusColor(step: StepView): string {
  if (step.isSkipped) {
    return 'on-surface-variant'
  }
  if (step.isComplete) {
    return 'success'
  }
  if (step.isCurrent) {
    return 'primary'
  }
  return 'on-surface-variant'
}

function statusLabel(step: StepView): string {
  if (step.isSkipped) {
    return t('creator.ui.wizard.progress.skipped')
  }
  if (step.isComplete) {
    return t('creator.ui.wizard.progress.completed')
  }
  if (step.isCurrent) {
    return t('creator.ui.wizard.progress.current')
  }
  return t('creator.ui.wizard.progress.pending')
}
</script>

<template>
  <nav
    v-if="creator"
    :aria-label="t('creator.ui.wizard.title')"
    class="onboarding-progress"
    data-test="onboarding-progress-list"
  >
    <ol class="onboarding-progress__list">
      <li
        v-for="step in steps"
        :key="step.id"
        class="onboarding-progress__item"
        :class="{
          'is-current': step.isCurrent,
          'is-complete': step.isComplete,
          'is-skipped': step.isSkipped,
        }"
        :data-test="`progress-step-${step.id}`"
      >
        <button
          type="button"
          class="onboarding-progress__button"
          :aria-current="step.isCurrent ? 'step' : undefined"
          @click="navigateTo(step.routeName)"
        >
          <v-icon
            :icon="statusIcon(step)"
            :color="statusColor(step)"
            size="small"
            aria-hidden="true"
            class="onboarding-progress__icon"
          />
          <span class="onboarding-progress__text">
            <span class="onboarding-progress__index text-caption text-medium-emphasis">
              {{ t('creator.ui.wizard.progress.step_of', { current: step.position, total: 9 }) }}
            </span>
            <span class="onboarding-progress__name text-body-2">
              {{ t(`creator.ui.wizard.steps.${step.id}.name`) }}
            </span>
            <span class="onboarding-progress__status text-caption">
              {{ statusLabel(step) }}
            </span>
          </span>
        </button>
        <p
          v-if="step.isSkipped"
          class="onboarding-progress__skipped-note text-caption text-medium-emphasis"
          :data-test="`progress-step-${step.id}-skipped-note`"
        >
          {{ t('creator.ui.wizard.skipped.explanation') }}
        </p>
      </li>
    </ol>
  </nav>
</template>

<style scoped>
.onboarding-progress__list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.onboarding-progress__item {
  border-radius: 6px;
  transition: background-color 0.15s ease;
}

.onboarding-progress__item.is-current {
  background-color: rgb(var(--v-theme-primary) / 0.08);
}

.onboarding-progress__button {
  width: 100%;
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
  padding: 0.5rem 0.75rem;
  background: transparent;
  border: none;
  cursor: pointer;
  text-align: left;
  border-radius: 6px;
  color: rgb(var(--v-theme-on-surface));
}

.onboarding-progress__button:hover {
  background-color: rgb(var(--v-theme-on-surface) / 0.04);
}

.onboarding-progress__button:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.onboarding-progress__icon {
  margin-top: 0.15rem;
}

.onboarding-progress__text {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
}

.onboarding-progress__index {
  display: block;
}

.onboarding-progress__name {
  font-weight: 500;
}

.onboarding-progress__status {
  margin-top: 0.15rem;
  color: rgb(var(--v-theme-on-surface-variant));
}

.onboarding-progress__skipped-note {
  margin: 0.25rem 0 0.5rem 2.25rem;
  max-width: 22rem;
}
</style>
