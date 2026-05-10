<script setup lang="ts">
/**
 * EnableTotpPage — two-step TOTP enrollment.
 *
 * Step 1 (`onMounted`):
 *   Calls `enrollTotp()` and renders the QR code (inline SVG) +
 *   manual-entry key.
 *
 * Step 2 (form submit):
 *   Calls `verifyTotp({ provisional_token, code })`. The action
 *   returns the plaintext recovery codes — these are held in
 *   COMPONENT-LOCAL state (a `ref<readonly string[]>([])`) and
 *   forwarded to `<RecoveryCodesDisplay />` as a prop. They MUST
 *   NEVER enter the auth store (chunk-6.4 enforced rule, extended
 *   in chunk-6.7 by the source-inspection extension to forbid even
 *   the `useAuthStore` import inside `RecoveryCodesDisplay.vue`).
 *
 * Step 3 (recovery confirmation):
 *   `<RecoveryCodesDisplay @confirmed>` fires after the 5-second
 *   countdown. We then navigate to the dashboard.
 */

import { ApiError } from '@catalyst/api-client'
import type { EnableTotpResponse } from '@catalyst/api-client'
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'

import RecoveryCodesDisplay from '@/modules/auth/components/RecoveryCodesDisplay.vue'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { resolveErrorMessage } from '@/modules/auth/composables/useErrorMessage'

const { t, te } = useI18n()
const router = useRouter()
const store = useAuthStore()
const { isEnrollingTotp, isVerifyingTotp } = storeToRefs(store)

type Phase = 'loading' | 'enroll' | 'codes'

const phase = ref<Phase>('loading')
const enrollment = ref<EnableTotpResponse | null>(null)
const code = ref('')

/**
 * Component-local recovery-code state. NEVER assigned into the store.
 * Lives only as long as this component is mounted.
 */
const recoveryCodes = ref<readonly string[]>([])

const errorKey = ref<string | null>(null)
const errorValues = ref<Record<string, string | number>>({})

onMounted(async () => {
  try {
    const response = await store.enrollTotp()
    enrollment.value = response
    phase.value = 'enroll'
  } catch (err) {
    const resolved = resolveErrorMessage(err, (k) => te(k))
    errorKey.value = resolved.key
    errorValues.value = resolved.values
    phase.value = 'enroll'
  }
})

async function onConfirm(): Promise<void> {
  errorKey.value = null
  errorValues.value = {}
  /* c8 ignore start -- defensive: the form that calls onConfirm is
     only rendered when `phase === 'enroll' && enrollment !== null`,
     so this guard is structurally unreachable. Kept as a runtime
     belt-and-suspenders against future refactors. */
  if (enrollment.value === null) {
    return
  }
  /* c8 ignore stop */
  try {
    const response = await store.verifyTotp({
      provisional_token: enrollment.value.provisional_token,
      code: code.value,
    })
    recoveryCodes.value = response.recovery_codes
    phase.value = 'codes'
  } catch (err) {
    if (err instanceof ApiError) {
      const resolved = resolveErrorMessage(err, (k) => te(k))
      errorKey.value = resolved.key
      errorValues.value = resolved.values
    } else {
      errorKey.value = 'auth.ui.errors.unknown'
      errorValues.value = {}
    }
  }
}

async function onCodesConfirmed(): Promise<void> {
  await router.push({ name: 'app.dashboard' })
}
</script>

<template>
  <section data-test="enable-totp-page">
    <h2 class="text-h5 mb-4" data-test="enable-totp-heading">
      {{ t('auth.ui.headings.enable_2fa') }}
    </h2>

    <div v-if="isEnrollingTotp || phase === 'loading'" data-test="enable-totp-loading">
      <v-progress-circular indeterminate color="primary" />
      <p class="text-body-2 mt-2">{{ t('auth.ui.loading.preparing_2fa') }}</p>
    </div>

    <template v-else-if="phase === 'enroll' && enrollment !== null">
      <p class="text-body-1 mb-4" data-test="enable-totp-intro">
        {{ t('auth.ui.descriptions.enable_2fa_intro') }}
      </p>

      <!-- eslint-disable vue/no-v-html -->
      <!-- The backend returns the QR code as a server-generated
           inline SVG string; rendering it as raw HTML is the
           documented contract from `EnableTotpResponse.qr_code_svg`.
           The backend is the only producer of this string. -->
      <div
        class="enable-totp__qr mb-3"
        data-test="enable-totp-qr"
        v-html="enrollment.qr_code_svg"
      />
      <!-- eslint-enable vue/no-v-html -->

      <div class="mb-3" data-test="enable-totp-manual">
        <span class="text-body-2 text-medium-emphasis">{{
          t('auth.ui.descriptions.manual_entry_label')
        }}</span>
        <code class="d-block text-body-1" data-test="enable-totp-manual-key">{{
          enrollment.manual_entry_key
        }}</code>
      </div>

      <form novalidate @submit.prevent="onConfirm">
        <v-text-field
          id="enable-totp-code"
          v-model="code"
          :label="t('auth.ui.labels.totp_code')"
          type="text"
          inputmode="numeric"
          maxlength="6"
          autocomplete="one-time-code"
          required
          data-test="enable-totp-code"
        />

        <div
          role="alert"
          aria-live="polite"
          class="text-error text-body-2 mb-2"
          data-test="enable-totp-error"
        >
          <template v-if="errorKey !== null">{{ t(errorKey, errorValues) }}</template>
        </div>

        <v-btn
          type="submit"
          color="primary"
          block
          :loading="isVerifyingTotp"
          :disabled="isVerifyingTotp"
          data-test="enable-totp-submit"
        >
          {{ isVerifyingTotp ? t('auth.ui.loading.verifying') : t('auth.ui.actions.enable_2fa') }}
        </v-btn>
      </form>
    </template>

    <template v-else-if="phase === 'enroll' && enrollment === null">
      <div
        role="alert"
        aria-live="polite"
        class="text-error text-body-2"
        data-test="enable-totp-error-fatal"
      >
        <template v-if="errorKey !== null">{{ t(errorKey, errorValues) }}</template>
      </div>
    </template>

    <RecoveryCodesDisplay
      v-else
      :codes="recoveryCodes"
      data-test="enable-totp-recovery"
      @confirmed="onCodesConfirmed"
    />
  </section>
</template>
