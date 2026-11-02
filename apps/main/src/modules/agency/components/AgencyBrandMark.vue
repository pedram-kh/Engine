<script setup lang="ts">
/**
 * AH-114 (S3.1) — a brand's mark in a table or a card: a 24px tile (radius 6) holding the
 * brand's initial (Chip type: 10.5 / 14 / 700, +0.4px, uppercase), on a 15 % tint of one of
 * four theme colours with the letter in that colour's text value (Figma list rows, node
 * 14:148). A brand has no colour of its own in the data, so the colour is chosen from the
 * brand's id by a fixed hash (`status/agencyBrandTone.ts`): the same brand is always the same colour,
 * on every page, in both themes.
 *
 * Nothing is rendered for an empty name.
 */

import { computed } from 'vue'

import { brandMarkTone } from '../status/agencyBrandTone'

const props = defineProps<{
  /** The brand's name; its first letter is the mark. */
  name: string
  /** Stable id (or any stable string) that picks the colour; falls back to the name. */
  seed?: string | null
}>()

const initial = computed(() => Array.from(props.name.trim())[0]?.toUpperCase() ?? '')
const tone = computed(() => brandMarkTone(props.seed ?? props.name))
</script>

<template>
  <span
    v-if="initial !== ''"
    class="agency-brand-mark"
    :class="`agency-brand-mark--${tone}`"
    :data-tone="tone"
    aria-hidden="true"
    data-test="agency-brand-mark"
  >
    <span class="agency-brand-mark__letter">{{ initial }}</span>
  </span>
</template>

<style scoped>
.agency-brand-mark {
  --mark-fill: var(--v-theme-info);
  --mark-text: var(--v-theme-info-text);

  position: relative;
  display: inline-flex;
  flex: none;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 6px;
  color: rgb(var(--mark-text));
  isolation: isolate;
}

/* The 15 % tint is its own layer so the letter keeps full colour. */
.agency-brand-mark::before {
  content: '';
  position: absolute;
  inset: 0;
  z-index: -1;
  border-radius: inherit;
  background-color: rgb(var(--mark-fill));
  opacity: 0.15;
}

.agency-brand-mark__letter {
  font-size: 10.5px;
  font-weight: 700;
  line-height: 14px;
  letter-spacing: 0.4px;
  text-transform: uppercase;
}

.agency-brand-mark--success {
  --mark-fill: var(--v-theme-success);
  --mark-text: var(--v-theme-success-text);
}

.agency-brand-mark--warning {
  --mark-fill: var(--v-theme-warning);
  --mark-text: var(--v-theme-warning-text);
}

.agency-brand-mark--ai {
  --mark-fill: var(--v-theme-status-ai);
  --mark-text: var(--v-theme-ai-text);
}
</style>
