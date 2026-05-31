<script setup lang="ts">
/**
 * CKpiCard — a workspace-home KPI tile (Sprint 4 Chunk 1, D-c1-10).
 *
 * The §11 "KPI strip" specimen: a small card with a `caption`-weight label
 * and a `heading-2` value. Reusable — four cards now (roster, pending
 * applications, plus the campaigns / payments placeholders), more as those
 * surfaces ship, and the admin SPA will want it too.
 *
 * i18n-free (matches `CEmptyState`'s contract): the consumer passes an
 * already-localized `label`; this package never calls `t(...)`.
 *
 * Placeholder state (D-c1-4 / D-c1-10): a `null` / `undefined` `value`
 * renders a muted em dash (`—`) — the card holds its slot in the strip and
 * becomes real in place when its backing data lands. No "coming soon" text.
 *
 * Typography is consumed via the Engine C v2 `--catalyst-typography-*` CSS
 * vars (no rem literals — `typography-consumption.spec.ts` scans this
 * package). Colours flow through the Vuetify theme
 * (`rgb(var(--v-theme-*))`), so the card re-themes automatically across
 * light / dark — exercised by the theme-aware harness spec.
 */

import { computed } from 'vue'

interface Props {
  /** Pre-localized caption label (e.g. "Creators in roster"). */
  label: string
  /**
   * The KPI value. `null` / `undefined` renders the muted placeholder dash
   * (campaigns / payments until those surfaces ship).
   */
  value?: number | null
  /** Show a skeleton in the value slot while the summary payload loads. */
  loading?: boolean
  /** Root `data-test` anchor. */
  dataTest?: string
}

const props = withDefaults(defineProps<Props>(), {
  value: null,
  loading: false,
})

const isPlaceholder = computed(() => props.value === null || props.value === undefined)
const displayValue = computed(() => (isPlaceholder.value ? '—' : String(props.value)))
</script>

<template>
  <v-card class="c-kpi-card" :data-test="dataTest" variant="flat" border rounded="lg">
    <span class="c-kpi-card__label">{{ label }}</span>
    <v-skeleton-loader
      v-if="loading"
      class="c-kpi-card__skeleton"
      type="text"
      data-test="kpi-card-skeleton"
    />
    <span
      v-else
      class="c-kpi-card__value"
      :class="{ 'c-kpi-card__value--placeholder': isPlaceholder }"
      data-test="kpi-card-value"
      >{{ displayValue }}</span
    >
  </v-card>
</template>

<style scoped>
.c-kpi-card {
  display: flex;
  flex-direction: column;
  gap: var(--space-2, 8px);
  padding: var(--space-5, 20px);
  background-color: rgb(var(--v-theme-surface));
}

.c-kpi-card__label {
  font-size: var(--catalyst-typography-caption-size);
  font-weight: var(--catalyst-typography-caption-weight);
  line-height: var(--catalyst-typography-caption-line-height);
  color: rgb(var(--v-theme-on-surface-variant));
}

.c-kpi-card__value {
  font-size: var(--catalyst-typography-heading-2-size);
  font-weight: var(--catalyst-typography-heading-2-weight);
  line-height: var(--catalyst-typography-heading-2-line-height);
  color: rgb(var(--v-theme-on-surface));
}

/* Placeholder slots (campaigns / payments) read as deliberately subdued —
 * the muted em dash should not compete with the real counts. */
.c-kpi-card__value--placeholder {
  color: rgb(var(--v-theme-on-surface-variant));
}

.c-kpi-card__skeleton {
  max-width: 64px;
}
</style>
