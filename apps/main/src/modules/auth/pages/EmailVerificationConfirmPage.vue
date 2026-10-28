<script setup lang="ts">
/**
 * Landing page from the email-verification link. Reads `token` from
 * the route query, calls `verifyEmail()`, then renders either a
 * success or an error state. The user lands here from an out-of-band
 * email click; no form to submit, just a status banner + "back to
 * sign in" link.
 */

import { ApiError } from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { resolveErrorMessage } from '@/modules/auth/composables/useErrorMessage'

const ALREADY_VERIFIED_CODE = 'auth.email.already_verified'

const { t, te } = useI18n()
const route = useRoute()
const store = useAuthStore()
const { isVerifyingEmail } = storeToRefs(store)

const verified = ref(false)
/** A re-click of a link that already worked: the account IS verified, the link is just spent. */
const alreadyVerified = ref(false)
/** The address is verified, whether by this visit or an earlier one. */
const isVerifiedNow = computed(() => verified.value || alreadyVerified.value)
const errorKey = ref<string | null>(null)
const errorValues = ref<Record<string, string | number>>({})

onMounted(async () => {
  const token = typeof route.query.token === 'string' ? route.query.token : ''
  if (token.length === 0) {
    errorKey.value = 'auth.ui.errors.missing_token'
    return
  }
  try {
    await store.verifyEmail({ token })
    verified.value = true
  } catch (err) {
    alreadyVerified.value = err instanceof ApiError && err.code === ALREADY_VERIFIED_CODE
    const resolved = resolveErrorMessage(err, (k) => te(k))
    errorKey.value = resolved.key
    errorValues.value = resolved.values
  }
})
</script>

<template>
  <section data-test="email-verification-confirm-page">
    <h2 class="text-h5 mb-4" data-test="email-verification-confirm-heading">
      {{ t('auth.ui.headings.verify_email_confirm') }}
    </h2>

    <div v-if="isVerifyingEmail" data-test="email-verification-confirm-loading">
      <v-progress-circular indeterminate color="primary" />
      <p class="text-body-2 mt-2">{{ t('auth.ui.loading.verifying') }}</p>
    </div>

    <v-alert
      v-if="verified"
      type="success"
      variant="tonal"
      class="mb-4"
      data-test="email-verification-confirm-success"
    >
      {{ t('auth.ui.banners.email_verified') }}
    </v-alert>

    <!-- AH-104d Q3(a)/C3: this page opens in the phone's browser, where the app
         cannot be detected, so the line is worded to be true for everyone and
         is shown to every visitor whose address is verified: on success, and on
         the "already verified" answer to a link used twice (W14, 2026-10-28). It
         is never shown for an expired or invalid link. -->
    <p
      v-if="isVerifiedNow"
      class="text-body-2 mb-2"
      data-test="email-verification-confirm-app-hint"
    >
      {{ t('auth.ui.banners.email_verified_app') }}
    </p>

    <div
      role="alert"
      aria-live="polite"
      class="text-error text-body-2 mb-2"
      data-test="email-verification-confirm-error"
    >
      <template v-if="errorKey !== null">{{ t(errorKey, errorValues) }}</template>
    </div>

    <div class="mt-4 text-body-2">
      <router-link :to="{ name: 'auth.sign-in' }" data-test="email-verification-confirm-back">
        {{ t('auth.ui.actions.back_to_sign_in') }}
      </router-link>
    </div>
  </section>
</template>
