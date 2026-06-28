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
 * Sourcing (AH-003): the rail is DERIVED from {@link VISIBLE_UX_STEPS}
 * (the single frontend step registry), not a hard-coded list or a
 * `TOTAL_STEPS = 9` constant. Build-time hidden steps (kyc/tax/payout)
 * are already absent from that list, and social + portfolio are merged
 * into the single "connections" UX step. Per-step completion + flag
 * state come from the store's `stepCompletion` + `flags` getters.
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
import {
  REVIEW_UX_STEPS,
  VISIBLE_UX_STEPS,
  WIZARD_TOTAL_STEPS,
  resolveUxStepComplete,
  resolveUxStepStatus,
  uxStepTitleKey,
} from '../composables/useWizardSteps'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const onboardingStore = useOnboardingStore()

const { creator, stepCompletion, flags, clickThroughAccepted } = storeToRefs(onboardingStore)

/**
 * Total step count visible in the rail = the static account row + every
 * visible UX step (review included, since it numbers the final submit
 * surface). Derived from {@link VISIBLE_UX_STEPS} so a reversible-hide
 * flip re-numbers automatically — never a magic `9`.
 */
const TOTAL_STEPS = WIZARD_TOTAL_STEPS

interface StepView {
  id: string
  titleKey: string
  routeName: string
  isComplete: boolean
  isCurrent: boolean
  isSkipped: boolean
  position: number
}

const steps = computed<StepView[]>(() =>
  // Navigable rows: every visible UX step except the static account row
  // and review (review is reached via "Submit" on the last step). The
  // position is the step's ordinal within the full visible list (account
  // is position 1), so the captions never skip a number.
  REVIEW_UX_STEPS.map((step) => {
    const isComplete = resolveUxStepComplete(step, stepCompletion.value)
    const status = resolveUxStepStatus(
      step,
      stepCompletion.value,
      flags.value,
      clickThroughAccepted.value,
    )
    return {
      id: step.id,
      titleKey: uxStepTitleKey(step),
      routeName: step.routeName ?? '',
      isComplete,
      isCurrent: route.name === step.routeName,
      isSkipped: status === 'skipped',
      position: VISIBLE_UX_STEPS.indexOf(step) + 1,
    }
  }),
)

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
      <!--
        Static Step 1 row — account creation is "implicit" (handled
        by the Identity module's sign-up endpoint and complete by the
        time the creator can authenticate). We surface it visually so
        the "Step X of N" numbering on the other rows isn't off by
        one from the user's perspective. Non-navigable: there's no
        in-wizard route to return to sign-up.
      -->
      <li
        class="onboarding-progress__item is-complete is-static"
        data-test="progress-step-account-created"
      >
        <div class="onboarding-progress__button onboarding-progress__button--static">
          <v-icon
            icon="mdi-check-circle"
            color="success"
            size="small"
            aria-hidden="true"
            class="onboarding-progress__icon"
          />
          <span class="onboarding-progress__text">
            <span class="onboarding-progress__index text-caption text-medium-emphasis">
              {{ t('creator.ui.wizard.progress.step_of', { current: 1, total: TOTAL_STEPS }) }}
            </span>
            <span class="onboarding-progress__name text-body-2">
              {{ t('creator.ui.wizard.steps.account_created.name') }}
            </span>
            <span class="onboarding-progress__status text-caption">
              {{ t('creator.ui.wizard.progress.completed') }}
            </span>
          </span>
        </div>
      </li>
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
              {{
                t('creator.ui.wizard.progress.step_of', {
                  current: step.position,
                  total: TOTAL_STEPS,
                })
              }}
            </span>
            <span class="onboarding-progress__name text-body-2">
              {{ t(step.titleKey) }}
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

/* Static Step 1 row — no hover state, not navigable. */
.onboarding-progress__button--static {
  cursor: default;
}

.onboarding-progress__button--static:hover {
  background-color: transparent;
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
