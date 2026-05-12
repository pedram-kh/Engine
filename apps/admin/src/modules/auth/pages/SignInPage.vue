<script setup lang="ts">
/**
 * SignInPage — admin SPA email + password (+ optional TOTP) form.
 *
 * Mirror of `apps/main/src/modules/auth/pages/SignInPage.vue` (chunk 6.6)
 * with one structurally-correct admin adaptation: the sign-up and
 * forgot-password router-links at the bottom of the form are removed
 * because admin has neither an admin-signup flow nor a self-service
 * password-reset route (admin routes live in
 * `apps/admin/src/modules/auth/routes.ts` — only `/sign-in` and the
 * 2FA sub-routes exist on the auth surface; sign-up + forgot-password
 * are out-of-band per `docs/20-PHASE-1-SPEC.md § 5`). Everything else
 * is verbatim.
 *
 * Behaviours covered by chunk 6.6 acceptance criteria:
 *   - Renders i18n strings only (no hardcoded user-visible text).
 *   - Reads `?reason=session_expired` and shows a banner (chunks 6.5 +
 *     7.4 — the admin 401-policy redirect target uses the same
 *     `SESSION_EXPIRED_QUERY_REASON` constant; see
 *     `apps/admin/src/core/api/index.ts:47`).
 *   - On `auth.mfa_required` (the backend's "2FA needed" signal),
 *     transitions to the TOTP input within the same page (no separate
 *     route, matching main's UX).
 *   - Renders `t(error.code)` for every other ApiError via
 *     `resolveErrorMessage`.
 *   - Per-action loading state from `useAdminAuthStore.isLoggingIn`
 *     drives the button's loading prop (Group 1's per-action flag
 *     convention).
 *   - Form fields properly labelled; error region `aria-live="polite"`.
 *
 * The chunk-7.4 mandatory-MFA enforcement story interacts cleanly with
 * this page: a new admin signs in successfully here, then `bootstrap()`
 * (already called by `requireAuth` during the post-login navigation —
 * see `apps/admin/src/core/router/guards.ts:80-110`) surfaces the
 * `mfaEnrollmentRequired` flag, and the `requireAuth` branch redirects
 * to `/auth/2fa/enable?redirect=<intended>`. This page does not need
 * to know about that flow — the router owns it.
 */

import { ApiError } from '@catalyst/api-client'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'

import { useAdminAuthStore } from '@/modules/auth/stores/useAdminAuthStore'
import { resolveErrorMessage } from '@/modules/auth/composables/useErrorMessage'

const { t, te } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useAdminAuthStore()
const { isLoggingIn } = storeToRefs(store)

const email = ref('')
const password = ref('')
const totpCode = ref('')

/**
 * Two-step UI: the form starts with email + password only, and after
 * the backend signals `auth.mfa_required` we reveal the TOTP input
 * inline (mirroring main's chunk-6.6 decision — same-page reveal vs.
 * separate route; the dedicated `/auth/2fa/verify` route exists for
 * deep-link continuity and the kickoff item 18 narrative).
 */
const showTotpField = ref(false)

const errorKey = ref<string | null>(null)
const errorValues = ref<Record<string, string | number>>({})

const sessionExpired = computed(() => route.query.reason === 'session_expired')

const submitLabel = computed(() =>
  isLoggingIn.value ? t('auth.ui.loading.logging_in') : t('auth.ui.actions.sign_in'),
)

async function onSubmit(): Promise<void> {
  errorKey.value = null
  errorValues.value = {}
  try {
    await store.login(email.value, password.value, showTotpField.value ? totpCode.value : undefined)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    await router.push(redirect)
  } catch (err) {
    if (err instanceof ApiError && err.code === 'auth.mfa_required') {
      // Reveal the TOTP field and keep the rest of the form intact.
      showTotpField.value = true
      errorKey.value = 'auth.mfa_required'
      return
    }
    const resolved = resolveErrorMessage(err, (k) => te(k))
    errorKey.value = resolved.key
    errorValues.value = resolved.values
  }
}
</script>

<template>
  <section data-test="sign-in-page">
    <h2 class="text-h5 mb-4" data-test="sign-in-heading">
      {{ t('auth.ui.headings.sign_in') }}
    </h2>

    <v-alert
      v-if="sessionExpired"
      type="warning"
      variant="tonal"
      class="mb-4"
      data-test="session-expired-banner"
    >
      {{ t('auth.ui.banners.session_expired') }}
    </v-alert>

    <form novalidate @submit.prevent="onSubmit">
      <v-text-field
        id="sign-in-email"
        v-model="email"
        :label="t('auth.ui.labels.email')"
        type="email"
        autocomplete="email"
        required
        data-test="sign-in-email"
      />

      <v-text-field
        id="sign-in-password"
        v-model="password"
        :label="t('auth.ui.labels.password')"
        type="password"
        autocomplete="current-password"
        required
        data-test="sign-in-password"
      />

      <v-text-field
        v-if="showTotpField"
        id="sign-in-totp"
        v-model="totpCode"
        :label="t('auth.ui.labels.totp_code')"
        type="text"
        inputmode="numeric"
        autocomplete="one-time-code"
        maxlength="32"
        required
        data-test="sign-in-totp"
      />

      <div
        role="alert"
        aria-live="polite"
        class="text-error text-body-2 mb-2"
        data-test="sign-in-error"
      >
        <template v-if="errorKey !== null">{{ t(errorKey, errorValues) }}</template>
      </div>

      <v-btn
        type="submit"
        color="primary"
        block
        :loading="isLoggingIn"
        :disabled="isLoggingIn"
        data-test="sign-in-submit"
      >
        {{ submitLabel }}
      </v-btn>
    </form>
  </section>
</template>
