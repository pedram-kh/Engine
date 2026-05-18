<script setup lang="ts">
/**
 * ResetPasswordPage — landing page from a password-reset email link.
 * Reads `token` and `email` from the route query, requires the user
 * to enter + confirm their new password, then calls `resetPassword()`.
 *
 * On success, transitions to a confirmation banner with a "back to
 * sign in" link. On error, renders the i18n-resolved error inline.
 */

import { ApiError, extractFieldErrors } from '@catalyst/api-client'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { resolveErrorMessage } from '@/modules/auth/composables/useErrorMessage'

const { t, te } = useI18n()
const route = useRoute()
const store = useAuthStore()
const { isResettingPassword } = storeToRefs(store)

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))
const email = computed(() => (typeof route.query.email === 'string' ? route.query.email : ''))

const password = ref('')
const passwordConfirmation = ref('')
const reset = ref(false)
const errorKey = ref<string | null>(null)
const errorValues = ref<Record<string, string | number>>({})

/**
 * Per-field validation errors extracted from a 422 envelope. See the
 * matching `fieldErrors` block in `SignUpPage.vue` for the full
 * rationale — the short version is that the backend ships
 * `code: validation.failed` with the actual message in `details[].detail`
 * and the per-field pointer in `source.pointer`, so the
 * `extractFieldErrors` helper is the right surface to render the
 * StrongPassword "min 12 chars" error under the password field
 * instead of in a generic banner. The banner remains for semantic
 * codes like `auth.password.invalid_token` / `auth.password.compromised`
 * that have bundle entries.
 */
type ResetPasswordField = 'password' | 'password_confirmation'
const fieldErrors = ref<Partial<Record<ResetPasswordField, readonly string[]>>>({})

async function onSubmit(): Promise<void> {
  errorKey.value = null
  errorValues.value = {}
  fieldErrors.value = {}
  if (token.value.length === 0 || email.value.length === 0) {
    errorKey.value = 'auth.ui.errors.missing_token'
    return
  }
  try {
    await store.resetPassword({
      email: email.value,
      token: token.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    reset.value = true
  } catch (err) {
    if (err instanceof ApiError) {
      fieldErrors.value = extractFieldErrors<ResetPasswordField>(err)
    }
    if (Object.keys(fieldErrors.value).length === 0) {
      const resolved = resolveErrorMessage(err, (k) => te(k))
      errorKey.value = resolved.key
      errorValues.value = resolved.values
    }
  }
}
</script>

<template>
  <section data-test="reset-password-page">
    <h2 class="text-h5 mb-4" data-test="reset-password-heading">
      {{ t('auth.ui.headings.reset_password') }}
    </h2>

    <v-alert
      v-if="reset"
      type="success"
      variant="tonal"
      class="mb-4"
      data-test="reset-password-success"
    >
      {{ t('auth.ui.banners.password_reset_done') }}
    </v-alert>

    <form v-if="!reset" novalidate @submit.prevent="onSubmit">
      <v-text-field
        id="reset-password-password"
        v-model="password"
        :label="t('auth.ui.labels.password')"
        :error-messages="fieldErrors.password"
        type="password"
        autocomplete="new-password"
        required
        data-test="reset-password-password"
      />
      <v-text-field
        id="reset-password-password-confirmation"
        v-model="passwordConfirmation"
        :label="t('auth.ui.labels.password_confirmation')"
        :error-messages="fieldErrors.password_confirmation"
        type="password"
        autocomplete="new-password"
        required
        data-test="reset-password-password-confirmation"
      />

      <div
        role="alert"
        aria-live="polite"
        class="text-error text-body-2 mb-2"
        data-test="reset-password-error"
      >
        <template v-if="errorKey !== null">{{ t(errorKey, errorValues) }}</template>
      </div>

      <v-btn
        type="submit"
        color="primary"
        block
        :loading="isResettingPassword"
        :disabled="isResettingPassword"
        data-test="reset-password-submit"
      >
        {{
          isResettingPassword
            ? t('auth.ui.loading.submitting')
            : t('auth.ui.actions.reset_password')
        }}
      </v-btn>
    </form>

    <div class="mt-4 text-body-2">
      <router-link :to="{ name: 'auth.sign-in' }" data-test="reset-password-back">
        {{ t('auth.ui.actions.back_to_sign_in') }}
      </router-link>
    </div>
  </section>
</template>
