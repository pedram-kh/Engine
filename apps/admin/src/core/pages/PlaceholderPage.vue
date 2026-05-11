<script setup lang="ts">
/**
 * Sub-chunk 7.4 placeholder mounted at every admin route until
 * sub-chunk 7.5 substitutes the substantive auth + dashboard pages.
 * Exists only so the route table has a valid component target — the
 * router instance + the guards are the units under test in 7.4, not
 * this page.
 *
 * Sub-chunk 7.5 will:
 *   - Replace `auth.sign-in` with `SignInPage.vue`.
 *   - Replace `auth.2fa.enable` with `EnableTotpPage.vue`.
 *   - Replace `auth.2fa.verify` with `VerifyTotpPage.vue`.
 *   - Replace `auth.2fa.disable` with `DisableTotpPage.vue`.
 *   - Replace `error.auth-bootstrap` with `AuthBootstrapErrorPage.vue`.
 *   - Leave `app.dashboard` and `app.settings` as a placeholder until
 *     the substantive admin console UI lands in a later chunk
 *     (mirroring main's `core/pages/DashboardPlaceholderPage.vue`).
 *
 * Renders the same three i18n strings the pre-7.4 admin `App.vue`
 * rendered (`app.title`, `app.subtitle`, `app.sprint0Notice`) so the
 * visible Sprint-0 scaffolding is preserved while the routing
 * infrastructure lands underneath.
 *
 * Excluded from runtime coverage (chunk-6.4 "exclusion + guard"
 * pattern; mirror of main's `core/pages/DashboardPlaceholderPage.vue`
 * + `AuthLayout.vue` exclusions) — pure structural SFC, no
 * user-defined methods, will be replaced wholesale by 7.5.
 */
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <v-container class="d-flex flex-column align-center justify-center" style="min-height: 100vh">
    <h1 class="text-h4 mb-2">{{ t('app.title') }}</h1>
    <p class="text-body-1 text-medium-emphasis">{{ t('app.subtitle') }}</p>
    <p class="text-caption mt-8 text-disabled">{{ t('app.sprint0Notice') }}</p>
  </v-container>
</template>
