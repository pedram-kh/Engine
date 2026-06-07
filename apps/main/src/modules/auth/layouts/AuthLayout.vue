<script setup lang="ts">
/**
 * Centred-card layout for every auth page (sign-in, sign-up, verify
 * email, reset password, 2FA). The layout owns the brand mark, the
 * locale switcher, and a slot for the page content.
 *
 * Chunk 2: ThemeToggle removed from this layout's header. It now lives
 * exclusively in AgencyLayout's user menu for authenticated users.
 * Sprint 3.5 Chunk 1 dropped the `prefers-color-scheme` system default
 * (binary light/dark, dark-first), so unauthenticated auth pages render
 * the default theme (dark) with no in-page toggle; the explicit toggle
 * is an authenticated-user affordance reached after sign-in.
 */

import { useI18n } from 'vue-i18n'

import ImpersonationBanner from '@/modules/impersonation/components/ImpersonationBanner.vue'
import { buildLocaleOptions } from './localeOptions'

const { t, locale, availableLocales } = useI18n()

const localeOptions = buildLocaleOptions(availableLocales, t)
</script>

<template>
  <v-app>
    <ImpersonationBanner />

    <v-main>
      <div class="auth-layout d-flex flex-column align-center justify-center pa-6">
        <header class="auth-layout__header d-flex align-center justify-space-between mb-6 w-100">
          <h1 class="text-h6 ma-0" data-test="auth-brand">
            {{ t('app.title') }}
          </h1>
          <div class="d-flex align-center ga-2">
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
  /* Aurora brand accent (Sprint 3.5 Chunk 4 — Decision D7, thin-accent-only):
   * a 3px aurora gradient line along the card's top edge. The primary brand
   * moment for unauthenticated users. v-card's default overflow:hidden clips
   * it to the rounded top corners. Consumes the authored utility var, never a
   * Vuetify theme.color (parity invariant 3 stays green). */
  position: relative;
}

.auth-layout__card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: var(--brand-aurora-gradient);
}

.auth-layout__locale {
  max-width: 160px;
}
</style>
