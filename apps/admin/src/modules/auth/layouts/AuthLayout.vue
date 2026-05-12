<script setup lang="ts">
/**
 * Centred-card layout for every admin auth page (sign-in, 2FA enable,
 * 2FA verify, 2FA disable, and the bootstrap-error route). The layout
 * owns the brand mark, the locale switcher, the theme toggle (chunk
 * 8.2), and a slot for the page content. Mirror of
 * `apps/main/src/modules/auth/layouts/AuthLayout.vue` (chunk 6.6)
 * verbatim, with admin's `app.title` rendered in the brand mark.
 *
 * Coverage carve-out: excluded from the function-coverage gate (v8 cannot
 * anchor function coverage on a `<script setup>` SFC with no user-defined
 * functions). Substantive logic must live in sibling `*.ts` helpers (see
 * `localeOptions.ts`); the architecture test
 * `tests/unit/architecture/auth-layout-shape.spec.ts` pins the size +
 * no-multi-statement-arrow contract.
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
