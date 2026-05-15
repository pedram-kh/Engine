<script setup lang="ts">
/**
 * CompletenessBar — render the creator's profile completeness
 * score as a labelled progress bar.
 *
 * Sprint 3 Chunk 3 sub-step 8 (Decision C1: display-shared,
 * form-main). Used in two places:
 *   - Wizard Step 9 (review) — drives the "almost there"
 *     orientation before submit.
 *   - Creator dashboard — running progress on the application
 *     itself for the not-yet-submitted state.
 *
 * The score is an integer 0..100. Backend's
 * `CompletenessScoreCalculator` computes this; the SPA renders
 * it without interpretation.
 *
 * Status → Vuetify colour mapping (no hard-coded thresholds —
 * the consumer passes the colour via prop so a future re-tune of
 * "what counts as complete enough" doesn't need this component
 * to know):
 *
 * a11y (F2=b): the progress bar uses
 * `<v-progress-linear :model-value :height :aria-valuetext>` so
 * screen readers announce the numeric value + the human-readable
 * "X% complete" string.
 */

interface Props {
  /** Integer 0..100 — the backend computes this. */
  score: number
  /** Localized "X% complete" label rendered alongside. */
  label: string
  /**
   * Vuetify colour token, e.g. `'primary'` or `'success'`. The
   * consumer is expected to map the score → colour locally; we
   * keep this component dumb so the design system gets to
   * re-tune thresholds without touching the shared component.
   */
  color?: string
}

const props = withDefaults(defineProps<Props>(), {
  color: 'primary',
})
</script>

<template>
  <div class="completeness-bar" data-testid="completeness-bar">
    <div class="completeness-bar__label">
      {{ props.label }}
    </div>
    <v-progress-linear
      :model-value="props.score"
      :color="props.color"
      :aria-valuetext="props.label"
      :aria-valuenow="props.score"
      aria-valuemin="0"
      aria-valuemax="100"
      height="8"
      rounded
    />
  </div>
</template>

<style scoped>
.completeness-bar {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.completeness-bar__label {
  font-size: 0.875rem;
  color: rgb(var(--v-theme-on-surface-variant));
}
</style>
