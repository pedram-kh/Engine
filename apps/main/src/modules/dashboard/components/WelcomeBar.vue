<script setup lang="ts">
/**
 * WelcomeBar — the agency workspace-home greeting (§11 "Welcome bar with
 * user name and current date"; Sprint 4 Chunk 1, 1b).
 *
 * Content: the authenticated user's name (from `useAuthStore`) + today's
 * date, formatted locale-aware via `Intl.DateTimeFormat` keyed to the active
 * vue-i18n locale (no heavy date dependency). A static greeting word is fine
 * (D-c1 kickoff); the required content is name + date.
 *
 * Aurora accent (D-c1-9 / D7, thin-accent-only): a 2px aurora rule along the
 * bottom edge, mirroring `CreatorDashboardPage`'s header rule. Consumes the
 * authored `var(--brand-aurora-gradient)` utility — NEVER a Vuetify
 * `theme.color` (parity invariant 3). Regression-locked as an asserted
 * surface in `aurora-surfacing.spec.ts`.
 */

import { storeToRefs } from 'pinia'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

const { t, locale } = useI18n()
const authStore = useAuthStore()
const { user } = storeToRefs(authStore)

const userName = computed(() => user.value?.attributes.name ?? '')

const today = computed(() =>
  new Intl.DateTimeFormat(locale.value, {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  }).format(new Date()),
)
</script>

<template>
  <header class="welcome-bar" data-test="welcome-bar">
    <h1 class="text-h5 ma-0" data-test="welcome-bar-greeting">
      {{
        userName
          ? t('dashboard.welcome.greetingNamed', { name: userName })
          : t('dashboard.welcome.greeting')
      }}
    </h1>
    <p class="text-body-2 text-medium-emphasis ma-0 mt-1" data-test="welcome-bar-date">
      {{ today }}
    </p>
  </header>
</template>

<style scoped>
.welcome-bar {
  padding-bottom: 16px;
  border-bottom: 2px solid transparent;
  border-image: var(--brand-aurora-gradient) 1;
}
</style>
