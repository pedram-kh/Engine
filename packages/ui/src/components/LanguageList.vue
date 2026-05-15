<script setup lang="ts">
/**
 * LanguageList — render the creator's primary language + the
 * comma-separated list of secondary languages.
 *
 * Sprint 3 Chunk 3 sub-step 5 (Decision C1: display-shared, form-main).
 *
 * The consumer page is responsible for resolving language codes
 * (e.g. `en`, `pt`) to localized display labels and passing the
 * result via the `:primaryLabel` and `:secondaryLabels` props.
 *
 * The primary language renders with stronger emphasis so the
 * "main" language is visually distinguishable from the
 * supporting ones. Empty bootstrap shapes render an em-dash.
 */

import { computed } from 'vue'

interface Props {
  /** Pre-localized primary language label (e.g. "English"). */
  primaryLabel: string | null
  /** Pre-localized secondary language labels in display order. */
  secondaryLabels: ReadonlyArray<string>
  /** Optional fallback when both primary and secondary are absent. */
  emptyLabel?: string
}

const props = withDefaults(defineProps<Props>(), {
  emptyLabel: '—',
})

const secondaryText = computed(() => props.secondaryLabels.join(', '))
const hasPrimary = computed(() => props.primaryLabel !== null && props.primaryLabel.length > 0)
const hasSecondary = computed(() => props.secondaryLabels.length > 0)
const hasAny = computed(() => hasPrimary.value || hasSecondary.value)
</script>

<template>
  <span v-if="hasAny" class="language-list" data-testid="language-list">
    <span v-if="hasPrimary" class="language-list__primary" data-testid="language-list-primary">
      {{ props.primaryLabel }}
    </span>
    <span
      v-if="hasSecondary"
      class="language-list__secondary"
      data-testid="language-list-secondary"
    >
      <span v-if="hasPrimary" aria-hidden="true">·</span>
      {{ secondaryText }}
    </span>
  </span>
  <span v-else class="language-list language-list--empty" data-testid="language-list-empty">
    {{ props.emptyLabel }}
  </span>
</template>

<style scoped>
.language-list {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 0.9375rem;
}

.language-list__primary {
  font-weight: 500;
}

.language-list__secondary {
  color: rgb(var(--v-theme-on-surface-variant));
}

.language-list--empty {
  color: rgb(var(--v-theme-on-surface-variant));
}
</style>
