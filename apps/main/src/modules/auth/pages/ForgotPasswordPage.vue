<script setup lang="ts">
/**
 * ForgotPasswordPage — single email input + submit. The backend
 * deliberately returns 204 regardless of whether the email exists
 * (user-enumeration defence per `docs/05-SECURITY-COMPLIANCE.md § 6.6`).
 * The UI mirrors that policy with a single generic confirmation
 * banner: "if an account exists for that email, a link is on its way".
 */

import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { storeToRefs } from 'pinia'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { resolveErrorMessage } from '@/modules/auth/composables/useErrorMessage'

const { t, te } = useI18n()
const store = useAuthStore()
const { isRequestingPasswordReset } = storeToRefs(store)

const email = ref('')
const sentAt = ref<number | null>(null)
const errorKey = ref<string | null>(null)
const errorValues = ref<Record<string, string | number>>({})

async function onSubmit(): Promise<void> {
  errorKey.value = null
  errorValues.value = {}
  try {
    await store.forgotPassword({ email: email.value })
    sentAt.value = Date.now()
  } catch (err) {
    const resolved = resolveErrorMessage(err, (k) => te(k))
    errorKey.value = resolved.key
    errorValues.value = resolved.values
  }
}
</script>

<template>
  <section data-test="forgot-password-page">
    <h2 class="text-h5 mb-4" data-test="forgot-password-heading">
      {{ t('auth.ui.headings.forgot_password') }}
    </h2>

    <p class="text-body-1 mb-4" data-test="forgot-password-description">
      {{ t('auth.ui.descriptions.forgot_password') }}
    </p>

    <v-alert
      v-if="sentAt !== null"
      type="success"
      variant="tonal"
      class="mb-4"
      data-test="forgot-password-sent-banner"
    >
      {{ t('auth.ui.banners.reset_link_sent') }}
    </v-alert>

    <form novalidate @submit.prevent="onSubmit">
      <v-text-field
        id="forgot-password-email"
        v-model="email"
        :label="t('auth.ui.labels.email')"
        type="email"
        autocomplete="email"
        required
        data-test="forgot-password-email"
      />

      <div
        role="alert"
        aria-live="polite"
        class="text-error text-body-2 mb-2"
        data-test="forgot-password-error"
      >
        <template v-if="errorKey !== null">{{ t(errorKey, errorValues) }}</template>
      </div>

      <v-btn
        type="submit"
        color="primary"
        block
        :loading="isRequestingPasswordReset"
        :disabled="isRequestingPasswordReset"
        data-test="forgot-password-submit"
      >
        {{
          isRequestingPasswordReset
            ? t('auth.ui.loading.sending')
            : t('auth.ui.actions.send_reset_link')
        }}
      </v-btn>
    </form>

    <div class="mt-4 text-body-2">
      <router-link :to="{ name: 'auth.sign-in' }" data-test="forgot-password-back">
        {{ t('auth.ui.actions.back_to_sign_in') }}
      </router-link>
    </div>
  </section>
</template>
