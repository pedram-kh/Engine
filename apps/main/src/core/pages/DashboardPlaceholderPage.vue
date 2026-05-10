<script setup lang="ts">
/**
 * Placeholder mounted at `/` and `/settings` until chunk 7 ships the
 * real dashboard / settings surface. Exists only so the chunk-6.5
 * router has a valid component target — the route guards (requireAuth +
 * requireMfaEnrolled) are the unit under test, not this page.
 */
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <main class="d-flex flex-column align-center justify-center" style="min-height: 100vh">
    <h1 class="text-h4">{{ t('app.title') }}</h1>
    <p class="text-body-1 text-medium-emphasis">{{ t('app.dashboardPlaceholder') }}</p>
  </main>
</template>
