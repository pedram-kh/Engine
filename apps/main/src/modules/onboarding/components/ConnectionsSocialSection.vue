<script setup lang="ts">
/**
 * ConnectionsSocialSection — the Social-accounts sub-section of the merged
 * "Connections" wizard step (ad-hoc AH-003 D2). This is the former
 * Step3SocialAccountsPage body, extracted verbatim so the connect / update
 * / remove logic is unchanged; the page-level header and the single
 * "Continue" affordance now live on the parent {@link Step3ConnectionsPage}.
 *
 * Social and portfolio are kept as DISTINCT sub-sections — never folded
 * into each other. The "Connect" CTA reads "Add" (empty) / "Edit"
 * (already added) because the connection is form-based, not adapter-
 * connected (no metadata is fetched), so "Connect" would mislead.
 */

import { SocialAccountList } from '@catalyst/ui'
import type { CreatorSocialAccountSummary, CreatorSocialPlatform } from '@catalyst/api-client'
import { ApiError, extractFieldErrors } from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'

import { useOnboardingStore } from '../stores/useOnboardingStore'

const { t } = useI18n()
const store = useOnboardingStore()
const display = useDisplay()

// Mobile swaps the rigid 4-column desktop row for a stacked card with a
// view/edit toggle (a connected account reads as a clean card until "Edit").
const isMobile = computed(() => display.smAndDown.value)

// Per-platform "currently editing the handle" flag. Only meaningful on the
// mobile card path; the desktop row keeps its always-visible input.
const editing = ref<Record<CreatorSocialPlatform, boolean>>({
  instagram: false,
  tiktok: false,
  youtube: false,
})

const PLATFORMS: readonly CreatorSocialPlatform[] = ['instagram', 'tiktok', 'youtube']

/**
 * Allowed handle characters, mirrored from the backend
 * `ConnectSocialRequest::HANDLE_PATTERN`: 2–30 letters, digits, dot,
 * underscore or hyphen. Rejects spaces / slashes / `@` so a pasted
 * profile URL is caught client-side with a clear message.
 */
const HANDLE_RE = /^[A-Za-z0-9._-]{2,30}$/

function normalizeHandle(raw: string): string {
  return raw.trim().replace(/^@/, '')
}

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

const connectedByPlatform = computed<
  Partial<Record<CreatorSocialPlatform, CreatorSocialAccountSummary>>
>(() => {
  const out: Partial<Record<CreatorSocialPlatform, CreatorSocialAccountSummary>> = {}
  for (const account of store.creator?.attributes.social_accounts ?? []) {
    out[account.platform] = account
  }
  return out
})

const connectedAccounts = computed(() => {
  const raw = store.creator?.attributes.social_accounts ?? []
  return raw.map((account) => ({
    platform: account.platform,
    handle: account.handle,
    profileUrl: account.profile_url,
    platformLabel: t(`creator.ui.wizard.social_platforms.${account.platform}`),
  }))
})

function isConnected(platform: CreatorSocialPlatform): boolean {
  return connectedByPlatform.value[platform] !== undefined
}

/** Client-side validity for a row's handle (empty is "not yet valid"). */
function isHandleValid(platform: CreatorSocialPlatform): boolean {
  return HANDLE_RE.test(normalizeHandle(drafts.value[platform].handle))
}

/**
 * Pre-fill each row with its connected handle (edit affordance) and clear
 * transient error state. Called on mount and after every successful
 * connect/update/remove so the inputs always reflect server truth.
 */
function syncDraftsFromServer(): void {
  for (const platform of PLATFORMS) {
    drafts.value[platform].handle = connectedByPlatform.value[platform]?.handle ?? ''
    drafts.value[platform].fieldErrors = {}
    drafts.value[platform].errorKey = null
  }
}

onMounted(syncDraftsFromServer)

async function connectPlatform(platform: CreatorSocialPlatform): Promise<void> {
  const draft = drafts.value[platform]
  const handle = normalizeHandle(draft.handle)
  if (handle === '' || !HANDLE_RE.test(handle)) return

  draft.errorKey = null
  draft.fieldErrors = {}
  try {
    await store.connectSocial({
      platform,
      handle,
      profile_url: PROFILE_URL_BUILDERS[platform](handle),
    })
    syncDraftsFromServer()
  } catch (error) {
    if (error instanceof ApiError) {
      draft.fieldErrors = extractFieldErrors<SocialField>(error)
    }
    const collapsed = [
      ...(drafts.value[platform].fieldErrors.handle ?? []),
      ...(drafts.value[platform].fieldErrors.profile_url ?? []),
      ...(drafts.value[platform].fieldErrors.platform ?? []),
    ]
    if (collapsed.length > 0) {
      drafts.value[platform].fieldErrors = { handle: collapsed }
    } else {
      draft.errorKey = 'creator.ui.errors.upload_failed'
    }
  }
}

async function removePlatform(platform: CreatorSocialPlatform): Promise<void> {
  const draft = drafts.value[platform]
  draft.errorKey = null
  draft.fieldErrors = {}
  try {
    await store.disconnectSocial(platform)
    syncDraftsFromServer()
  } catch (error) {
    if (error instanceof ApiError) {
      draft.fieldErrors = extractFieldErrors<SocialField>(error)
    }
    if ((draft.fieldErrors.handle ?? []).length === 0) {
      draft.errorKey = 'creator.ui.errors.upload_failed'
    }
  }
}

/* ---- mobile card view/edit toggle ---- */
function startEdit(platform: CreatorSocialPlatform): void {
  drafts.value[platform].handle = connectedByPlatform.value[platform]?.handle ?? ''
  drafts.value[platform].fieldErrors = {}
  drafts.value[platform].errorKey = null
  editing.value[platform] = true
}

function cancelEdit(platform: CreatorSocialPlatform): void {
  drafts.value[platform].handle = connectedByPlatform.value[platform]?.handle ?? ''
  drafts.value[platform].fieldErrors = {}
  drafts.value[platform].errorKey = null
  editing.value[platform] = false
}

/** Save from the mobile edit state: run the shared connect/update, then leave
 *  edit mode only if it succeeded (a 422 keeps the input open with its error). */
async function saveEdit(platform: CreatorSocialPlatform): Promise<void> {
  await connectPlatform(platform)
  const draft = drafts.value[platform]
  const hasError =
    draft.errorKey !== null ||
    Object.values(draft.fieldErrors).some((messages) => (messages?.length ?? 0) > 0)
  if (!hasError) editing.value[platform] = false
}
</script>

<template>
  <section class="social-accounts" data-testid="step-social-accounts">
    <header class="social-accounts__header">
      <h3 class="text-subtitle-1">{{ t('creator.ui.wizard.steps.social.title') }}</h3>
      <p class="text-body-2 text-medium-emphasis">
        {{ t('creator.ui.wizard.steps.social.description') }}
      </p>
    </header>

    <div class="social-accounts__connected" data-testid="social-accounts-connected">
      <SocialAccountList :accounts="connectedAccounts" />
    </div>

    <p class="text-caption text-medium-emphasis social-accounts__hint">
      {{ t('creator.ui.wizard.fields.social_handle_hint') }}
    </p>

    <div class="social-accounts__forms">
      <template v-for="platform in PLATFORMS" :key="platform">
        <!-- Desktop: the original 4-column row (unchanged). -->
        <div
          v-if="!isMobile"
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
            :rules="[
              (v: string) =>
                normalizeHandle(v) === '' ||
                HANDLE_RE.test(normalizeHandle(v)) ||
                t('creator.ui.wizard.fields.social_handle_invalid'),
            ]"
            :error-messages="
              drafts[platform].fieldErrors.handle ??
              (drafts[platform].errorKey === null ? undefined : t(drafts[platform].errorKey!))
            "
          />
          <v-btn
            variant="tonal"
            :loading="store.isLoadingSocial"
            :disabled="drafts[platform].handle.trim() === '' || !isHandleValid(platform)"
            :data-testid="`social-connect-${platform}`"
            @click="connectPlatform(platform)"
          >
            {{
              isConnected(platform)
                ? t('creator.ui.wizard.actions.edit')
                : t('creator.ui.wizard.actions.connect')
            }}
          </v-btn>
          <v-btn
            v-if="isConnected(platform)"
            variant="outlined"
            color="error"
            :loading="store.isLoadingSocial"
            :data-testid="`social-remove-${platform}`"
            @click="removePlatform(platform)"
          >
            {{ t('creator.ui.wizard.actions.remove') }}
          </v-btn>
        </div>

        <!-- Mobile: stacked card with a view/edit toggle. -->
        <div v-else class="social-card" :data-testid="`social-form-${platform}`">
          <!-- Connected, view mode: read-only card like the review step. -->
          <template v-if="isConnected(platform) && !editing[platform]">
            <div class="social-card__head">
              <span class="social-card__platform">
                {{ t(`creator.ui.wizard.social_platforms.${platform}`) }}
              </span>
              <span class="social-card__handle">@{{ connectedByPlatform[platform]?.handle }}</span>
            </div>
            <div class="social-card__actions">
              <v-btn
                variant="tonal"
                size="small"
                :data-testid="`social-edit-${platform}`"
                @click="startEdit(platform)"
              >
                {{ t('creator.ui.wizard.actions.edit') }}
              </v-btn>
              <v-btn
                variant="outlined"
                color="error"
                size="small"
                :loading="store.isLoadingSocial"
                :data-testid="`social-remove-${platform}`"
                @click="removePlatform(platform)"
              >
                {{ t('creator.ui.wizard.actions.remove') }}
              </v-btn>
            </div>
          </template>

          <!-- Empty platform, or editing a connected one: show the input. -->
          <template v-else>
            <span class="social-card__platform">
              {{ t(`creator.ui.wizard.social_platforms.${platform}`) }}
            </span>
            <v-text-field
              v-model="drafts[platform].handle"
              :label="t('creator.ui.wizard.fields.social_handle')"
              :data-testid="`social-handle-${platform}`"
              density="compact"
              hide-details="auto"
              :rules="[
                (v: string) =>
                  normalizeHandle(v) === '' ||
                  HANDLE_RE.test(normalizeHandle(v)) ||
                  t('creator.ui.wizard.fields.social_handle_invalid'),
              ]"
              :error-messages="
                drafts[platform].fieldErrors.handle ??
                (drafts[platform].errorKey === null ? undefined : t(drafts[platform].errorKey!))
              "
            />
            <div class="social-card__actions">
              <v-btn
                variant="tonal"
                size="small"
                :loading="store.isLoadingSocial"
                :disabled="drafts[platform].handle.trim() === '' || !isHandleValid(platform)"
                :data-testid="`social-connect-${platform}`"
                @click="editing[platform] ? saveEdit(platform) : connectPlatform(platform)"
              >
                {{
                  editing[platform]
                    ? t('creator.ui.wizard.actions.save')
                    : t('creator.ui.wizard.actions.connect')
                }}
              </v-btn>
              <v-btn
                v-if="editing[platform]"
                variant="text"
                size="small"
                :data-testid="`social-cancel-${platform}`"
                @click="cancelEdit(platform)"
              >
                {{ t('creator.ui.wizard.actions.cancel') }}
              </v-btn>
            </div>
          </template>
        </div>
      </template>
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
  grid-template-columns: 110px 1fr auto auto;
  align-items: start;
  gap: 12px;
}

.social-accounts__hint {
  margin-top: -8px;
}

.social-accounts__platform-label {
  font-weight: 500;
}

/* ---- mobile card (view/edit toggle) ---- */
.social-card {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 12px;
  border: 1px solid rgb(var(--v-theme-outline-variant, var(--v-theme-outline)));
  border-radius: 6px;
}

.social-card__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}

.social-card__platform {
  font-weight: 500;
}

.social-card__handle {
  color: rgb(var(--v-theme-on-surface-variant));
  font-size: 0.875rem;
  text-align: right;
  overflow-wrap: anywhere;
  min-width: 0;
}

.social-card__actions {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 8px;
}
</style>
