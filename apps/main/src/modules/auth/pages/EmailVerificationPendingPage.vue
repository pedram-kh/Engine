<script setup lang="ts">
/**
 * "Check your email" page after sign-up. Reads the email from the
 * route query and shows a rate-limited resend button. The api-client
 * surfaces the rate-limit error code, which renders inline via the
 * shared error-resolver.
 */

import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { resolveErrorMessage } from '@/modules/auth/composables/useErrorMessage'

const { t, te } = useI18n()
const route = useRoute()
const store = useAuthStore()
const { isResendingVerification } = storeToRefs(store)

const email = computed(() => (typeof route.query.email === 'string' ? route.query.email : ''))

const errorKey = ref<string | null>(null)
const errorValues = ref<Record<string, string | number>>({})
const resentAt = ref<number | null>(null)

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

    <div class="mt-4 text-body-2">
      <router-link :to="{ name: 'auth.sign-in' }" data-test="email-verification-pending-back">
        {{ t('auth.ui.actions.back_to_sign_in') }}
      </router-link>
    </div>
  </section>
</template>
