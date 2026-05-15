<script setup lang="ts">
/**
 * SignUpPage — name + email + password + password_confirmation form.
 *
 * On the 2xx path, navigates to the email-verification-pending page,
 * passing the entered email through as a route query so the next page
 * can address the user without making another roundtrip.
 *
 * The api-client mirrors the backend `SignUpRequest` validation —
 * `password_confirmation` is forwarded verbatim. We do NOT re-implement
 * the password-match check client-side because the backend's
 * `confirmed` rule is the authoritative gate.
 */

import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { resolveErrorMessage } from '@/modules/auth/composables/useErrorMessage'

const { t, te } = useI18n()
const router = useRouter()
const route = useRoute()
const store = useAuthStore()
const { isSigningUp } = storeToRefs(store)

const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')

const errorKey = ref<string | null>(null)
const errorValues = ref<Record<string, string | number>>({})

/**
 * Sprint 3 Chunk 4 sub-step 4 — magic-link invitation forward path.
 *
 * When the creator arrived from `/auth/accept-invite`, the token is
 * passed through as `?token=<token>` in the query string. We surface
 * an inline banner explaining that they're completing an invitation
 * and forward the token to the backend as `invitation_token`. The
 * backend's SignUpService runs the post-submit hard-lock (email match)
 * and emits the structured `invitation.*` error codes which surface
 * through `resolveErrorMessage` → the `errors.invitation_*` keys.
 */
const invitationToken = computed(() => {
  const raw = route.query.token
  return typeof raw === 'string' && raw.trim() !== '' ? raw.trim() : null
})

const isInvitationFlow = computed(() => invitationToken.value !== null)

async function onSubmit(): Promise<void> {
  errorKey.value = null
  errorValues.value = {}
  try {
    await store.signUp({
      name: name.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
      ...(invitationToken.value !== null ? { invitation_token: invitationToken.value } : {}),
    })
    if (isInvitationFlow.value) {
      // Invitation acceptance auto-verifies the email (the user clicked
      // a link mailed to them) and is now ready to enter the wizard.
      // The /me/wizard route is gated behind requireAuth; the user
      // still needs to sign in once to mint a session. Route them to
      // the verified-email-confirmed page which shows a "Sign in" CTA.
      await router.push({
        name: 'auth.sign-in',
        query: { invited: '1' },
      })
      return
    }
    await router.push({
      name: 'auth.verify-email.pending',
      query: { email: email.value },
    })
  } catch (err) {
    const resolved = resolveErrorMessage(err, (k) => te(k))
    errorKey.value = resolved.key
    errorValues.value = resolved.values
  }
}
</script>

<template>
  <section data-test="sign-up-page">
    <h2 class="text-h5 mb-4" data-test="sign-up-heading">
      {{ t('auth.ui.headings.sign_up') }}
    </h2>

    <v-alert
      v-if="isInvitationFlow"
      type="info"
      variant="tonal"
      class="mb-4"
      data-test="sign-up-invitation-banner"
    >
      {{ t('auth.creator_invitation.banners.completing_invitation') }}
    </v-alert>

    <form novalidate @submit.prevent="onSubmit">
      <v-text-field
        id="sign-up-name"
        v-model="name"
        :label="t('auth.ui.labels.name')"
        autocomplete="name"
        required
        data-test="sign-up-name"
      />
      <v-text-field
        id="sign-up-email"
        v-model="email"
        :label="t('auth.ui.labels.email')"
        type="email"
        autocomplete="email"
        required
        data-test="sign-up-email"
      />
      <v-text-field
        id="sign-up-password"
        v-model="password"
        :label="t('auth.ui.labels.password')"
        type="password"
        autocomplete="new-password"
        required
        data-test="sign-up-password"
      />
      <v-text-field
        id="sign-up-password-confirmation"
        v-model="passwordConfirmation"
        :label="t('auth.ui.labels.password_confirmation')"
        type="password"
        autocomplete="new-password"
        required
        data-test="sign-up-password-confirmation"
      />

      <div
        role="alert"
        aria-live="polite"
        class="text-error text-body-2 mb-2"
        data-test="sign-up-error"
      >
        <template v-if="errorKey !== null">{{ t(errorKey, errorValues) }}</template>
      </div>

      <v-btn
        type="submit"
        color="primary"
        block
        :loading="isSigningUp"
        :disabled="isSigningUp"
        data-test="sign-up-submit"
      >
        {{ isSigningUp ? t('auth.ui.loading.submitting') : t('auth.ui.actions.sign_up') }}
      </v-btn>
    </form>

    <div class="mt-4 text-body-2">
      <router-link :to="{ name: 'auth.sign-in' }" data-test="sign-up-back-link">
        {{ t('auth.ui.actions.back_to_sign_in') }}
      </router-link>
    </div>
  </section>
</template>
