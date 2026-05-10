<script setup lang="ts">
/**
 * Terminal error route for `bootstrapStatus === 'error'`.
 *
 * Renders a generic error message + a "Try again" button that
 * re-fires `bootstrap()` and navigates back to the originally-attempted
 * URL on success (preserved as `?attempted=...` by the requireAuth
 * guard).
 */

import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useAuthStore()

const retrying = ref(false)

async function tryAgain(): Promise<void> {
  retrying.value = true
  try {
    try {
      await store.bootstrap()
    } catch {
      // bootstrap() rethrows on the unrecoverable error path. We
      // already display "something went wrong" — no need to surface
      // a different message; the user just stays on this page and
      // can retry again.
    }
    if (store.bootstrapStatus !== 'error') {
      const attempted = typeof route.query.attempted === 'string' ? route.query.attempted : '/'
      await router.push(attempted)
    }
  } finally {
    retrying.value = false
  }
}
</script>

<template>
  <section data-test="auth-bootstrap-error-page">
    <h2 class="text-h5 mb-4" data-test="auth-bootstrap-error-heading">
      {{ t('auth.ui.headings.auth_bootstrap_error') }}
    </h2>

    <p class="text-body-1 mb-4" data-test="auth-bootstrap-error-description">
      {{ t('auth.ui.descriptions.auth_bootstrap_error') }}
    </p>

    <v-btn
      color="primary"
      block
      :loading="retrying"
      :disabled="retrying"
      data-test="auth-bootstrap-error-retry"
      @click="tryAgain"
    >
      {{ retrying ? t('auth.ui.loading.bootstrap') : t('auth.ui.actions.try_again') }}
    </v-btn>
  </section>
</template>
