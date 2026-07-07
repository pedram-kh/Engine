<script setup lang="ts">
/**
 * SignInPage — email + password (+ optional TOTP) form.
 *
 * Behaviours covered by the chunk-6.6 acceptance criteria:
 *   - Renders i18n strings only (no hardcoded user-visible text).
 *   - Reads `?reason=session_expired` and shows a banner.
 *   - On `auth.mfa_required` (the backend's "2FA needed" signal),
 *     transitions to the TOTP input within the same page (no separate
 *     route, per the kickoff).
 *   - Renders `t(error.code)` for every other ApiError using the
 *     shared `resolveErrorMessage` composable.
 *   - Per-action loading state from the store drives the button's
 *     loading prop (chunk-6.4 standard: per-action loading flags).
 *   - Form fields are properly labelled and the error region uses
 *     `aria-live="polite"`.
 */

import { ApiError } from '@catalyst/api-client'
import { computed, ref } from 'vue'
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

const email = ref('')
const password = ref('')
const totpCode = ref('')

/**
 * Two-step UI: the form starts with email + password only, and after
 * the backend signals `auth.mfa_required` we reveal the TOTP input.
 * The kickoff says: "On 2FA-required error, transitions to a TOTP
 * code input within the same page (no separate route)."
 */
const showTotpField = ref(false)

const errorKey = ref<string | null>(null)
const errorValues = ref<Record<string, string | number>>({})

const sessionExpired = computed(() => route.query.reason === 'session_expired')

const submitLabel = computed(() =>
  isLoggingIn.value ? t('auth.ui.loading.logging_in') : t('auth.ui.actions.sign_in'),
)

/**
 * Resolve the post-login navigation target.
 *
 * Stabilization (post-Sprint 3): pre-fix this page sent every user to
 * `/` after a successful login, which is `app.dashboard` → the agency
 * dashboard page wrapped in `AgencyLayout` (sidebar:
 * Dashboard / Brands / Team / Settings). For a creator who arrived
 * via the bulk-invite magic link → sign-up → sign-in path, that's the
 * wrong shell entirely — they should land on the onboarding wizard's
 * Welcome-Back surface (`/onboarding`), which auto-advances to the
 * next incomplete step (or to `/creator/dashboard` if their
 * `application_status !== 'incomplete'`).
 *
 * Rule:
 *   1. If `?redirect=<path>` is set AND it's not the default agency
 *      home (`/`), honor it. This preserves the session-expired flow
 *      where `requireAuth` captured `to.fullPath` (e.g.
 *      `/onboarding/profile`) before bouncing through sign-in —
 *      `requireOnboardingAccess` on the destination still validates
 *      the user_type + status, so a stale wizard redirect against an
 *      agency user (or vice-versa) is still safely bounced.
 *   2. Otherwise dispatch by `user_type`: creators land on the wizard's
 *      Welcome-Back page (which itself routes onward to the right
 *      step or to the creator dashboard); every other user_type lands
 *      on the agency dashboard (the existing behaviour).
 *
 * The shape mirrors the symmetry already present in
 * `requireOnboardingAccess`: non-creators bouncing OFF wizard routes
 * land on `app.dashboard`, so creators bouncing TO their home should
 * land on `onboarding.welcome-back`. The defensive case — a creator
 * manually typing an agency URL — is tracked in `docs/tech-debt.md`
 * under "Defensive requireAgencyUser guard for agency routes".
 */
function postLoginTarget(): RouteLocationRaw {
  const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : ''
  if (redirect.length > 0 && redirect !== '/') {
    return redirect
  }
  if (userType.value === 'creator') {
    // Unverified creators cannot bootstrap any verified-gated wizard
    // surface — `/api/v1/creators/me` responds 403, so
    // `requireOnboardingAccess`'s bootstrap() throws, router.push
    // rejects, and the SPA shows the generic "Something went wrong"
    // banner with the URL stuck on /sign-in. Caught in CI by
    // playwright/specs/2fa-enrollment-and-sign-in.spec.ts:125 and
    // playwright/specs/failed-login-lockout-and-reset.spec.ts:244 —
    // both sign up a creator and immediately sign in WITHOUT clicking
    // the verification email link. Production users hit the same
    // edge case when they sign up, close the browser, then return
    // and sign in directly. Bounce to `/verify-email/pending` so the
    // precondition is fixed before the wizard guard chain runs.
    if (store.user?.attributes.email_verified_at == null) {
      // Carry the email so the pending page can interpolate it and the
      // resend button works (it reads the address from the route query).
      return {
        name: 'auth.verify-email.pending',
        query: { email: store.user?.attributes.email },
      }
    }
    return { name: 'onboarding.welcome-back' }
  }
  return '/'
}

async function onSubmit(): Promise<void> {
  errorKey.value = null
  errorValues.value = {}
  try {
    await store.login(email.value, password.value, showTotpField.value ? totpCode.value : undefined)
    await router.push(postLoginTarget())
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

    <div class="mt-4 d-flex justify-space-between text-body-2">
      <router-link :to="{ name: 'auth.sign-up' }" data-test="sign-in-signup-link">
        {{ t('auth.ui.actions.sign_up') }}
      </router-link>
      <router-link :to="{ name: 'auth.forgot-password' }" data-test="sign-in-forgot-link">
        {{ t('auth.ui.headings.forgot_password') }}
      </router-link>
    </div>
  </section>
</template>
