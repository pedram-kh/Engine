<script setup lang="ts">
/**
 * AH-114 (S3.1) — the agency page header (rule R3): the title (Heading/H1, 26 / 32 / 500)
 * with a one-line secondary line under it on the left, the page's actions on the right.
 * Used by the designed pages (Campaigns, Discover) and adopted by the inferred ones in L4.
 *
 * The subtitle renders only when the page has one (no empty line, no placeholder): a page
 * whose number is not loaded yet passes nothing. Actions come in through the `actions`
 * slot; a page with none gets no empty column.
 *
 * `titleTest` and `subtitleTest` carry the page's own `data-test` anchors, so a page
 * swapping its heading for this component keeps its specs.
 */

defineProps<{
  title: string
  subtitle?: string | null
  titleTest?: string
  subtitleTest?: string
}>()
</script>

<template>
  <header class="agency-page-header" data-test="agency-page-header">
    <div class="agency-page-header__text">
      <h1 class="agency-page-header__title" :data-test="titleTest">{{ title }}</h1>
      <p
        v-if="subtitle !== undefined && subtitle !== null && subtitle !== ''"
        class="agency-page-header__subtitle"
        :data-test="subtitleTest"
      >
        {{ subtitle }}
      </p>
    </div>
    <div v-if="$slots.actions" class="agency-page-header__actions">
      <slot name="actions" />
    </div>
  </header>
</template>

<style scoped>
.agency-page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4, 16px);
}

.agency-page-header__text {
  display: flex;
  flex-direction: column;
  gap: var(--space-1, 4px);
  min-width: 0;
}

.agency-page-header__title {
  margin: 0;
  font-size: var(--catalyst-typography-heading-1-size);
  font-weight: var(--catalyst-typography-heading-1-weight);
  line-height: var(--catalyst-typography-heading-1-line-height);
  letter-spacing: normal;
  color: rgb(var(--v-theme-on-surface));
}

.agency-page-header__subtitle {
  margin: 0;
  font-size: var(--catalyst-typography-body-sm-size);
  font-weight: var(--catalyst-typography-body-sm-weight);
  line-height: var(--catalyst-typography-body-sm-line-height);
  color: rgb(var(--v-theme-on-surface-variant));
}

.agency-page-header__actions {
  display: flex;
  flex: none;
  align-items: center;
  gap: var(--space-3, 12px);
}
</style>
