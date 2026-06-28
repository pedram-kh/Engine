<script setup lang="ts">
/**
 * Public creator profile (Sprint 6.6a, D-5/D-6) — reached from a discovery
 * card. The PUBLIC shape: it carries the creator's public profile (bio,
 * country, languages, categories, social ACCOUNTS, portfolio) and the
 * calling-agency-only connection status, and it does NOT 404 when this agency
 * has no relation (D-6).
 *
 * Sprint 6.6b (D-10/D-11): the header carries the status-driven send-request
 * affordance (admin/manager only, mirroring the roster detail's canEdit role
 * pattern) + the three annotation states. The button's presence is derived
 * from the calling-agency-only relationship_status:
 *   - none      → "Send request" (W1)
 *   - pending   → "Request pending" (disabled/info)
 *   - connected → "View in roster" (the existing 2a link — keys on `roster`)
 *   - declined  → "Declined" + an explicit "Request again" (the D-4 re-request)
 *
 * It deliberately carries no rating/notes editor, no contact email, no
 * availability, no admin actions — those are relation-gated surfaces (the
 * roster detail), not the public pool view.
 */

import type {
  CreatorPublicProfile,
  CreatorSocialAccountSummary,
  CreatorPortfolioItemSummary,
  DiscoveryConnectionState,
} from '@catalyst/api-client'
import { ApiError, deriveConnectionState, languageEndonym } from '@catalyst/api-client'
import {
  CategoryChips,
  CountryDisplay,
  LanguageList,
  PortfolioDrawer,
  PortfolioGallery,
  SocialAccountList,
} from '@catalyst/ui'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { COUNTRY_OPTIONS } from '@/modules/onboarding/data/countries'

import { discoveryApi } from '../api/discovery.api'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const agencyStore = useAgencyStore()

const profile = ref<CreatorPublicProfile | null>(null)
const loading = ref(false)
const errorMessage = ref<string | null>(null)

// Send-request state (D-10). Admin/manager only — the SAME role pattern as the
// roster detail's canEdit (copied verbatim per the kickoff).
const canSend = computed(
  () => agencyStore.currentRole === 'agency_admin' || agencyStore.currentRole === 'agency_manager',
)
const sending = ref(false)
const snackbar = ref<{ color: string; text: string } | null>(null)

const creatorUlid = computed(() => String(route.params.ulid ?? ''))
const attrs = computed(() => profile.value?.attributes ?? null)

// The three annotation states (D-5/D-11), derived from the calling-agency-only
// relationship_status alone. `connected` keys on `roster` specifically.
const connectionState = computed<DiscoveryConnectionState>(() =>
  deriveConnectionState(attrs.value?.relationship_status ?? null),
)

const displayName = computed(() => attrs.value?.display_name ?? t('app.discover.unnamed'))

const countryLabel = computed(() => {
  const code = attrs.value?.country_code ?? null
  if (code === null) return ''
  return COUNTRY_OPTIONS.find((c) => c.code === code)?.label ?? code
})

function languageLabel(code: string | null): string | null {
  if (code === null) return null
  return languageEndonym(code)
}

const primaryLanguageLabel = computed(() => languageLabel(attrs.value?.primary_language ?? null))
const secondaryLanguageLabels = computed(() =>
  (attrs.value?.secondary_languages ?? [])
    .map((c) => languageLabel(c))
    .filter((l): l is string => l !== null),
)

const categoryLabels = computed(() =>
  (attrs.value?.categories ?? []).map((cat) => t(`creator.ui.wizard.categories.${cat}`, cat)),
)

const socialAccountRows = computed(() => {
  const raw: ReadonlyArray<CreatorSocialAccountSummary> = attrs.value?.social_accounts ?? []
  return raw.map((account) => ({
    platform: account.platform,
    handle: account.handle,
    profileUrl: account.profile_url,
    platformLabel: t(`creator.ui.wizard.social_platforms.${account.platform}`, account.platform),
  }))
})

const portfolioItems = computed(() => {
  const items: ReadonlyArray<CreatorPortfolioItemSummary> = attrs.value?.portfolio ?? []
  return items.map((item) => ({
    id: item.id,
    kind: item.kind,
    title: item.title,
    description: item.description,
    thumbnailUrl: item.thumbnail_view_url ?? (item.kind === 'image' ? item.view_url : null),
    viewUrl: item.view_url,
    externalUrl: item.external_url,
    altText: item.title ?? t('app.discover.detail.portfolio.untitled'),
    processingStatus: item.processing_status,
    downloadUrl: item.download_url,
  }))
})

const portfolioDrawerOpen = ref(false)

async function load(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null || creatorUlid.value === '') return

  loading.value = true
  errorMessage.value = null
  try {
    const envelope = await discoveryApi.show(agencyId, creatorUlid.value)
    profile.value = envelope.data
  } catch (error) {
    errorMessage.value =
      error instanceof ApiError && error.status === 404
        ? t('app.discover.detail.notFound')
        : t('app.discover.detail.loadFailed')
  } finally {
    loading.value = false
  }
}

function goBack(): void {
  void router.push({ name: 'discover.list' })
}

function viewInRoster(): void {
  void router.push({ name: 'roster.detail', params: { ulid: creatorUlid.value } })
}

/**
 * Send (or re-send, when declined — D-4) a connection request. Updates the
 * local relationship_status from the response so the button re-derives its
 * state without a refetch, and surfaces the outcome via a snackbar keyed on
 * the backend's meta.code.
 */
async function sendConnectionRequest(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null || creatorUlid.value === '' || sending.value || !canSend.value) return

  sending.value = true
  try {
    const res = await discoveryApi.sendConnectionRequest(agencyId, creatorUlid.value)
    if (attrs.value !== null) {
      attrs.value.relationship_status = res.data.attributes.relationship_status
    }
    snackbar.value = snackbarFor(res.meta.code)
  } catch {
    snackbar.value = { color: 'error', text: t('app.discover.connection.error') }
  } finally {
    sending.value = false
  }
}

function snackbarFor(code: string): { color: string; text: string } {
  switch (code) {
    case 'connection.requested':
      return { color: 'success', text: t('app.discover.connection.sent') }
    case 'connection.re_requested':
      return { color: 'success', text: t('app.discover.connection.reRequested') }
    case 'connection.already_connected':
      return { color: 'info', text: t('app.discover.connection.alreadyConnected') }
    case 'connection.already_requested':
    default:
      return { color: 'info', text: t('app.discover.connection.alreadyPending') }
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="discover-profile" data-test="discover-profile-page">
    <v-btn
      variant="text"
      density="comfortable"
      prepend-icon="mdi-arrow-left"
      class="mb-2 px-0"
      data-test="discover-profile-back"
      @click="goBack"
    >
      {{ t('app.discover.detail.back') }}
    </v-btn>

    <v-alert
      v-if="errorMessage"
      type="error"
      variant="tonal"
      class="mb-4"
      data-test="discover-profile-error"
    >
      {{ errorMessage }}
    </v-alert>

    <v-skeleton-loader
      v-if="loading && profile === null"
      type="article, list-item-two-line, image"
      data-test="discover-profile-skeleton"
    />

    <template v-else-if="profile !== null && attrs !== null">
      <!-- Header: name + the three connection annotation states + the
           status-driven send-request affordance (D-10/D-11). -->
      <v-card variant="outlined" class="discover-profile__header-card">
        <v-card-text class="discover-profile__header d-flex align-start justify-space-between ga-3">
          <div class="discover-profile__header-text">
            <h1 class="text-h5 ma-0" data-test="discover-profile-name">{{ displayName }}</h1>
            <div class="d-flex flex-wrap align-center ga-2 mt-1">
              <v-chip
                v-if="connectionState === 'connected'"
                size="small"
                color="primary"
                variant="tonal"
                prepend-icon="mdi-link-variant"
                data-test="discover-profile-connection-connected"
              >
                {{ t('app.discover.connection.connected') }}
              </v-chip>
              <v-chip
                v-else-if="connectionState === 'pending'"
                size="small"
                color="info"
                variant="tonal"
                prepend-icon="mdi-clock-outline"
                data-test="discover-profile-connection-pending"
              >
                {{ t('app.discover.connection.pending') }}
              </v-chip>
              <v-chip
                v-else-if="connectionState === 'declined'"
                size="small"
                variant="tonal"
                prepend-icon="mdi-close-circle-outline"
                data-test="discover-profile-connection-declined"
              >
                {{ t('app.discover.connection.declined') }}
              </v-chip>
              <span
                v-else
                class="text-caption text-medium-emphasis"
                data-test="discover-profile-notconnected"
              >
                {{ t('app.discover.connection.notConnected') }}
              </span>
            </div>
          </div>

          <!-- Status-driven action (D-10). Admin/manager only (canSend). -->
          <div class="d-flex align-center ga-2">
            <!-- connected → the existing READ link to the relation-gated roster
               detail (keys on `roster` specifically, D-5). -->
            <v-btn
              v-if="connectionState === 'connected'"
              variant="tonal"
              color="primary"
              prepend-icon="mdi-account-arrow-right-outline"
              data-test="discover-profile-view-in-roster"
              @click="viewInRoster"
            >
              {{ t('app.discover.detail.viewInRoster') }}
            </v-btn>

            <!-- pending → informational, disabled. -->
            <v-btn
              v-else-if="connectionState === 'pending'"
              variant="tonal"
              color="info"
              prepend-icon="mdi-clock-outline"
              disabled
              data-test="discover-profile-request-pending"
            >
              {{ t('app.discover.connection.pending') }}
            </v-btn>

            <!-- declined → an explicit "Request again" (D-4); admin/manager only. -->
            <v-btn
              v-else-if="connectionState === 'declined' && canSend"
              variant="flat"
              color="primary"
              prepend-icon="mdi-refresh"
              :loading="sending"
              data-test="discover-profile-request-again"
              @click="sendConnectionRequest"
            >
              {{ t('app.discover.connection.requestAgain') }}
            </v-btn>

            <!-- none → "Send request" (W1); admin/manager only. -->
            <v-btn
              v-else-if="connectionState === 'none' && canSend"
              variant="flat"
              color="primary"
              prepend-icon="mdi-account-plus-outline"
              :loading="sending"
              data-test="discover-profile-send-request"
              @click="sendConnectionRequest"
            >
              {{ t('app.discover.connection.sendRequest') }}
            </v-btn>
          </div>
        </v-card-text>
      </v-card>

      <!-- Profile -->
      <v-card variant="outlined" data-test="discover-profile-profile">
        <v-card-title class="text-h6">
          {{ t('app.discover.detail.sections.profile') }}
        </v-card-title>
        <v-card-text class="d-flex flex-column ga-3">
          <p v-if="attrs.bio" class="text-body-2 mb-0" data-test="discover-profile-bio">
            {{ attrs.bio }}
          </p>
          <div class="discover-profile__grid">
            <div>
              <span class="discover-profile__label">{{ t('app.roster.fields.country') }}</span>
              <CountryDisplay :code="attrs.country_code" :label="countryLabel" />
            </div>
            <div>
              <span class="discover-profile__label">{{ t('app.roster.fields.language') }}</span>
              <LanguageList
                :primary-label="primaryLanguageLabel"
                :secondary-labels="secondaryLanguageLabels"
              />
            </div>
            <div class="discover-profile__categories">
              <span class="discover-profile__label">{{ t('app.roster.fields.categories') }}</span>
              <CategoryChips :labels="categoryLabels" />
            </div>
          </div>
        </v-card-text>
      </v-card>

      <!-- Social accounts (accounts render; metrics blocked-on-data) -->
      <v-card variant="outlined" data-test="discover-profile-social">
        <v-card-title class="text-h6">
          {{ t('app.discover.detail.sections.social') }}
        </v-card-title>
        <v-card-text>
          <SocialAccountList
            :accounts="socialAccountRows"
            :empty-label="t('app.discover.detail.social.empty')"
          />
        </v-card-text>
      </v-card>

      <!-- Portfolio -->
      <v-card variant="outlined" data-test="discover-profile-portfolio">
        <v-card-title class="d-flex align-center justify-space-between text-h6">
          <span>{{ t('app.discover.detail.sections.portfolio') }}</span>
          <v-btn
            v-if="portfolioItems.length > 0"
            variant="text"
            size="small"
            prepend-icon="mdi-view-gallery-outline"
            data-test="discover-profile-portfolio-open-drawer"
            @click="portfolioDrawerOpen = true"
          >
            {{ t('creator.ui.wizard.steps.portfolio.view_all_label') }}
          </v-btn>
        </v-card-title>
        <v-card-text>
          <PortfolioGallery
            :items="portfolioItems"
            :editable="false"
            :empty-label="t('app.discover.detail.portfolio.empty')"
            :video-label="t('creator.ui.wizard.steps.portfolio.video_badge_label')"
            :link-label="t('creator.ui.wizard.steps.portfolio.link_badge_label')"
            :processing-label="t('creator.ui.wizard.steps.portfolio.processing_label')"
            :failed-label="t('creator.ui.wizard.steps.portfolio.failed_label')"
            :download-label="t('creator.ui.wizard.steps.portfolio.download_label')"
            :copy-link-label="t('creator.ui.wizard.steps.portfolio.copy_link_label')"
          />
        </v-card-text>
      </v-card>

      <PortfolioDrawer
        v-model="portfolioDrawerOpen"
        :items="portfolioItems"
        :title="t('app.discover.detail.sections.portfolio')"
        :empty-label="t('app.discover.detail.portfolio.empty')"
        :video-label="t('creator.ui.wizard.steps.portfolio.video_badge_label')"
        :link-label="t('creator.ui.wizard.steps.portfolio.link_badge_label')"
        :preview-label="t('creator.ui.wizard.steps.portfolio.preview_label')"
        :close-label="t('creator.ui.wizard.steps.portfolio.preview_close')"
        :processing-label="t('creator.ui.wizard.steps.portfolio.processing_label')"
        :failed-label="t('creator.ui.wizard.steps.portfolio.failed_label')"
        :download-label="t('creator.ui.wizard.steps.portfolio.download_label')"
        :copy-link-label="t('creator.ui.wizard.steps.portfolio.copy_link_label')"
      />
    </template>

    <v-snackbar
      :model-value="snackbar !== null"
      :timeout="3000"
      :color="snackbar?.color"
      data-test="discover-profile-snackbar"
      @update:model-value="
        (v) => {
          if (!v) snackbar = null
        }
      "
    >
      {{ snackbar?.text }}
    </v-snackbar>
  </div>
</template>

<style scoped>
.discover-profile {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-width: 960px;
}

.discover-profile__header-text {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.discover-profile__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
}

.discover-profile__categories {
  grid-column: 1 / -1;
}

.discover-profile__label {
  display: block;
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: rgb(var(--v-theme-on-surface-variant));
  margin-bottom: 4px;
}
</style>
