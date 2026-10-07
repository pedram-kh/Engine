<script setup lang="ts">
/**
 * AvailabilityPage — the creator's availability calendar surface
 * (Sprint 5 Chunk B). Route `/creator/availability`, `creator` layout,
 * `requireAuth` guard.
 *
 * Thin page shell: a localized header + the `AvailabilityCalendar` (which
 * owns the month grid, fetch, tz rendering, and the create/edit dialog).
 * Inside the creator app (AH-100) the calendar is `AvailabilityCalendarNative`
 * (Figma frame 12) over the same data, and the header drops the aurora rule.
 */

import { useI18n } from 'vue-i18n'

import { isNativePlatform } from '@/core/platform/isNativePlatform'

import AvailabilityCalendar from '../components/AvailabilityCalendar.vue'
import AvailabilityCalendarNative from '../components/AvailabilityCalendarNative.vue'

const { t } = useI18n()
const isNative = isNativePlatform()
</script>

<template>
  <section class="availability-page" data-testid="availability-page">
    <header
      class="availability-page__header"
      :class="{ 'availability-page__header--native': isNative }"
    >
      <h1 class="text-h4">{{ t('availability.title') }}</h1>
      <p class="text-body-1 text-medium-emphasis">{{ t('availability.subtitle') }}</p>
    </header>

    <AvailabilityCalendarNative v-if="isNative" />
    <AvailabilityCalendar v-else />
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

/* AH-100 — frame 12's header: display title, secondary subtitle, no rule. */
.availability-page__header--native {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding-bottom: 0;
  border-bottom: 0;
  border-image: none;
}

.availability-page__header--native h1 {
  font: var(--native-type-display) !important;
  letter-spacing: normal !important;
  color: var(--native-text-primary);
}

.availability-page__header--native p {
  margin: 0;
  font: var(--native-type-body) !important;
  color: var(--native-text-secondary) !important;
}
</style>
