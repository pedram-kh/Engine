<script setup lang="ts">
/**
 * VerifyTotpPage — six-digit TOTP entry. Reachable from the sign-in
 * flow when the backend has signalled `auth.mfa_required` AND a
 * separate route is preferred over the inline reveal in
 * `SignInPage.vue` (the kickoff says SignInPage handles the inline
 * reveal — this page exists for deep-linking + email-flow continuity).
 *
 * Reads `email` and `password` from the route query (passed by the
 * sign-in page when it routes here) and re-submits the login.
 */

import { ApiError } from '@catalyst/api-client'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter, type RouteLocationRaw } from 'vue-router'
import { storeToRefs } from 'pinia'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { resolveErrorMessage } from '@/modules/auth/composables/useErrorMessage'

const { t, te } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useAuthStore()
const { isLoggingIn, userType } = storeToRefs(store)

const code = ref('')
const errorKey = ref<string | null>(null)
const errorValues = ref<Record<string, string | number>>({})

/**
 * Post-login navigation target — mirrors `SignInPage.postLoginTarget`.
 * See that file for the full rationale; the short version is that a
 * creator finishing 2FA on the verify-totp route lands here too, and
 * pre-stabilization this page also blindly pushed to `/`. Keeping the
 * two surfaces' dispatch logic identical avoids a path-specific drift.
 */
function postLoginTarget(): RouteLocationRaw {
  const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : ''
  if (redirect.length > 0 && redirect !== '/') {
    return redirect
  }
  if (userType.value === 'creator') {
    // Mirrors SignInPage.postLoginTarget — see the long comment there
    // for the discovery context. A creator who reaches the TOTP page
    // has already completed 2FA enrollment (which itself requires a
    // verified email), so this branch is largely defensive symmetry,
    // but keeping the two dispatch surfaces identical prevents
    // path-specific drift.
    if (store.user?.attributes.email_verified_at == null) {
      return { name: 'auth.verify-email.pending' }
    }
    return { name: 'onboarding.welcome-back' }
  }
  return '/'
}

async function onSubmit(): Promise<void> {
  errorKey.value = null
  errorValues.value = {}
  const email = typeof route.query.email === 'string' ? route.query.email : ''
  const password = typeof route.query.password === 'string' ? route.query.password : ''
  if (email.length === 0 || password.length === 0) {
    errorKey.value = 'auth.ui.errors.missing_token'
    return
  }
  try {
    await store.login(email, password, code.value)
    await router.push(postLoginTarget())
  } catch (err) {
    if (err instanceof ApiError) {
      const resolved = resolveErrorMessage(err, (k) => te(k))
      errorKey.value = resolved.key
      errorValues.value = resolved.values
    } else {
      errorKey.value = 'auth.ui.errors.unknown'
    }
  }
}
</script>

<template>
  <section data-test="verify-totp-page">
    <h2 class="text-h5 mb-4" data-test="verify-totp-heading">
      {{ t('auth.ui.headings.verify_2fa') }}
    </h2>

    <form novalidate @submit.prevent="onSubmit">
      <v-text-field
        id="verify-totp-code"
        v-model="code"
        :label="t('auth.ui.labels.totp_code')"
        type="text"
        inputmode="numeric"
        maxlength="32"
        autocomplete="one-time-code"
        required
        data-test="verify-totp-code"
      />

      <div
        role="alert"
        aria-live="polite"
        class="text-error text-body-2 mb-2"
        data-test="verify-totp-error"
      >
        <template v-if="errorKey !== null">{{ t(errorKey, errorValues) }}</template>
      </div>

      <v-btn
        type="submit"
        color="primary"
        block
        :loading="isLoggingIn"
        :disabled="isLoggingIn"
        data-test="verify-totp-submit"
      >
        {{ isLoggingIn ? t('auth.ui.loading.verifying') : t('auth.ui.actions.verify_2fa') }}
      </v-btn>
    </form>

    <div class="mt-4 text-body-2">
      <router-link :to="{ name: 'auth.sign-in' }" data-test="verify-totp-back">
        {{ t('auth.ui.actions.back_to_sign_in') }}
      </router-link>
    </div>
  </section>
</template>
