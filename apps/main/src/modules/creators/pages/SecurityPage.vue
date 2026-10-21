<script setup lang="ts">
/**
 * SecurityPage — `/creator/security` (AH-104b-del B4).
 *
 * Today the page holds one section, "Delete account": what is deleted, what stays, the 14
 * days and how to cancel, then the button that opens {@link DeleteAccountDialog}. The route
 * is behind `requireAccountDeletionEnabled`, so it only renders while the server says the
 * feature is on for this creator.
 *
 * The consequences copy is the same text the public `/delete-account` page shows; both read
 * the `creator.ui.security.delete.*` keys, so the two never drift apart.
 */

import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { isNativePlatform } from '@/core/platform/isNativePlatform'

import DeleteAccountDialog from '../components/DeleteAccountDialog.vue'

const { t } = useI18n()
const isNative = isNativePlatform()
const dialogOpen = ref(false)
</script>

<template>
  <section
    class="security-page"
    :class="{ 'security-page--native': isNative }"
    data-test="security-page"
  >
    <h1 class="text-h5 mb-4" data-test="security-title">{{ t('creator.ui.security.title') }}</h1>

    <v-card variant="outlined" data-test="security-delete-card">
      <v-card-title data-test="security-delete-title">
        {{ t('creator.ui.security.delete.title') }}
      </v-card-title>
      <v-card-text>
        <p class="mb-4" data-test="security-delete-intro">
          {{ t('creator.ui.security.delete.intro') }}
        </p>

        <h2 class="text-subtitle-1 font-weight-bold mb-1">
          {{ t('creator.ui.security.delete.erasedTitle') }}
        </h2>
        <p class="mb-4" data-test="security-delete-erased">
          {{ t('creator.ui.security.delete.erased') }}
        </p>

        <h2 class="text-subtitle-1 font-weight-bold mb-1">
          {{ t('creator.ui.security.delete.keptTitle') }}
        </h2>
        <p class="mb-4" data-test="security-delete-kept">
          {{ t('creator.ui.security.delete.kept') }}
        </p>

        <h2 class="text-subtitle-1 font-weight-bold mb-1">
          {{ t('creator.ui.security.delete.graceTitle') }}
        </h2>
        <p class="mb-4" data-test="security-delete-grace">
          {{ t('creator.ui.security.delete.grace') }}
        </p>

        <v-btn
          color="error"
          variant="flat"
          :block="isNative"
          data-test="security-delete-open"
          @click="dialogOpen = true"
        >
          {{ t('creator.ui.security.delete.button') }}
        </v-btn>
      </v-card-text>
    </v-card>

    <DeleteAccountDialog v-model="dialogOpen" />
  </section>
</template>
