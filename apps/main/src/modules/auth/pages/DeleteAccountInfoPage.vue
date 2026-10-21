<script setup lang="ts">
/**
 * DeleteAccountInfoPage — the public `/delete-account` page (AH-104b-del B5).
 *
 * Store listings ask for a URL that explains how a person deletes their account and what
 * happens to their data. This is it: public, no guards, signed-out friendly, and outside the
 * `/creator/` prefix on purpose so a universal link never hands it to the app.
 *
 * It says the same things as the in-app Security section (it reads the same
 * `creator.ui.security.delete.*` copy, so the two cannot drift): what is deleted, what stays,
 * the 14 days. It adds the two ways in: the app, and the support address for someone who
 * cannot sign in. The address comes from `core/config/support` and nowhere else.
 */

import { useI18n } from 'vue-i18n'

import { SUPPORT_EMAIL } from '@/core/config/support'

const { t } = useI18n()
</script>

<template>
  <section data-test="delete-account-info">
    <h2 class="text-h5 mb-2" data-test="delete-account-info-title">
      {{ t('auth.ui.deleteAccountPage.title') }}
    </h2>
    <p class="mb-6" data-test="delete-account-info-intro">
      {{ t('auth.ui.deleteAccountPage.intro') }}
    </p>

    <h3 class="text-subtitle-1 font-weight-bold mb-1">
      {{ t('auth.ui.deleteAccountPage.inAppTitle') }}
    </h3>
    <p class="mb-6" data-test="delete-account-info-in-app">
      {{ t('auth.ui.deleteAccountPage.inApp') }}
    </p>

    <h3 class="text-subtitle-1 font-weight-bold mb-1">
      {{ t('auth.ui.deleteAccountPage.supportTitle') }}
    </h3>
    <p class="mb-6" data-test="delete-account-info-support">
      {{ t('auth.ui.deleteAccountPage.support', { email: SUPPORT_EMAIL }) }}
      <a :href="`mailto:${SUPPORT_EMAIL}`" data-test="delete-account-info-mailto">{{
        SUPPORT_EMAIL
      }}</a>
    </p>

    <h3 class="text-subtitle-1 font-weight-bold mb-1">
      {{ t('creator.ui.security.delete.erasedTitle') }}
    </h3>
    <p class="mb-6" data-test="delete-account-info-erased">
      {{ t('creator.ui.security.delete.erased') }}
    </p>

    <h3 class="text-subtitle-1 font-weight-bold mb-1">
      {{ t('creator.ui.security.delete.keptTitle') }}
    </h3>
    <p class="mb-6" data-test="delete-account-info-kept">
      {{ t('creator.ui.security.delete.kept') }}
    </p>

    <h3 class="text-subtitle-1 font-weight-bold mb-1">
      {{ t('creator.ui.security.delete.graceTitle') }}
    </h3>
    <p data-test="delete-account-info-grace">
      {{ t('creator.ui.security.delete.grace') }}
    </p>
  </section>
</template>
