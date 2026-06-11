<script setup lang="ts">
/**
 * Auth shell for every admin auth page — rebranded to the Figma
 * "Rebrand" landing (node 359-1253), mirror of
 * `apps/main/src/modules/auth/layouts/AuthLayout.vue`: full-viewport
 * dark surface with vertical grid lines, an aurora glow band, and the
 * Catalyst logo mark. The admin mirror keeps the ThemeToggle (chunk
 * 8.2) and drops the main SPA's partner brand wall — the hero
 * arrangement (sign-in only) renders headline/copy left + card right.
 *
 * Substantive hero copy lives in the sibling AuthHeroPanel.vue; this
 * file stays a structural shell (see
 * tests/unit/architecture/auth-layout-shape.spec.ts).
 */

import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import ThemeToggle from '@/components/ThemeToggle.vue'
import AuthHeroPanel from '@/modules/auth/components/AuthHeroPanel.vue'
import catalystLogo from '@/modules/auth/assets/catalyst-logo.svg'
import { buildLocaleOptions } from './localeOptions'

const { t, locale, availableLocales } = useI18n()
const route = useRoute()

const localeOptions = buildLocaleOptions(availableLocales, t)
const isHero = computed(() => route.name === 'auth.sign-in')
</script>

<template>
  <v-app>
    <v-main>
      <div class="auth-layout" :class="{ 'auth-layout--hero': isHero }">
        <header class="auth-layout__header d-flex align-center justify-space-between mb-6 w-100">
          <h1 class="auth-layout__brand ma-0" data-test="auth-brand">
            <img :src="catalystLogo" alt="" class="auth-layout__logo" />
            <span class="d-sr-only">{{ t('app.title') }}</span>
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

        <div class="auth-layout__content">
          <AuthHeroPanel v-if="isHero" />
          <v-card class="auth-layout__card pa-6 w-100" elevation="2" data-test="auth-card">
            <slot />
          </v-card>
        </div>
      </div>
    </v-main>
  </v-app>
</template>

<style scoped>
.auth-layout {
  position: relative;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  padding: 24px;
  background-color: var(--auth-page-bg);
}

/* Aurora glow band along the top edge, fading out downward. */
.auth-layout::before {
  content: '';
  position: absolute;
  top: 0;
  right: 0;
  left: 0;
  height: 450px;
  background: var(--auth-glow-gradient);
  mask-image: linear-gradient(to bottom, black, transparent);
  -webkit-mask-image: linear-gradient(to bottom, black, transparent);
  pointer-events: none;
}

/* Five-column grid lines, inset 24px: four 1px no-repeat layers +
 * edge borders (a repeating fractional-width tile gets its 1px stripe
 * inconsistently dropped by Chromium's rasterizer). */
.auth-layout::after {
  content: '';
  position: absolute;
  top: 0;
  bottom: 0;
  left: 24px;
  right: 24px;
  background-image:
    linear-gradient(var(--auth-grid-line), var(--auth-grid-line)),
    linear-gradient(var(--auth-grid-line), var(--auth-grid-line)),
    linear-gradient(var(--auth-grid-line), var(--auth-grid-line)),
    linear-gradient(var(--auth-grid-line), var(--auth-grid-line));
  background-size: 1px 100%;
  background-repeat: no-repeat;
  background-position:
    20% 0,
    40% 0,
    60% 0,
    80% 0;
  border-right: 1px solid var(--auth-grid-line);
  border-left: 1px solid var(--auth-grid-line);
  pointer-events: none;
}

.auth-layout > * {
  position: relative;
  z-index: 1;
}

/* Centred-card mode (every page except sign-in). */
.auth-layout:not(.auth-layout--hero) {
  align-items: center;
  justify-content: center;
}

.auth-layout:not(.auth-layout--hero) .auth-layout__header,
.auth-layout:not(.auth-layout--hero) .auth-layout__content {
  width: 100%;
  max-width: 480px;
}

.auth-layout__logo {
  display: block;
  height: 28px;
}

.auth-layout__content {
  display: flex;
  justify-content: center;
}

.auth-layout--hero .auth-layout__content {
  margin-top: var(--space-20);
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-12);
}

/* The Figma card spans the last two grid columns (747px on the
 * 1920px frame); 38.9vw keeps that alignment as the viewport scales. */
.auth-layout--hero .auth-layout__card {
  flex: 0 0 clamp(480px, 38.9vw, 747px);
  max-width: 747px;
}

.auth-layout__card {
  max-width: 480px;
  /* Aurora brand accent (Decision D7, thin-accent-only): 3px gradient
   * line along the card's top edge, consumed via the authored utility
   * var — never a Vuetify theme color (parity invariant 3). Mirror of
   * the main SPA AuthLayout (cross-SPA parity). */
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

/* Mobile: no background columns (mirror of the main SPA layout). */
@media (max-width: 1100px) {
  .auth-layout::after {
    content: none;
  }

  .auth-layout--hero .auth-layout__content {
    flex-direction: column;
    align-items: stretch;
    gap: var(--space-6);
  }

  .auth-layout--hero .auth-layout__card {
    flex: initial;
    align-self: center;
  }
}
</style>
