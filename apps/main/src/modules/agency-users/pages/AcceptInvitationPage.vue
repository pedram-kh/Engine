<script setup lang="ts">
/**
 * Accept invitation page — handles all 4 invitation states.
 *
 * URL: /accept-invitation?token=<unhashed>&agency=<agency_ulid>
 *
 * Q3 answer: Option B — the accept endpoint requires auth (auth:web);
 * if unauthenticated, show sign-in CTA with redirect preserved.
 *
 * State machine:
 *   loading    → previewing the invitation (unauthenticated preview endpoint)
 *   expired    → "This invitation has expired" + contact admin msg
 *   accepted   → "Already accepted" + sign-in CTA
 *   pending    →  authenticated: "Accept" button → POST accept
 *             → unauthenticated: "Sign in to accept" CTA
 *   success    → "Welcome!" message
 *   error      → generic error state
 *
 * Note: invitation.expired_on_attempt (410 from accept endpoint) is
 * surfaced as distinct from the preview "is_expired: true" path —
 * both show the expired message but the 410 is logged in audit trail.
 */

import { ApiError } from '@catalyst/api-client'
import type { InvitationPreview } from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { invitationsApi } from '../api/invitations.api'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const { user } = storeToRefs(authStore)

type PageState =
  | 'loading'
  | 'expired'
  | 'already-accepted'
  | 'pending'
  | 'not-authenticated'
  | 'email-mismatch'
  | 'already-member'
  | 'not-found'
  | 'success'
  | 'error'

const state = ref<PageState>('loading')
const preview = ref<InvitationPreview | null>(null)
const accepting = ref(false)

const token = computed(() => {
  const t = route.query.token
  return typeof t === 'string' ? t : null
})

const agencyId = computed(() => {
  const a = route.query.agency
  return typeof a === 'string' ? a : null
})

const redirectParam = computed(() =>
  encodeURIComponent(
    `/accept-invitation?token=${token.value ?? ''}&agency=${agencyId.value ?? ''}`,
  ),
)

const isAuthenticated = computed(() => user.value !== null)

async function loadPreview(): Promise<void> {
  const t = token.value
  const a = agencyId.value

  if (!t || !a) {
    state.value = 'not-found'
    return
  }

  // Bootstrap to detect session state. The accept page has no requireAuth
  // guard (it must be reachable unauthenticated), so bootstrap is never
  // triggered by the router. Without this call, a fresh page.goto() to the
  // accept URL leaves authStore.user null even when a session cookie exists.
  await authStore.bootstrap()

  try {
    const res = await invitationsApi.preview(a, t)
    preview.value = res.data

    if (res.data.is_expired) {
      state.value = 'expired'
    } else if (res.data.is_accepted) {
      state.value = 'already-accepted'
    } else if (!isAuthenticated.value) {
      state.value = 'not-authenticated'
    } else {
      state.value = 'pending'
    }
  } catch (err) {
    if (err instanceof ApiError && err.status === 404) {
      state.value = 'not-found'
    } else {
      state.value = 'error'
    }
  }
}

async function acceptInvitation(): Promise<void> {
  const t = token.value
  const a = agencyId.value
  if (!t || !a) return

  accepting.value = true
  try {
    await invitationsApi.accept(a, { token: t })
    state.value = 'success'
    // Re-bootstrap the auth store so agency memberships refresh.
    authStore.bootstrapStatus = 'idle'
    await authStore.bootstrap()
    // Redirect home after a brief moment.
    setTimeout(() => {
      void router.push({ name: 'app.dashboard' })
    }, 2000)
  } catch (err) {
    if (err instanceof ApiError) {
      if (err.status === 410 || err.code === 'invitation.expired') {
        state.value = 'expired'
      } else if (err.code === 'invitation.already_accepted') {
        state.value = 'already-accepted'
      } else if (err.code === 'invitation.email_mismatch') {
        state.value = 'email-mismatch'
      } else if (err.code === 'invitation.already_member') {
        state.value = 'already-member'
      } else if (err.status === 404) {
        state.value = 'not-found'
      } else {
        state.value = 'error'
      }
    } else {
      state.value = 'error'
    }
  } finally {
    accepting.value = false
  }
}

onMounted(loadPreview)
</script>

<template>
  <section
    class="accept-invitation d-flex flex-column align-center justify-center"
    data-test="accept-invitation-page"
  >
    <!-- Loading -->
    <v-card v-if="state === 'loading'" class="pa-8" min-width="380">
      <v-skeleton-loader type="article" data-test="accept-invitation-skeleton" />
    </v-card>

    <!-- Expired -->
    <v-card
      v-else-if="state === 'expired'"
      class="pa-8 text-center"
      max-width="480"
      data-test="accept-invitation-expired"
    >
      <v-icon icon="mdi-clock-alert-outline" size="64" color="warning" class="mb-4" />
      <h2 class="text-h5 mb-2">{{ t('app.invitation.accept.expired.heading') }}</h2>
      <p class="text-body-2 text-medium-emphasis">{{ t('app.invitation.accept.expired.body') }}</p>
    </v-card>

    <!-- Already accepted -->
    <v-card
      v-else-if="state === 'already-accepted'"
      class="pa-8 text-center"
      max-width="480"
      data-test="accept-invitation-already-accepted"
    >
      <v-icon icon="mdi-check-circle-outline" size="64" color="success" class="mb-4" />
      <h2 class="text-h5 mb-2">{{ t('app.invitation.accept.alreadyAccepted.heading') }}</h2>
      <p class="text-body-2 text-medium-emphasis mb-6">
        {{ t('app.invitation.accept.alreadyAccepted.body') }}
      </p>
      <v-btn color="primary" :to="{ name: 'auth.sign-in' }" data-test="already-accepted-sign-in">
        {{ t('app.invitation.accept.alreadyAccepted.signIn') }}
      </v-btn>
    </v-card>

    <!-- Not authenticated — Q3: Option B redirect to sign-in -->
    <v-card
      v-else-if="state === 'not-authenticated'"
      class="pa-8 text-center"
      max-width="480"
      data-test="accept-invitation-unauthenticated"
    >
      <v-icon icon="mdi-login-variant" size="64" color="primary" class="mb-4" />
      <h2 class="text-h5 mb-2">{{ t('app.invitation.accept.title') }}</h2>
      <p v-if="preview" class="text-body-2 mb-6">
        {{
          t('app.invitation.accept.joining', {
            agencyName: preview.agency_name,
            role: t(`app.agencyUsers.roles.${preview.role}`),
          })
        }}
      </p>
      <p class="text-body-2 text-medium-emphasis mb-4">
        {{ t('app.invitation.accept.signInToContinue') }}
      </p>
      <v-btn
        color="primary"
        block
        class="mb-3"
        :to="{ name: 'auth.sign-in', query: { redirect: redirectParam } }"
        data-test="accept-sign-in-btn"
      >
        {{ t('app.invitation.accept.signIn') }}
      </v-btn>
      <p class="text-body-2 text-medium-emphasis">
        {{ t('app.invitation.accept.noAccount') }}
        <router-link :to="{ name: 'auth.sign-up' }" data-test="accept-sign-up-link">
          {{ t('app.invitation.accept.signUp') }}
        </router-link>
      </p>
    </v-card>

    <!-- Pending — authenticated user can accept -->
    <v-card
      v-else-if="state === 'pending'"
      class="pa-8 text-center"
      max-width="480"
      data-test="accept-invitation-pending"
    >
      <v-icon icon="mdi-email-open-outline" size="64" color="primary" class="mb-4" />
      <h2 class="text-h5 mb-2">{{ t('app.invitation.accept.title') }}</h2>
      <p v-if="preview" class="text-body-1 mb-6" data-test="accept-invitation-description">
        {{
          t('app.invitation.accept.joining', {
            agencyName: preview.agency_name,
            role: t(`app.agencyUsers.roles.${preview.role}`),
          })
        }}
      </p>
      <v-btn
        color="primary"
        size="large"
        block
        :loading="accepting"
        :disabled="accepting"
        data-test="accept-invitation-btn"
        @click="acceptInvitation"
      >
        {{ accepting ? t('app.invitation.accept.accepting') : t('app.invitation.accept.accept') }}
      </v-btn>
    </v-card>

    <!-- Email mismatch -->
    <v-card
      v-else-if="state === 'email-mismatch'"
      class="pa-8 text-center"
      max-width="480"
      data-test="accept-invitation-email-mismatch"
    >
      <v-icon icon="mdi-account-alert-outline" size="64" color="error" class="mb-4" />
      <h2 class="text-h5 mb-2">{{ t('app.invitation.accept.emailMismatch.heading') }}</h2>
      <p class="text-body-2 text-medium-emphasis">
        {{ t('app.invitation.accept.emailMismatch.body') }}
      </p>
    </v-card>

    <!-- Already a member -->
    <v-card
      v-else-if="state === 'already-member'"
      class="pa-8 text-center"
      max-width="480"
      data-test="accept-invitation-already-member"
    >
      <v-icon icon="mdi-account-check-outline" size="64" color="success" class="mb-4" />
      <h2 class="text-h5 mb-2">{{ t('app.invitation.accept.alreadyMember.heading') }}</h2>
      <p class="text-body-2 text-medium-emphasis">
        {{ t('app.invitation.accept.alreadyMember.body') }}
      </p>
    </v-card>

    <!-- Not found -->
    <v-card
      v-else-if="state === 'not-found'"
      class="pa-8 text-center"
      max-width="480"
      data-test="accept-invitation-not-found"
    >
      <v-icon icon="mdi-link-off" size="64" color="error" class="mb-4" />
      <h2 class="text-h5 mb-2">{{ t('app.invitation.accept.notFound.heading') }}</h2>
      <p class="text-body-2 text-medium-emphasis">{{ t('app.invitation.accept.notFound.body') }}</p>
    </v-card>

    <!-- Success -->
    <v-card
      v-else-if="state === 'success'"
      class="pa-8 text-center"
      max-width="480"
      data-test="accept-invitation-success"
    >
      <v-icon icon="mdi-party-popper" size="64" color="success" class="mb-4" />
      <h2 class="text-h5 mb-2" data-test="accept-invitation-success-msg">
        <template v-if="preview">
          {{
            t('app.invitation.accept.success', {
              agencyName: preview.agency_name,
              role: t(`app.agencyUsers.roles.${preview.role}`),
            })
          }}
        </template>
      </h2>
    </v-card>

    <!-- Generic error -->
    <v-card v-else class="pa-8 text-center" max-width="480" data-test="accept-invitation-error">
      <v-icon icon="mdi-alert-circle-outline" size="64" color="error" class="mb-4" />
      <h2 class="text-h5 mb-2">Something went wrong</h2>
      <p class="text-body-2 text-medium-emphasis">{{ t('app.invitation.accept.errors.failed') }}</p>
    </v-card>
  </section>
</template>

<style scoped>
.accept-invitation {
  min-height: calc(100vh - 64px);
  padding: 24px;
}
</style>
