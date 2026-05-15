<script setup lang="ts">
/**
 * CategoryChips — render an array of pre-localized category labels
 * as Vuetify chips.
 *
 * Sprint 3 Chunk 3 sub-step 5 (Decision C1: display-shared, form-main).
 *
 * The consumer page is responsible for resolving the category
 * keys (e.g. `fashion`, `beauty`) to localized labels via i18n
 * and passing the result in via `:labels`. Keeps this package
 * dependency-free of `vue-i18n`.
 *
 * Empty state renders a low-emphasis em-dash so empty bootstrap
 * shapes don't render as an invisible region — useful in admin
 * detail views where "category not yet picked" is meaningful.
 */

interface Props {
  /** Pre-localized labels in display order. */
  labels: ReadonlyArray<string>
  /** Optional aria-label fallback when labels array is empty. */
  emptyLabel?: string
}

const props = withDefaults(defineProps<Props>(), {
  emptyLabel: '—',
})
</script>

<template>
  <div class="category-chips" data-testid="category-chips">
    <template v-if="props.labels.length > 0">
      <v-chip
        v-for="label in props.labels"
        :key="label"
        size="small"
        variant="tonal"
        :data-testid="`category-chip-${label}`"
      >
        {{ label }}
      </v-chip>
    </template>
    <span v-else class="category-chips__empty" data-testid="category-chips-empty">
      {{ props.emptyLabel }}
    </span>
  </div>
</template>

<style scoped>
.category-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.category-chips__empty {
  color: rgb(var(--v-theme-on-surface-variant));
}
</style>
