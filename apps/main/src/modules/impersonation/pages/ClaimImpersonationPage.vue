<script setup lang="ts">
/**
 * Impersonation hand-off landing (Sprint 13, D-9 / D-10).
 *
 * The admin SPA opens this page in a new tab with the one-time token in
 * the URL fragment (`#token=...`). The page consumes the token via the
 * UNAUTHENTICATED claim endpoint — which logs the impersonated user into
 * the `web` guard server-side — then re-bootstraps the auth store so the
 * whole SPA reflects the impersonated identity, and lands on the
 * dashboard with the persistent impersonation banner showing.
 *
 * On any failure (missing / used / expired token) it renders an inline
 * error rather than bouncing, so the admin can see exactly what happened.
 */

import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { ApiError } from '@catalyst/api-client'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { impersonationApi, readHandoffToken } from '../api/impersonation.api'
import { useImpersonationStore } from '../stores/useImpersonationStore'

const { t } = useI18n()
const router = useRouter()
const authStore = useAuthStore()
const impersonationStore = useImpersonationStore()

const status = ref<'claiming' | 'error'>('claiming')
const errorKey = ref('impersonation.claim.failed')

onMounted(async () => {
  const token = readHandoffToken(window.location.hash)
  if (token === null) {
    status.value = 'error'
    errorKey.value = 'impersonation.claim.missing_token'
    return
  }

  try {
    const res = await impersonationApi.claim(token)

    // The claim swapped the server-side `web` session to the impersonated
    // user. Re-hydrate the auth store from /me so the SPA renders as them.
    authStore.clearUser()
    impersonationStore.setActive(res.data.attributes.expires_at)
    await authStore.bootstrap()

    // Strip the token from the address bar before navigating away.
    window.history.replaceState(null, '', window.location.pathname)
    await router.replace({ name: 'app.dashboard' })
  } catch (error) {
    status.value = 'error'
    errorKey.value =
      error instanceof ApiError && error.code === 'admin.impersonation.invalid_handoff'
        ? 'impersonation.claim.invalid'
        : 'impersonation.claim.failed'
  }
})
</script>

<template>
  <div
    class="d-flex flex-column align-center justify-center pa-8"
    data-testid="impersonation-claim"
  >
    <template v-if="status === 'claiming'">
      <v-progress-circular indeterminate color="primary" size="48" class="mb-4" />
      <p class="text-body-1">{{ t('impersonation.claim.in_progress') }}</p>
    </template>

    <v-alert
      v-else
      type="error"
      variant="tonal"
      max-width="480"
      data-testid="impersonation-claim-error"
    >
      {{ t(errorKey) }}
    </v-alert>
  </div>
</template>
