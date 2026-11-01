<script setup lang="ts">
/**
 * AH-114 (S2.4) — the agency fork of `CKpiCard` (Figma stat card, frame 18:139). `CKpiCard`
 * lives in `packages/ui`, is shared with the admin app and stays untouched (plan ruling 9a).
 *
 * Same contract as the original (pre-localised `label`, `value` null renders a muted dash,
 * `loading` shows a skeleton, `dataTest`, the same inner `data-test` anchors) plus two optional
 * pieces the Figma card has: an icon tile (`icon`, an mdi name; a solid fill chosen with
 * `tileTone`, the four "USP" colours of the Figma tiles) and a trailing chevron that appears
 * only when the card links somewhere (`to`). Nothing is rendered for a prop the page does not
 * supply, so a card with neither is the plain tile. Not adopted by any page yet (S3.2 does).
 */

import { computed } from 'vue'
import type { RouteLocationRaw } from 'vue-router'

interface Props {
  /** Pre-localised caption label (e.g. "Creators in roster"). */
  label: string
  /** The KPI value; `null` / `undefined` renders the muted dash. */
  value?: number | null
  /** Show a skeleton in the value slot while the summary loads. */
  loading?: boolean
  /** Root `data-test` anchor. */
  dataTest?: string
  /** An mdi icon name for the 40px tile; no tile when omitted. */
  icon?: string
  /** The tile's solid fill: the Figma USP 1 to 4 colours, as theme keys. */
  tileTone?: 'ai' | 'success' | 'info' | 'warning'
  /** Makes the whole card a link and shows the chevron; omitted = a plain card. */
  to?: RouteLocationRaw
}

const props = withDefaults(defineProps<Props>(), {
  value: null,
  loading: false,
  dataTest: undefined,
  icon: undefined,
  tileTone: 'ai',
  to: undefined,
})

const isPlaceholder = computed(() => props.value === null || props.value === undefined)
const displayValue = computed(() => (isPlaceholder.value ? '—' : String(props.value)))
</script>

<template>
  <component
    :is="to ? 'router-link' : 'div'"
    v-bind="to ? { to } : {}"
    class="agency-kpi"
    :class="{ 'agency-kpi--link': to }"
    :data-test="dataTest"
  >
    <span
      v-if="icon"
      class="agency-kpi__tile"
      :class="`agency-kpi__tile--${tileTone}`"
      data-test="agency-kpi-icon"
    >
      <v-icon :icon="icon" size="20" />
    </span>
    <span class="agency-kpi__text">
      <span class="agency-kpi__label">{{ label }}</span>
      <v-skeleton-loader
        v-if="loading"
        class="agency-kpi__skeleton"
        type="text"
        data-test="kpi-card-skeleton"
      />
      <span
        v-else
        class="agency-kpi__value"
        :class="{ 'agency-kpi__value--placeholder': isPlaceholder }"
        data-test="kpi-card-value"
        >{{ displayValue }}</span
      >
    </span>
    <v-icon
      v-if="to"
      icon="mdi-chevron-right"
      size="16"
      class="agency-kpi__chevron"
      aria-hidden="true"
      data-test="agency-kpi-chevron"
    />
  </component>
</template>

<style scoped>
/* Figma stat card (36:484): surface, 1px border, radius 10, 20px padding, 16 between the parts. */
.agency-kpi {
  display: flex;
  align-items: center;
  gap: var(--space-4, 16px);
  padding: var(--space-5, 20px);
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: var(--radius-xl, 10px);
  background-color: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  text-decoration: none;
}

.agency-kpi--link:hover {
  background-color: rgb(var(--v-theme-surface-bright));
}

.agency-kpi--link:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.agency-kpi__tile {
  flex: none;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-lg, 8px);
  color: rgb(var(--v-theme-text-inverse));
}

/* The four solid tile fills (Figma USP 1 to 4); the same colours as the status fills. */
.agency-kpi__tile--ai {
  background-color: rgb(var(--v-theme-status-ai));
}

.agency-kpi__tile--success {
  background-color: rgb(var(--v-theme-success));
}

.agency-kpi__tile--info {
  background-color: rgb(var(--v-theme-info));
}

.agency-kpi__tile--warning {
  background-color: rgb(var(--v-theme-warning));
}

.agency-kpi__text {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.agency-kpi__label {
  font-size: var(--catalyst-typography-body-sm-size);
  font-weight: var(--catalyst-typography-body-sm-weight);
  line-height: var(--catalyst-typography-body-sm-line-height);
  color: rgb(var(--v-theme-on-surface-variant));
}

.agency-kpi__value {
  font-size: var(--catalyst-typography-heading-2-size);
  font-weight: var(--catalyst-typography-heading-2-weight);
  line-height: var(--catalyst-typography-heading-2-line-height);
  color: rgb(var(--v-theme-on-surface));
}

.agency-kpi__value--placeholder {
  color: rgb(var(--v-theme-on-surface-variant));
}

.agency-kpi__skeleton {
  max-width: 64px;
}

.agency-kpi__chevron {
  flex: none;
  color: rgb(var(--v-theme-text-tertiary));
}
</style>
