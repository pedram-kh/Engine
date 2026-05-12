<script setup lang="ts">
/**
 * ThemeToggle — admin SPA tri-state theme toggle.
 *
 * Renders a `v-btn-toggle` with three icon buttons: light, dark, and
 * system. The currently-selected button reflects the user's resolved
 * preference (the stored value if explicit, the SPA default if not).
 *
 * Composable boundary (chunk 8.2 review priority #3):
 *   The component holds NO theme state of its own — every read goes
 *   through `useThemePreference().preference` and every write goes
 *   through `useThemePreference().setPreference()`. The architecture
 *   test `tests/unit/architecture/use-theme-is-sot.spec.ts` (extended
 *   in chunk 8.2) forbids both theme-key storage operations and OS
 *   colour-scheme media-query reads outside the preference composable,
 *   so this component cannot accidentally bypass the SOT.
 *
 * Q3 design answer (chunk 8.2): tri-state vs binary.
 *   Tri-state was picked because Q1's Option C (asymmetric defaults
 *   with layered fallback) makes the user's `'system'` choice
 *   meaningful — it engages `prefers-color-scheme` as a fallback. A
 *   binary toggle would erase that affordance. The three values map
 *   1:1 to the persistence layer's storage values.
 *
 * `data-test` attributes follow the chunk-7.1 hotfix #3 lesson:
 *   leaf elements only, no parent fall-through risk. The
 *   `theme-toggle` selector is on the root `<v-btn-toggle>` and each
 *   button carries its own `theme-toggle-{light,dark,system}`. The
 *   parent (AuthLayout / App.vue) does NOT pass a `data-test`
 *   through this component — the chunk-7.1 architecture test would
 *   not catch it (no architecture test exists yet for that bug
 *   class), but the convention is enforced by review.
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
    <v-btn
      value="system"
      icon="mdi-monitor"
      :aria-label="t('app.theme.toggle.system')"
      :title="t('app.theme.toggle.system')"
      data-test="theme-toggle-system"
    />
  </v-btn-toggle>
</template>
