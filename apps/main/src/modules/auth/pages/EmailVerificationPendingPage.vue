<script setup lang="ts">
/**
 * "Check your email" page after sign-up. Reads the email from the
 * route query and shows a rate-limited resend button. The api-client
 * surfaces the rate-limit error code, which renders inline via the
 * shared error-resolver.
 *
 * AH-104d, inside the creator app only: the person has left for their mail app
 * and a browser, so the page offers a primary "I've verified, sign in" button
 * (two states, see `verifiedSignIn`) and, when they hold a session, a text link
 * to Security so an account that never got verified can still be deleted.
 */

import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'

import { isNativePlatform } from '@/core/platform/isNativePlatform'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { resolveErrorMessage } from '@/modules/auth/composables/useErrorMessage'

const { t, te } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useAuthStore()
const { isResendingVerification } = storeToRefs(store)

const isNative = isNativePlatform()

// Primary source is the route query (set by the sign-up flow and the
// unverified-account bounces). Fall back to the authenticated user's email
// so any entry path that omits the query — e.g. a stale bookmark — still
// renders the address and keeps the resend button functional.
const email = computed(() => {
  const fromQuery = typeof route.query.email === 'string' ? route.query.email : ''
  return fromQuery !== '' ? fromQuery : (store.user?.attributes.email ?? '')
})

const errorKey = ref<string | null>(null)
const errorValues = ref<Record<string, string | number>>({})
const resentAt = ref<number | null>(null)
const isCheckingVerification = ref(false)

/**
 * Delete link: native, holding a session (the unverified-sign-in bounce leaves one),
 * and the server says deletion is available to this user. Same gate as every other
 * Security entry; the route's own guard still decides.
 */
const showDeleteLink = computed(
  () => isNative && store.user?.attributes.features?.account_deletion === true,
)

function goToSignIn(): Promise<unknown> {
  return router.push({
    name: 'auth.sign-in',
    ...(email.value !== '' ? { query: { email: email.value } } : {}),
  })
}

/**
 * The primary button has two states.
 *
 * - Signed out (the usual case, straight after sign-up): go to sign-in with the
 *   address carried so the field is already filled. `refreshSession()` makes no
 *   request without a session, so this path touches no API.
 * - Signed in (an unverified sign-in bounced here holding a session): `/sign-in`
 *   would be turned away by `requireGuest`, so re-read the account instead. Verified
 *   now: on to the wizard. Not yet: say so and stay. The refresh can also end the
 *   session (a dead token), in which case the answer is sign-in again.
 */
async function verifiedSignIn(): Promise<void> {
  errorKey.value = null
  errorValues.value = {}
  isCheckingVerification.value = true
  try {
    await store.refreshSession()
  } finally {
    isCheckingVerification.value = false
  }
  if (store.user === null) {
    await goToSignIn()
    return
  }
  if (store.user.attributes.email_verified_at != null) {
    await router.push({ name: 'onboarding.welcome-back' })
    return
  }
  errorKey.value = 'auth.ui.errors.not_verified_yet'
}

async function resend(): Promise<void> {
  if (email.value.length === 0) {
    errorKey.value = 'auth.ui.errors.missing_token'
    errorValues.value = {}
    return
  }
  errorKey.value = null
  errorValues.value = {}
  try {
    await store.resendVerification({ email: email.value })
    resentAt.value = Date.now()
  } catch (err) {
    const resolved = resolveErrorMessage(err, (k) => te(k))
    errorKey.value = resolved.key
    errorValues.value = resolved.values
  }
}
</script>

<template>
  <section data-test="email-verification-pending-page">
    <h2 class="text-h5 mb-4" data-test="email-verification-pending-heading">
      {{ t('auth.ui.headings.verify_email_pending') }}
    </h2>

    <p class="text-body-1 mb-4" data-test="email-verification-pending-description">
      {{ t('auth.ui.descriptions.verify_email_pending', { email }) }}
    </p>

    <v-alert
      v-if="resentAt !== null"
      type="success"
      variant="tonal"
      class="mb-4"
      data-test="email-verification-pending-resent-banner"
    >
      {{ t('auth.ui.banners.verification_email_sent') }}
    </v-alert>

    <div
      role="alert"
      aria-live="polite"
      class="text-error text-body-2 mb-2"
      data-test="email-verification-pending-error"
    >
      <template v-if="errorKey !== null">{{ t(errorKey, errorValues) }}</template>
    </div>

    <v-btn
      color="primary"
      block
      :loading="isResendingVerification"
      :disabled="isResendingVerification"
      data-test="email-verification-pending-resend"
      @click="resend"
    >
      {{
        isResendingVerification
          ? t('auth.ui.loading.sending')
          : t('auth.ui.actions.resend_verification')
      }}
    </v-btn>

    <v-btn
      v-if="isNative"
      color="primary"
      variant="outlined"
      block
      class="mt-3"
      :loading="isCheckingVerification"
      :disabled="isCheckingVerification"
      data-test="email-verification-pending-verified"
      @click="verifiedSignIn"
    >
      {{ t('auth.ui.actions.verified_sign_in') }}
    </v-btn>

    <div v-if="showDeleteLink" class="mt-4 text-body-2">
      <router-link :to="{ name: 'creator.security' }" data-test="email-verification-pending-delete">
        {{ t('creator.ui.security.delete.button') }}
      </router-link>
    </div>

    <div v-if="!isNative" class="mt-4 text-body-2">
      <router-link :to="{ name: 'auth.sign-in' }" data-test="email-verification-pending-back">
        {{ t('auth.ui.actions.back_to_sign_in') }}
      </router-link>
    </div>
  </section>
</template>
