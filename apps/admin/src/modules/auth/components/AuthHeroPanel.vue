<script setup lang="ts">
/**
 * Hero copy panel for the rebrand sign-in landing (Figma "Rebrand"
 * node 359-1253) — admin mirror of the main SPA component. The admin
 * console has no partner logo wall, so the case-studies footnote is
 * intentionally omitted; only the headline + body render here. All
 * strings come from the `auth.ui.hero.*` i18n bundle.
 */

import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <div class="auth-hero" data-test="auth-hero">
    <p class="auth-hero__headline">{{ t('auth.ui.hero.headline') }}</p>
    <p class="auth-hero__body">{{ t('auth.ui.hero.body') }}</p>
  </div>
</template>

<style scoped>
.auth-hero {
  max-width: 1100px;
}

.auth-hero__headline {
  margin: 0 0 var(--space-12);
  font-family: var(--auth-display-font);
  /* 102px on the 1920px Figma frame, fluid below it. */
  font-size: clamp(44px, 5.3vw, 102px);
  font-weight: 400;
  line-height: 1;
  text-transform: uppercase;
  color: var(--auth-page-fg);
}

.auth-hero__body {
  margin: 0;
  max-width: 867px;
  font-size: clamp(16px, 1.25vw, 24px);
  line-height: 1.3;
  color: var(--auth-page-fg-muted);
}
</style>
