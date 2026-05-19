<script setup lang="ts">
/**
 * Step3SocialAccountsPage — wizard Step 3 (Social accounts).
 *
 * Sprint 3 Chunk 3 sub-step 5. Connect one or more social media
 * accounts (Instagram, TikTok, YouTube). The "connection" in
 * Sprint 3 is form-based — the creator pastes a handle and the
 * profile URL is auto-derived; later sprints upgrade to OAuth.
 *
 * Decisions applied:
 *   - Decision C1: form-main (this page), display-shared
 *     ({@link SocialAccountList} from `@catalyst/ui`).
 *   - At least one social account is required to advance; the
 *     submit button is disabled until the SPA sees at least one
 *     connected account in the bootstrap state.
 *
 * Each row in the form is a per-platform group: when the creator
 * fills in a handle and clicks "Connect", the SPA POSTs to
 * `/wizard/social` and re-bootstraps. The display below the form
 * always reflects the canonical server-side state.
 */

import { SocialAccountList } from '@catalyst/ui'
import type { CreatorSocialPlatform } from '@catalyst/api-client'
import { ApiError, extractFieldErrors } from '@catalyst/api-client'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { useOnboardingStore } from '../stores/useOnboardingStore'

const { t } = useI18n()
const router = useRouter()
const store = useOnboardingStore()

/**
 * Backend field-key union (matches `ConnectSocialRequest::rules()`).
 * The `platform` key is set programmatically per-row, so a backend
 * `platform` violation is exceptional — we still surface it to the
 * row that triggered it, attached to the handle input (no separate
 * UI surface). `profile_url` is also derived programmatically from
 * `handle`, so any error there folds back onto the handle input.
 */
type SocialField = 'platform' | 'handle' | 'profile_url'

interface PlatformDraft {
  handle: string
  fieldErrors: Partial<Record<SocialField, readonly string[]>>
  errorKey: string | null
}

function emptyDraft(): PlatformDraft {
  return { handle: '', fieldErrors: {}, errorKey: null }
}

const drafts = ref<Record<CreatorSocialPlatform, PlatformDraft>>({
  instagram: emptyDraft(),
  tiktok: emptyDraft(),
  youtube: emptyDraft(),
})

const PROFILE_URL_BUILDERS: Record<CreatorSocialPlatform, (handle: string) => string> = {
  instagram: (h) => `https://instagram.com/${h.replace(/^@/, '')}`,
  tiktok: (h) => `https://tiktok.com/@${h.replace(/^@/, '')}`,
  youtube: (h) => `https://youtube.com/@${h.replace(/^@/, '')}`,
}

const connectedAccounts = computed(() => {
  const raw = store.creator?.attributes.social_accounts ?? []
  return raw.map((account) => ({
    platform: account.platform,
    handle: account.handle,
    profileUrl: account.profile_url,
    platformLabel: t(`creator.ui.wizard.social_platforms.${account.platform}`),
  }))
})

const canAdvance = computed(() => connectedAccounts.value.length > 0)

async function connectPlatform(platform: CreatorSocialPlatform): Promise<void> {
  const draft = drafts.value[platform]
  if (draft.handle.trim() === '') return

  draft.errorKey = null
  draft.fieldErrors = {}
  try {
    await store.connectSocial({
      platform,
      handle: draft.handle.trim(),
      profile_url: PROFILE_URL_BUILDERS[platform](draft.handle.trim()),
    })
    draft.handle = ''
  } catch (error) {
    if (error instanceof ApiError) {
      draft.fieldErrors = extractFieldErrors<SocialField>(error)
    }
    // Fold any `platform` or `profile_url` violations into the handle
    // surface — those fields are derived/programmatic, the creator
    // can only act on the handle. We collapse to a single line in
    // priority order to keep the per-row UI compact.
    const collapsed = [
      ...(drafts.value[platform].fieldErrors.handle ?? []),
      ...(drafts.value[platform].fieldErrors.profile_url ?? []),
      ...(drafts.value[platform].fieldErrors.platform ?? []),
    ]
    if (collapsed.length > 0) {
      drafts.value[platform].fieldErrors = { handle: collapsed }
    } else {
      // No per-field violations — fall back to the generic banner.
      draft.errorKey = 'creator.ui.errors.upload_failed'
    }
  }
}

async function advance(): Promise<void> {
  if (!canAdvance.value) return
  await router.push({ name: 'onboarding.portfolio' })
}
</script>

<template>
  <section class="social-accounts" data-testid="step-social-accounts">
    <header class="social-accounts__header">
      <h2 class="text-h5">{{ t('creator.ui.wizard.steps.social.title') }}</h2>
      <p class="text-body-2 text-medium-emphasis">
        {{ t('creator.ui.wizard.steps.social.description') }}
      </p>
    </header>

    <div class="social-accounts__connected" data-testid="social-accounts-connected">
      <SocialAccountList :accounts="connectedAccounts" />
    </div>

    <div class="social-accounts__forms">
      <div
        v-for="platform in ['instagram', 'tiktok', 'youtube'] as CreatorSocialPlatform[]"
        :key="platform"
        class="social-accounts__form-row"
        :data-testid="`social-form-${platform}`"
      >
        <span class="social-accounts__platform-label">
          {{ t(`creator.ui.wizard.social_platforms.${platform}`) }}
        </span>
        <v-text-field
          v-model="drafts[platform].handle"
          :label="t('creator.ui.wizard.fields.social_handle')"
          :data-testid="`social-handle-${platform}`"
          density="compact"
          hide-details="auto"
          :error-messages="
            drafts[platform].fieldErrors.handle ??
            (drafts[platform].errorKey === null ? undefined : t(drafts[platform].errorKey!))
          "
        />
        <v-btn
          variant="tonal"
          :loading="store.isLoadingSocial"
          :disabled="drafts[platform].handle.trim() === ''"
          :data-testid="`social-connect-${platform}`"
          @click="connectPlatform(platform)"
        >
          {{ t('creator.ui.wizard.actions.save_and_continue') }}
        </v-btn>
      </div>
    </div>

    <div class="social-accounts__actions">
      <v-btn color="primary" :disabled="!canAdvance" data-testid="social-advance" @click="advance">
        {{ t('creator.ui.wizard.actions.save_and_continue') }}
      </v-btn>
    </div>
  </section>
</template>

<style scoped>
.social-accounts {
  display: flex;
  flex-direction: column;
  gap: 20px;
  max-width: 720px;
}

.social-accounts__connected {
  padding: 12px 16px;
  border: 1px solid rgb(var(--v-theme-outline-variant, var(--v-theme-outline)));
  border-radius: 6px;
  background-color: rgb(var(--v-theme-surface-variant));
}

.social-accounts__forms {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.social-accounts__form-row {
  display: grid;
  grid-template-columns: 110px 1fr auto;
  align-items: center;
  gap: 12px;
}

.social-accounts__platform-label {
  font-weight: 500;
}

.social-accounts__actions {
  display: flex;
  justify-content: flex-end;
}
</style>
