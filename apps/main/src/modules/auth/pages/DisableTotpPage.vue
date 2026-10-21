<script setup lang="ts">
/**
 * DisableTotpPage — disable an active TOTP enrollment.
 *
 * Per chunk-5 priority #10: disable requires BOTH the user's current
 * password AND a working 2FA code (TOTP or recovery code).
 *
 * On success, navigates back to where the user manages their account: `creator.security`
 * for a creator, `settings` for everyone else. (It used to push `app.settings`, a route that
 * has never existed, so a successful disable ended on a failed navigation. AH-104b-del B5.)
 */

import { ApiError } from '@catalyst/api-client'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { resolveErrorMessage } from '@/modules/auth/composables/useErrorMessage'

const { t, te } = useI18n()
const router = useRouter()
const store = useAuthStore()
const { isDisablingTotp } = storeToRefs(store)

/** Where "done" and "cancel" go: the creator's Security page, or the agency Settings page. */
const backTo = computed(() =>
  store.user?.attributes.user_type === 'creator'
    ? { name: 'creator.security' }
    : { name: 'settings' },
)

const password = ref('')
const code = ref('')
const errorKey = ref<string | null>(null)
const errorValues = ref<Record<string, string | number>>({})

async function onSubmit(): Promise<void> {
  errorKey.value = null
  errorValues.value = {}
  try {
    await store.disableTotp({ password: password.value, mfa_code: code.value })
    await router.push(backTo.value)
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
  <section data-test="disable-totp-page">
    <h2 class="text-h5 mb-4" data-test="disable-totp-heading">
      {{ t('auth.ui.headings.disable_2fa') }}
    </h2>

    <form novalidate @submit.prevent="onSubmit">
      <v-text-field
        id="disable-totp-password"
        v-model="password"
        :label="t('auth.ui.labels.current_password')"
        type="password"
        autocomplete="current-password"
        required
        data-test="disable-totp-password"
      />
      <v-text-field
        id="disable-totp-code"
        v-model="code"
        :label="t('auth.ui.labels.totp_code')"
        type="text"
        inputmode="numeric"
        maxlength="32"
        autocomplete="one-time-code"
        required
        data-test="disable-totp-code"
      />

      <div
        role="alert"
        aria-live="polite"
        class="text-error text-body-2 mb-2"
        data-test="disable-totp-error"
      >
        <template v-if="errorKey !== null">{{ t(errorKey, errorValues) }}</template>
      </div>

      <v-btn
        type="submit"
        color="error"
        block
        :loading="isDisablingTotp"
        :disabled="isDisablingTotp"
        data-test="disable-totp-submit"
      >
        {{ isDisablingTotp ? t('auth.ui.loading.submitting') : t('auth.ui.actions.disable_2fa') }}
      </v-btn>
    </form>

    <div class="mt-4 text-body-2">
      <router-link :to="backTo" data-test="disable-totp-back">
        {{ t('auth.ui.actions.cancel') }}
      </router-link>
    </div>
  </section>
</template>
