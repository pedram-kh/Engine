<script setup lang="ts">
/**
 * ThemeToggle — admin SPA binary theme toggle.
 *
 * Renders a `v-btn-toggle` with two icon buttons: light and dark. The
 * currently-selected button reflects the user's resolved preference
 * (the stored value if explicit, the SPA default if not).
 *
 * Composable boundary (chunk 8.2 review priority #3):
 *   The component holds NO theme state of its own — every read goes
 *   through `useThemePreference().preference` and every write goes
 *   through `useThemePreference().setPreference()`. The architecture
 *   test `tests/unit/architecture/use-theme-is-sot.spec.ts` forbids both
 *   theme-key storage operations and OS colour-scheme media-query reads
 *   outside the preference composable, so this component cannot
 *   accidentally bypass the SOT.
 *
 * Binary toggle (Sprint 3.5 Chunk 1 — Q `tri_state_disposition`):
 *   Chunk 8.2 shipped a tri-state toggle (light / dark / system). The
 *   `system` affordance was dropped in Sprint 3.5: the Engine C v2 brand
 *   is dark-first and the toggle is a deliberate binary choice. The two
 *   values map 1:1 to the persistence layer's storage values.
 *
 * `data-test` attributes follow the chunk-7.1 hotfix #3 lesson:
 *   leaf elements only, no parent fall-through risk. The
 *   `theme-toggle` selector is on the root `<v-btn-toggle>` and each
 *   button carries its own `theme-toggle-{light,dark}`. The parent
 *   (AuthLayout / App.vue) does NOT pass a `data-test` through this
 *   component.
 *
 * Per-SPA mirror (chunk 7.2 D2 standing standard):
 *   The main SPA mirrors this component verbatim at
 *   `apps/main/src/components/ThemeToggle.vue`. Both files MUST
 *   stay in structural lockstep. Differences are limited to the
 *   SPA-name swap in this comment header.
 */

import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { useThemePreference, type ThemePreference } from '@/composables/useThemePreference'

const { t } = useI18n()

const preference = useThemePreference()

const selected = computed<ThemePreference>({
  get: () => preference.preference.value,
  set: (next) => {
    preference.setPreference(next)
  },
})
</script>

<template>
  <v-btn-toggle
    v-model="selected"
    mandatory
    density="compact"
    variant="outlined"
    color="primary"
    data-test="theme-toggle"
    :aria-label="t('app.theme.toggle.label')"
  >
    <v-btn
      value="light"
      icon="mdi-weather-sunny"
      :aria-label="t('app.theme.toggle.light')"
      :title="t('app.theme.toggle.light')"
      data-test="theme-toggle-light"
    />
    <v-btn
      value="dark"
      icon="mdi-weather-night"
      :aria-label="t('app.theme.toggle.dark')"
      :title="t('app.theme.toggle.dark')"
      data-test="theme-toggle-dark"
    />
  </v-btn-toggle>
</template>
