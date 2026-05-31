<script setup lang="ts">
/**
 * CEmptyState — shared empty-state scaffold (Sprint 3.5 Chunk 2 § 1.4).
 *
 * Replaces the hand-rolled empty-state blocks that were duplicated across
 * pages (centered icon + title + body + optional CTA). Each section renders
 * only when its prop/slot is provided, so the same component serves both the
 * "no-results-at-all" and "no-results-matching-filter" variants cleanly.
 *
 * API (Decision D-empty-state + Q-chunk-2-3 = slot-only icon):
 *   - `title` / `body` — props (the common case). Pass already-localized
 *     strings; this package stays i18n-free (the consumer calls `t(...)`).
 *   - `icon` slot      — optional; caller supplies a <v-icon> / custom SVG.
 *   - `action` slot    — optional; caller supplies a CTA button.
 *   - `dataTest`       — applied to the root for spec/Playwright anchoring.
 *     Call-site migrations preserve each existing `data-test` anchor.
 *
 * Styling consumes the Engine C v2 type scale (`--catalyst-typography-*`)
 * and zinc neutrals via the Vuetify theme (`rgb(var(--v-theme-on-surface*))`),
 * so it re-themes automatically across light/dark.
 *
 * Tests are co-located in `apps/main/tests/unit/` — `packages/ui` has no
 * Vitest harness of its own (see docs/tech-debt.md, "packages/ui has no
 * test harness").
 */

interface Props {
  /** Pre-localized title. Omit for icon-only / body-only variants. */
  title?: string
  /** Pre-localized body copy. */
  body?: string
  /** Root `data-test` anchor (preserved from the migrated call site). */
  dataTest?: string
}

defineProps<Props>()
</script>

<template>
  <div :data-test="dataTest" class="c-empty-state">
    <div v-if="$slots.icon" class="c-empty-state__icon">
      <slot name="icon" />
    </div>
    <h3 v-if="title" class="c-empty-state__title">{{ title }}</h3>
    <p v-if="body" class="c-empty-state__body">{{ body }}</p>
    <div v-if="$slots.action" class="c-empty-state__action">
      <slot name="action" />
    </div>
  </div>
</template>

<style scoped>
.c-empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: var(--space-8, 32px);
}

.c-empty-state__icon {
  margin-bottom: var(--space-3, 12px);
  color: rgb(var(--v-theme-on-surface-variant));
}

.c-empty-state__title {
  margin: 0 0 var(--space-2, 8px);
  font-size: var(--catalyst-typography-heading-3-size);
  font-weight: var(--catalyst-typography-heading-3-weight);
  line-height: var(--catalyst-typography-heading-3-line-height);
  color: rgb(var(--v-theme-on-surface));
}

.c-empty-state__body {
  margin: 0;
  font-size: var(--catalyst-typography-body-size);
  line-height: var(--catalyst-typography-body-line-height);
  color: rgb(var(--v-theme-on-surface-variant));
}

.c-empty-state__action {
  margin-top: var(--space-6, 24px);
}
</style>
