<script setup lang="ts">
/**
 * AvailabilityPage — the creator's availability calendar surface
 * (Sprint 5 Chunk B). Route `/creator/availability`, `creator` layout,
 * `requireAuth` guard.
 *
 * Thin page shell: a localized header + the `AvailabilityCalendar` (which
 * owns the month grid, fetch, tz rendering, and the create/edit dialog).
 */

import { useI18n } from 'vue-i18n'

import AvailabilityCalendar from '../components/AvailabilityCalendar.vue'

const { t } = useI18n()
</script>

<template>
  <section class="availability-page" data-testid="availability-page">
    <header class="availability-page__header">
      <h1 class="text-h4">{{ t('availability.title') }}</h1>
      <p class="text-body-1 text-medium-emphasis">{{ t('availability.subtitle') }}</p>
    </header>

    <AvailabilityCalendar />
  </section>
</template>

<style scoped>
.availability-page {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

/* Aurora brand accent — matches the creator dashboard header divider
   (Sprint 3.5 Chunk 4 — Decision D7, thin-accent-only; consumes the
   authored utility var, never a Vuetify theme.color). */
.availability-page__header {
  padding-bottom: 16px;
  border-bottom: 2px solid transparent;
  border-image: var(--brand-aurora-gradient) 1;
}
</style>
