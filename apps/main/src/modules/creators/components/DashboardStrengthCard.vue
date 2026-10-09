<script setup lang="ts">
/**
 * DashboardStrengthCard — the "Profile strength" card (Figma frame 07).
 *
 * AH-100c. Rendered ONLY inside the Capacitor app, on the approved dashboard;
 * the web dashboard keeps `CompletenessBar`. A title + percent row, a 6px bar,
 * and — when the creator still has an incomplete step — one row naming it.
 *
 * Data-shape deviation from Figma: the row says "Complete {step} to reach
 * 100%", not "Add a YouTube account to reach 100%". The completeness payload
 * exposes which wizard STEP is incomplete, never which field inside it, and
 * this batch builds no new API surface. Only the steps the Edit profile page
 * can finish are links; the others (identity, tax, payout, agreement) have no
 * post-approval surface, so their row is plain text.
 */

import type { CreatorWizardStepId } from '@catalyst/api-client'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

interface Props {
  /** Integer 0..100 — the backend computes this. */
  score: number
  /** The first incomplete wizard step, or null when none is. */
  nextStep: CreatorWizardStepId | null
}

const props = defineProps<Props>()

const { t, locale } = useI18n()

/** The wizard steps the Edit profile page can complete. */
const EDITABLE_STEPS: ReadonlySet<CreatorWizardStepId> = new Set(['profile', 'social', 'portfolio'])

const clamped = computed(() => Math.min(100, Math.max(0, Math.round(props.score))))

/** "97%" — formatted for the locale ("97 %" in French, etc.). */
const percentText = computed(() =>
  new Intl.NumberFormat(locale.value, { style: 'percent' }).format(clamped.value / 100),
)

const nextText = computed(() =>
  props.nextStep === null || clamped.value >= 100
    ? null
    : t('creator.ui.dashboard.native.strength.next', {
        step: t(`creator.ui.wizard.steps.${props.nextStep}.name`),
      }),
)

const linkable = computed(() => props.nextStep !== null && EDITABLE_STEPS.has(props.nextStep))
</script>

<template>
  <section class="dash-strength" data-testid="dashboard-strength">
    <div class="dash-strength__head">
      <h2 class="dash-strength__title">{{ t('creator.ui.dashboard.native.strength.title') }}</h2>
      <span class="dash-strength__value" data-testid="dashboard-strength-value">
        {{ percentText }}
      </span>
    </div>

    <v-progress-linear
      class="dash-strength__bar"
      :model-value="clamped"
      color="primary"
      height="6"
      rounded
      :aria-valuetext="t('creator.ui.dashboard.completeness', { percent: clamped })"
      :aria-valuenow="clamped"
      aria-valuemin="0"
      aria-valuemax="100"
    />

    <template v-if="nextText !== null">
      <router-link
        v-if="linkable"
        class="dash-strength__next"
        :to="{ name: 'creator.profile' }"
        data-testid="dashboard-strength-next"
      >
        <span>{{ nextText }}</span>
        <v-icon icon="mdi-chevron-right" size="16" aria-hidden="true" />
      </router-link>
      <p v-else class="dash-strength__next" data-testid="dashboard-strength-next">
        <span>{{ nextText }}</span>
      </p>
    </template>
  </section>
</template>
