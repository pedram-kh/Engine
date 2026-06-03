<script setup lang="ts">
/**
 * Public creator profile (Sprint 6.6a, D-5/D-6/D-9) — reached from a discovery
 * card. The PUBLIC shape: it carries the creator's public profile (bio,
 * country, languages, categories, social ACCOUNTS, portfolio) and the
 * calling-agency-only connection status, and it does NOT 404 when this agency
 * has no relation (D-6).
 *
 * Read-only this chunk (D-9): there is NO "Send connection request" button —
 * that, and the pending/connected action states, is Sprint 6.6b. The ONE
 * connection affordance here is a READ: when the creator is already on this
 * agency's roster, a "View in roster" link to the 2a detail.
 *
 * It deliberately carries no rating/notes editor, no contact email, no
 * availability, no admin actions — those are relation-gated surfaces (the
 * roster detail), not the public pool view.
 */

import type {
  CreatorPublicProfile,
  CreatorSocialAccountSummary,
  CreatorPortfolioItemSummary,
} from '@catalyst/api-client'
import { ApiError } from '@catalyst/api-client'
import {
  CategoryChips,
  CountryDisplay,
  LanguageList,
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

const LANGUAGE_CODES = ['en', 'pt', 'it', 'es', 'fr', 'de'] as const

const profile = ref<CreatorPublicProfile | null>(null)
const loading = ref(false)
const errorMessage = ref<string | null>(null)

const creatorUlid = computed(() => String(route.params.ulid ?? ''))
const attrs = computed(() => profile.value?.attributes ?? null)

const displayName = computed(() => attrs.value?.display_name ?? t('app.discover.unnamed'))

const countryLabel = computed(() => {
  const code = attrs.value?.country_code ?? null
  if (code === null) return ''
  return COUNTRY_OPTIONS.find((c) => c.code === code)?.label ?? code
})

function languageLabel(code: string | null): string | null {
  if (code === null) return null
  return (LANGUAGE_CODES as readonly string[]).includes(code)
    ? t(`app.roster.languages.${code}`)
    : code
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
  }))
})

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
      <!-- Header: name + connection status. NO send-request action (D-9). -->
      <header class="discover-profile__header d-flex align-start justify-space-between ga-3">
        <div class="discover-profile__header-text">
          <h1 class="text-h5 ma-0" data-test="discover-profile-name">{{ displayName }}</h1>
          <div class="d-flex flex-wrap align-center ga-2 mt-1">
            <v-chip
              v-if="attrs.is_connected && attrs.relationship_status"
              size="small"
              color="primary"
              variant="tonal"
              prepend-icon="mdi-link-variant"
              data-test="discover-profile-connected"
            >
              {{ t(`app.roster.status.${attrs.relationship_status}`) }}
            </v-chip>
            <span
              v-else
              class="text-caption text-medium-emphasis"
              data-test="discover-profile-notconnected"
            >
              {{ t('app.discover.notConnected') }}
            </span>
          </div>
        </div>

        <!-- The ONLY connection affordance this chunk: a READ link to the
             relation-gated roster detail, shown only when already connected
             (D-9). The send-request action lives in 6.6b. -->
        <v-btn
          v-if="attrs.is_connected"
          variant="tonal"
          color="primary"
          prepend-icon="mdi-account-arrow-right-outline"
          data-test="discover-profile-view-in-roster"
          @click="viewInRoster"
        >
          {{ t('app.discover.detail.viewInRoster') }}
        </v-btn>
      </header>

      <!-- Profile -->
      <section class="discover-profile__section" data-test="discover-profile-profile">
        <h2 class="text-h6">{{ t('app.discover.detail.sections.profile') }}</h2>
        <p v-if="attrs.bio" class="text-body-2" data-test="discover-profile-bio">{{ attrs.bio }}</p>
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
      </section>

      <!-- Social accounts (accounts render; metrics blocked-on-data) -->
      <section class="discover-profile__section" data-test="discover-profile-social">
        <h2 class="text-h6">{{ t('app.discover.detail.sections.social') }}</h2>
        <SocialAccountList
          :accounts="socialAccountRows"
          :empty-label="t('app.discover.detail.social.empty')"
        />
      </section>

      <!-- Portfolio -->
      <section class="discover-profile__section" data-test="discover-profile-portfolio">
        <h2 class="text-h6">{{ t('app.discover.detail.sections.portfolio') }}</h2>
        <PortfolioGallery
          :items="portfolioItems"
          :editable="false"
          :empty-label="t('app.discover.detail.portfolio.empty')"
          :video-label="t('creator.ui.wizard.steps.portfolio.video_badge_label')"
          :link-label="t('creator.ui.wizard.steps.portfolio.link_badge_label')"
        />
      </section>
    </template>
  </div>
</template>

<style scoped>
.discover-profile {
  display: flex;
  flex-direction: column;
  gap: 24px;
  max-width: 960px;
}

.discover-profile__header-text {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.discover-profile__section {
  display: flex;
  flex-direction: column;
  gap: 12px;
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
