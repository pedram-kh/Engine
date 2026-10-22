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
 *
 * Also the one mount point for the Meta Pixel. It belongs here rather
 * than in `index.html` or the auth layout because the sibling auth routes
 * carry single-use tokens in their query strings, which the pixel would
 * report to Meta — see the header of `internal/metaPixel.ts`.
 *
 * AH-086: this same component also renders at `auth.agency-sign-in`
 * (`/agency/sign-in`) — see `isAgencyVariant` below. No fork: the form,
 * the login call, and postLoginTarget()'s role-based dispatch are
 * identical on both routes.
 *
 * AH-100: inside the Capacitor creator app the same page is the app's
 * sign-in screen. `store.login()` goes to `POST /auth/token` there; the
 * page itself only differs in three ways — no Meta Pixel, no sign-up
 * funnel (a pointer to the web instead), and a wrong-door message that
 * names the web address the account belongs to.
 *
 * AH-101 U2: that same native screen takes the Figma sign-in sheet (frame 02):
 * the brand mark above, labels above the fields, "forgot password" in the
 * password label row, the error as a tinted banner. Every difference is gated
 * on `isNative` and styled by `signInNative.css`; the web markup is unchanged.
 */

import { ApiError, deletionPendingMeta, type DeletionPendingMeta } from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter, type RouteLocationRaw } from 'vue-router'
import { storeToRefs } from 'pinia'

import { isNativePlatform } from '@/core/platform/isNativePlatform'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { resolveErrorMessage } from '@/modules/auth/composables/useErrorMessage'
import { useMfaCodeInput } from '@/modules/auth/composables/useMfaCodeInput'
import { loadMetaPixel } from '@/modules/auth/internal/metaPixel'
import nativeMark from '@/modules/auth/assets/native-sign-in-mark.svg'

const { t, te, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useAuthStore()
const { isLoggingIn, userType, sessionLapsed } = storeToRefs(store)

const email = ref('')
const password = ref('')
const totpCode = ref('')
const mfaInput = useMfaCodeInput()

/**
 * Two-step UI: the form starts with email + password only, and after
 * the backend signals `auth.mfa_required` we reveal the TOTP input.
 * The kickoff says: "On 2FA-required error, transitions to a TOTP
 * code input within the same page (no separate route)."
 */
const showTotpField = ref(false)

/**
 * AH-104b-del: the credentials were right but the account is scheduled for
 * deletion. The backend answered `auth.deletion_pending` and opened no
 * session; this page shows the restore step in place of the form. Kept
 * page-local on purpose — nothing else needs it, and a store field would
 * outlive the page.
 */
const pending = ref<DeletionPendingMeta | null>(null)
const hintKey = ref<string | null>(null)

const pendingDate = computed(() => {
  if (pending.value === null) {
    return ''
  }
  const date = new Date(pending.value.scheduledFor)
  if (Number.isNaN(date.getTime())) {
    return pending.value.scheduledFor
  }
  return new Intl.DateTimeFormat(locale.value, { dateStyle: 'long' }).format(date)
})

const errorKey = ref<string | null>(null)
const errorValues = ref<Record<string, string | number>>({})

const isNative = isNativePlatform()

/** The native sheet outlines the credential fields on a failed sign-in (frame 02b). */
const credentialError = computed(
  () => errorKey.value !== null && errorKey.value !== 'auth.mfa_required' && pending.value === null,
)

/**
 * The banner is for involuntary termination only. `?reason=session_expired`
 * comes from the 401 policy; inside the creator app the cold-start
 * bootstrap adds the lapse flag for a token that died while the app was
 * closed (AH-100b D3). A deliberate sign-out sets neither.
 */
const sessionExpired = computed(
  () => route.query.reason === 'session_expired' || (isNative && sessionLapsed.value),
)

/**
 * AH-104b-del — arriving here from the delete-account dialog: `?reason=deletion_scheduled&date=<ISO>`.
 * A voluntary, successful act, so it is an info banner (not the session-expired warning) naming
 * the day the account will be deleted and how to keep it. Nothing in the URL is trusted beyond
 * being a date to format; anything unparseable shows no banner rather than a broken date.
 */
const deletionScheduledDate = computed(() => {
  if (route.query.reason !== 'deletion_scheduled' || typeof route.query.date !== 'string') {
    return null
  }
  const date = new Date(route.query.date)
  if (Number.isNaN(date.getTime())) {
    return null
  }
  return new Intl.DateTimeFormat(locale.value, { dateStyle: 'long' }).format(date)
})

/** Where a creator without an account goes (AH-100 Q8: no in-app sign-up in v1). */
const CREATOR_SIGNUP_SITE = 'platform.catalyst-growth.com'

/**
 * AH-086 — the agency-facing sign-in variant. Same component, same
 * endpoint, same postLoginTarget() dispatch; the ONLY differences are the
 * heading copy and the absence of the creator-signup CTA below. Reading
 * `route.name` mirrors the mechanism `AuthLayout.vue`'s `isHero` already
 * uses to distinguish `auth.sign-in` — one source of truth, no second
 * page to drift out of sync with future auth changes. If a creator signs
 * in through this route, `onSubmit()` and `postLoginTarget()` below run
 * completely unchanged — this is a door, not a wall.
 */
const isAgencyVariant = computed(() => route.name === 'auth.agency-sign-in')

const headingKey = computed(() =>
  isAgencyVariant.value ? 'auth.ui.headings.agency_sign_in' : 'auth.ui.headings.sign_in',
)

/**
 * AH-086b — the agency team-invite email's magic link now points at
 * this route carrying `?token=&agency=` instead of `/accept-invitation`
 * directly (see `AgencyInvitationService::invite()`). This component
 * never inspects, hashes, or validates the token itself — it forwards
 * both params UNCHANGED to the SAME `/accept-invitation` page/flow,
 * which is byte-identical and does all the real work. Gated on
 * `isAgencyVariant` so `/sign-in` itself never forwards on a stray
 * `token`/`agency` query — the D2 zero-diff pin from AH-086 holds.
 * Already-sent invite emails carry the OLD `/accept-invitation?...`
 * URL, which this chunk does not touch — those links keep resolving
 * directly, unaffected by this forward existing or not.
 */
const inviteForwardTarget = computed(() => {
  if (!isAgencyVariant.value) {
    return null
  }
  const token = typeof route.query.token === 'string' ? route.query.token : null
  const agency = typeof route.query.agency === 'string' ? route.query.agency : null
  if (token === null || agency === null) {
    return null
  }
  return { name: 'accept-invitation', query: { token, agency } }
})

// On mount rather than at module scope so the pixel is tied to this page
// being shown, not to the route chunk being downloaded. An invite-link
// forward takes priority — the pixel should not fire for a hop nobody
// actually saw rendered. Never in the creator app: no third-party
// tracking inside the native shell (App Tracking Transparency).
onMounted(() => {
  if (inviteForwardTarget.value !== null) {
    void router.replace(inviteForwardTarget.value)
    return
  }
  if (!isNative) {
    loadMetaPixel(window, document)
  }
})

const submitLabel = computed(() => {
  if (pending.value !== null) {
    return isLoggingIn.value ? t('auth.ui.deletion.restoring') : t('auth.ui.deletion.restore')
  }
  return isLoggingIn.value ? t('auth.ui.loading.logging_in') : t('auth.ui.actions.sign_in')
})

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
    await store.login(
      email.value,
      password.value,
      showTotpField.value ? totpCode.value : undefined,
      pending.value !== null,
    )
    await router.push(postLoginTarget())
  } catch (err) {
    const meta = deletionPendingMeta(err)
    if (meta !== null && pending.value === null) {
      pending.value = meta
      if (meta.mfaVia === 'recovery_code') {
        // The recovery code just spent is burned; the restore needs a fresh one.
        totpCode.value = ''
        showTotpField.value = true
        hintKey.value = 'auth.ui.deletion.code_again'
      } else if (meta.mfaVia === 'totp') {
        hintKey.value = 'auth.ui.deletion.code_hint'
      }
      return
    }
    if (err instanceof ApiError && err.code === 'auth.mfa_required') {
      // Reveal the TOTP field and keep the rest of the form intact.
      showTotpField.value = true
      errorKey.value = 'auth.mfa_required'
      return
    }
    const resolved = resolveErrorMessage(err, (k) => te(k))
    errorKey.value = resolved.key
    errorValues.value = resolved.values
    if (
      isNative &&
      resolved.key === 'auth.wrong_spa' &&
      typeof resolved.values.correct_spa_url === 'string'
    ) {
      errorKey.value = 'auth.ui.native.wrong_door'
    }
  }
}

/** "Not now": back to an empty form; nothing was changed server-side. */
function leaveRestore(): void {
  pending.value = null
  hintKey.value = null
  errorKey.value = null
  errorValues.value = {}
  password.value = ''
  totpCode.value = ''
  showTotpField.value = false
  mfaInput.reset()
}
</script>

<template>
  <section
    v-if="inviteForwardTarget === null"
    :class="{ 'sign-in-native': isNative }"
    data-test="sign-in-page"
  >
    <img
      v-if="isNative"
      :src="nativeMark"
      alt=""
      class="sign-in-native__mark"
      :class="{ 'sign-in-native__mark--compact': errorKey !== null }"
      data-test="sign-in-native-mark"
    />

    <h2 :class="isNative ? 'sign-in-native__title' : 'text-h5 mb-4'" data-test="sign-in-heading">
      {{ pending !== null ? t('auth.ui.deletion.title') : t(headingKey) }}
    </h2>

    <p
      v-if="isNative && pending === null"
      class="sign-in-native__subtitle"
      data-test="sign-in-native-subtitle"
    >
      {{ t('auth.ui.native.sign_in_subtitle') }}
    </p>

    <v-alert
      v-if="deletionScheduledDate !== null"
      type="info"
      variant="tonal"
      class="mb-4"
      data-test="deletion-scheduled-banner"
    >
      {{ t('auth.ui.deletion.scheduled_banner', { date: deletionScheduledDate }) }}
    </v-alert>

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
      <p
        v-if="pending !== null"
        class="text-body-1 mb-4"
        :class="{ 'sign-in-native__subtitle': isNative }"
        data-test="sign-in-deletion-body"
      >
        {{ t('auth.ui.deletion.body', { date: pendingDate }) }}
      </p>

      <label v-if="isNative && pending === null" for="sign-in-email" class="sign-in-native__label">
        {{ t('auth.ui.labels.email') }}
      </label>
      <v-text-field
        v-if="pending === null"
        id="sign-in-email"
        v-model="email"
        :label="isNative ? undefined : t('auth.ui.labels.email')"
        :prepend-inner-icon="isNative ? 'mdi-email-outline' : undefined"
        :hide-details="isNative"
        :error="isNative && credentialError"
        type="email"
        autocomplete="email"
        required
        data-test="sign-in-email"
      />

      <div v-if="isNative && pending === null" class="sign-in-native__label-row">
        <label for="sign-in-password" class="sign-in-native__label">
          {{ t('auth.ui.labels.password') }}
        </label>
        <!-- Frame 02: "forgot password" lives in the password label row. -->
        <router-link
          :to="{ name: 'auth.forgot-password' }"
          class="sign-in-native__forgot"
          data-test="sign-in-forgot-link"
        >
          {{ t('auth.ui.headings.forgot_password') }}
        </router-link>
      </div>
      <v-text-field
        v-if="pending === null"
        id="sign-in-password"
        v-model="password"
        :label="isNative ? undefined : t('auth.ui.labels.password')"
        :prepend-inner-icon="isNative ? 'mdi-lock-outline' : undefined"
        :hide-details="isNative"
        :error="isNative && credentialError"
        type="password"
        autocomplete="current-password"
        required
        data-test="sign-in-password"
      />

      <label v-if="isNative && showTotpField" for="sign-in-totp" class="sign-in-native__label">
        {{ t(mfaInput.labelKey.value) }}
      </label>
      <v-text-field
        v-if="showTotpField"
        id="sign-in-totp"
        v-model="totpCode"
        :label="isNative ? undefined : t(mfaInput.labelKey.value)"
        :placeholder="mfaInput.placeholder.value"
        :hide-details="isNative"
        type="text"
        :inputmode="mfaInput.inputmode.value"
        :autocomplete="mfaInput.autocomplete.value"
        autocapitalize="off"
        autocorrect="off"
        spellcheck="false"
        maxlength="32"
        required
        data-test="sign-in-totp"
      />
      <v-btn
        v-if="showTotpField"
        variant="text"
        size="small"
        class="mb-2 px-0"
        :disabled="isLoggingIn"
        data-test="sign-in-mfa-toggle"
        @click="mfaInput.toggle"
      >
        {{ t(mfaInput.toggleKey.value) }}
      </v-btn>
      <p
        v-if="pending !== null && hintKey !== null"
        class="text-body-2 mb-2"
        data-test="sign-in-deletion-hint"
      >
        {{ t(hintKey) }}
      </p>

      <div
        role="alert"
        aria-live="polite"
        :class="
          isNative
            ? ['sign-in-native__error', { 'sign-in-native__error--on': errorKey !== null }]
            : 'text-error text-body-2 mb-2'
        "
        data-test="sign-in-error"
      >
        <template v-if="errorKey !== null">
          <v-icon v-if="isNative" icon="mdi-alert-circle-outline" size="18" aria-hidden="true" />
          {{ t(errorKey, errorValues) }}
        </template>
      </div>

      <v-btn
        type="submit"
        color="primary"
        block
        :size="isNative ? 'large' : undefined"
        :loading="isLoggingIn"
        :disabled="isLoggingIn"
        data-test="sign-in-submit"
      >
        {{ submitLabel }}
      </v-btn>
      <v-btn
        v-if="pending !== null"
        variant="text"
        block
        class="mt-2"
        :size="isNative ? 'large' : undefined"
        :disabled="isLoggingIn"
        data-test="sign-in-deletion-leave"
        @click="leaveRestore"
      >
        {{ t('auth.ui.deletion.leave') }}
      </v-btn>
    </form>

    <div v-if="!isNative && pending === null" class="mt-4 d-flex justify-space-between text-body-2">
      <!-- AH-086 D2: the agency variant has no signup flow of any kind —
           agencies are Catalyst-created. This link is the creator-signup
           funnel and does not appear here. -->
      <router-link
        v-if="!isAgencyVariant"
        :to="{ name: 'auth.sign-up' }"
        data-test="sign-in-signup-link"
      >
        {{ t('auth.ui.actions.sign_up') }}
      </router-link>
      <router-link :to="{ name: 'auth.forgot-password' }" data-test="sign-in-forgot-link">
        {{ t('auth.ui.headings.forgot_password') }}
      </router-link>
    </div>

    <!-- AH-101 Q7: frame 02's "Create an account" button is NOT drawn. A button
         with nowhere in-app to go is dead, and an external account-creation link
         is store-sensitive; the pointer line stays. The button returns with real
         in-app sign-up (AH-104d). -->
    <i18n-t
      v-if="isNative && pending === null"
      keypath="auth.ui.native.create_account"
      tag="p"
      class="sign-in-native__pointer"
      data-test="sign-in-native-create-account"
    >
      <template #site>
        <span class="sign-in-native__site">{{ CREATOR_SIGNUP_SITE }}</span>
      </template>
    </i18n-t>
  </section>
</template>
<style scoped src="./signInNative.css"></style>
