<script setup lang="ts">
/**
 * AcceptCreatorInvitePage — the creator-side magic-link landing page
 * (Sprint 3 Chunk 4 sub-step 4 — Q-pause-PC3 = (α)).
 *
 *   URL: /auth/accept-invite?token=<unhashed>
 *
 * Separate from the agency-user invitation flow (/accept-invitation):
 * this page handles the bulk-invite path that ships prospect creators
 * into the wizard. The preview endpoint deliberately does NOT expose
 * the invited email (#42 user-enumeration defence) — so we cannot
 * pre-fill the sign-up form. The "hard-lock" from Decision C2=a
 * degrades to a post-submit gate on /sign-up (the SignUpService
 * compares the typed email to the bound user at acceptance time and
 * returns invitation.email_mismatch on mismatch).
 *
 * 5-state UI per Decision C1=a:
 *   loading         — preview in flight (skeleton).
 *   valid-pending   — preview valid + pending → Continue to sign-up CTA.
 *   already-accepted — preview returns is_accepted=true → Sign in CTA.
 *   expired         — preview returns is_expired=true → contact agency.
 *   invalid         — 404 (generic per #42) or no token in URL.
 *
 * Forward navigation: clicking "Continue to create your account" pushes
 * to /sign-up?token=<token>. The sign-up page reads the query param
 * and forwards it as `invitation_token` in the POST. No state passing
 * across pages, no cookie — just the token in the URL.
 */

import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import {
  previewCreatorInvitation,
  type CreatorInvitationPreviewState,
} from '@/modules/auth/api/creator-invitations.api'

type PageState = 'loading' | CreatorInvitationPreviewState['kind']

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const state = ref<PageState>('loading')
const agencyName = ref<string | null>(null)

const token = computed(() => {
  const raw = route.query.token
  return typeof raw === 'string' && raw.trim() !== '' ? raw : null
})

async function loadPreview(): Promise<void> {
  if (token.value === null) {
    state.value = 'invalid'
    return
  }
  const result = await previewCreatorInvitation(token.value)
  state.value = result.kind
  if (result.kind !== 'invalid') {
    agencyName.value = result.agencyName
  }
}

async function onContinue(): Promise<void> {
  if (token.value === null) return
  await router.push({ name: 'auth.sign-up', query: { token: token.value } })
}

async function onSignIn(): Promise<void> {
  await router.push({ name: 'auth.sign-in' })
}

onMounted(() => {
  void loadPreview()
})
</script>

<template>
  <section
    class="accept-creator-invite d-flex flex-column align-center justify-center"
    data-test="accept-creator-invite-page"
  >
    <v-card
      v-if="state === 'loading'"
      class="pa-8 text-center"
      max-width="480"
      data-test="accept-creator-invite-loading"
    >
      <v-progress-circular indeterminate aria-hidden="true" />
      <div role="status" aria-live="polite" class="mt-4 text-body-1">
        {{ t('auth.creator_invitation.loading') }}
      </div>
    </v-card>

    <v-card
      v-else-if="state === 'valid-pending'"
      class="pa-8 text-center"
      max-width="520"
      data-test="accept-creator-invite-valid-pending"
    >
      <h2 class="text-h5 mb-4">{{ t('auth.creator_invitation.headings.valid_pending') }}</h2>
      <p class="text-body-1 mb-6">
        {{ t('auth.creator_invitation.descriptions.valid_pending', { agency: agencyName }) }}
      </p>
      <v-btn color="primary" block data-test="accept-creator-invite-continue" @click="onContinue">
        {{ t('auth.creator_invitation.actions.continue_to_sign_up') }}
      </v-btn>
    </v-card>

    <v-card
      v-else-if="state === 'already-accepted'"
      class="pa-8 text-center"
      max-width="520"
      data-test="accept-creator-invite-already-accepted"
    >
      <h2 class="text-h5 mb-4">{{ t('auth.creator_invitation.headings.already_accepted') }}</h2>
      <p class="text-body-1 mb-6">
        {{ t('auth.creator_invitation.descriptions.already_accepted') }}
      </p>
      <v-btn color="primary" block data-test="accept-creator-invite-sign-in" @click="onSignIn">
        {{ t('auth.creator_invitation.actions.sign_in') }}
      </v-btn>
    </v-card>

    <v-card
      v-else-if="state === 'expired'"
      class="pa-8 text-center"
      max-width="520"
      data-test="accept-creator-invite-expired"
    >
      <h2 class="text-h5 mb-4">{{ t('auth.creator_invitation.headings.expired') }}</h2>
      <p class="text-body-1 mb-6">
        {{ t('auth.creator_invitation.descriptions.expired', { agency: agencyName }) }}
      </p>
    </v-card>

    <v-card
      v-else
      class="pa-8 text-center"
      max-width="520"
      data-test="accept-creator-invite-invalid"
    >
      <h2 class="text-h5 mb-4">{{ t('auth.creator_invitation.headings.invalid') }}</h2>
      <p class="text-body-1 mb-6">
        {{ t('auth.creator_invitation.descriptions.invalid') }}
      </p>
    </v-card>
  </section>
</template>

<style scoped>
.accept-creator-invite {
  min-height: 60vh;
  padding: 2rem 1rem;
}
</style>
