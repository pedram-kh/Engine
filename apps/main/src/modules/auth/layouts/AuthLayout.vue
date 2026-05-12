<script setup lang="ts">
/**
 * Centred-card layout for every auth page (sign-in, sign-up, verify
 * email, reset password, 2FA). The layout owns the brand mark, the
 * locale switcher, the theme toggle (chunk 8.2), and a slot for the
 * page content.
 *
 * Brand mark is text-only (`Catalyst Engine` from `app.title`) — chunk-6
 * out-of-scope note keeps the actual logo asset for chunk 7.
 *
 * Locale switcher writes directly to `i18n.global.locale` via the
 * `useI18n` composable. The choice is purely client-side for now;
 * persisted user preference lands with the settings page in chunk 7.
 *
 * Theme toggle (chunk 8.2): the `<ThemeToggle />` component consumes
 * `useThemePreference()` and emits user intent via
 * `setPreference()`. This layout itself holds NO theme state — see
 * the toggle component's docblock for the SOT-boundary contract.
 * The same component is also mounted in `App.vue`'s app-layout
 * branch so authenticated users on the placeholder dashboard /
 * settings page can toggle too. Sprint 2's user-menu work will
 * consume the same component when the real nav shell lands.
 */

import { useI18n } from 'vue-i18n'

import ThemeToggle from '@/components/ThemeToggle.vue'

import { buildLocaleOptions } from './localeOptions'

const { t, locale, availableLocales } = useI18n()

const localeOptions = buildLocaleOptions(availableLocales, t)
</script>

<template>
  <v-app>
    <v-main>
      <div class="auth-layout d-flex flex-column align-center justify-center pa-6">
        <header class="auth-layout__header d-flex align-center justify-space-between mb-6 w-100">
          <h1 class="text-h6 ma-0" data-test="auth-brand">
            {{ t('app.title') }}
          </h1>
          <div class="d-flex align-center ga-2">
            <ThemeToggle />
            <v-select
              v-model="locale"
              :items="localeOptions"
              :label="t('app.locale.switcher')"
              item-title="title"
              item-value="value"
              density="compact"
              variant="outlined"
              hide-details
              class="auth-layout__locale"
              data-test="auth-locale-switcher"
            />
          </div>
        </header>
        <v-card class="auth-layout__card pa-6 w-100" elevation="2" data-test="auth-card">
          <slot />
        </v-card>
      </div>
    </v-main>
  </v-app>
</template>

<style scoped>
.auth-layout {
  min-height: 100vh;
  background-color: rgb(var(--v-theme-background));
}

.auth-layout__header {
  max-width: 480px;
}

.auth-layout__card {
  max-width: 480px;
}

.auth-layout__locale {
  max-width: 160px;
}
</style>
