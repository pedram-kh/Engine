<script setup lang="ts">
/**
 * Agency-side per-creator DETAIL view (Sprint 6 Chunk 2a, F1).
 *
 * Reached by clicking a roster row (the D-c5-4 reversal). Re-composes the same
 * SPA-agnostic `@catalyst/ui` primitives the admin `CreatorDetailPage` uses
 * (CategoryChips / CountryDisplay / LanguageList / SocialAccountList /
 * PortfolioGallery) — but it is a DIFFERENT page (D-2a-7): NO admin actions
 * (approve/reject/verify), NO per-field edit, NO completeness bar, NO
 * admin_attributes. It adds the agency-private rating/notes EDITOR the roster
 * shipped read-only, and consumes the Sprint-5 agency availability endpoint.
 *
 * Editing is gated to admin/manager (D-2a-4) in the UI; the backend is the SOT
 * (a staff PATCH 403s regardless). Two sections are data-blocked and render
 * honest empty states (D-2a-10): social METRICS (followers/engagement — null
 * until adapters land) and campaign HISTORY (Sprint 8). Social ACCOUNTS DO
 * render — it's the metrics that are empty.
 *
 * The creator's contact email is surfaced (D-2a-8): the agency holds a
 * verified relation with this creator, so the contact email belongs here.
 */

import type {
  AgencyCreatorDetailResource,
  CreatorPortfolioItemSummary,
  CreatorSocialAccountSummary,
} from '@catalyst/api-client'
import { ApiError, languageEndonym } from '@catalyst/api-client'
import {
  BlacklistBadge,
  CategoryChips,
  CEmptyState,
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

import AddToPoolDialog from '@/modules/pools/components/AddToPoolDialog.vue'

import { rosterApi } from '../api/roster.api'
import AgencyAvailabilityCalendar from '../components/AgencyAvailabilityCalendar.vue'
import BlacklistCreatorDialog from '../components/BlacklistCreatorDialog.vue'
import StarRatingInput from '../components/StarRatingInput.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const agencyStore = useAgencyStore()

const detail = ref<AgencyCreatorDetailResource | null>(null)
const loading = ref(false)
const errorMessage = ref<string | null>(null)

const creatorUlid = computed(() => String(route.params.ulid ?? ''))

// Editing is admin/manager (D-2a-4). Staff sees rating/notes read-only.
const canEdit = computed(
  () => agencyStore.currentRole === 'agency_admin' || agencyStore.currentRole === 'agency_manager',
)

const attrs = computed(() => detail.value?.attributes ?? null)
const creator = computed(() => detail.value?.attributes.creator ?? null)

const displayName = computed(() => creator.value?.display_name ?? t('app.roster.detail.unnamed'))
const email = computed(() => creator.value?.email ?? null)

// AH-010b — the "Message" entry point (D9). Mirror the backend
// `canMessageRelationship` gate (approved creator + roster + non-blacklisted) so
// we never surface a shortcut that would 403; the backend stays the SOT.
const canMessage = computed(
  () =>
    attrs.value?.relationship_status === 'roster' &&
    attrs.value?.is_blacklisted !== true &&
    creator.value?.application_status === 'approved',
)

// AH-005 — optional contact details. The server gates the whole block by
// omission (present only when this agency's relation is non-blacklisted), so
// a blacklisted-but-rostered agency receives no keys and the card stays hidden.
const phone = computed(() => creator.value?.phone ?? null)
const whatsapp = computed(() => creator.value?.whatsapp ?? null)
const addressStreet = computed(() => creator.value?.address_street ?? null)
const addressPostalCode = computed(() => creator.value?.address_postal_code ?? null)

// Mailing address composes from street + "postal_code region (city)" + country.
const mailingAddressLines = computed<string[]>(() => {
  const c = creator.value
  if (c === null) return []
  const cityLine = [c.address_postal_code, c.region].filter((p): p is string => !!p).join(' ')
  return [c.address_street ?? '', cityLine, countryLabel.value].filter((line) => line !== '')
})

const hasMailingAddress = computed(
  () => addressStreet.value !== null || addressPostalCode.value !== null,
)
const hasContactDetails = computed(
  () => phone.value !== null || whatsapp.value !== null || hasMailingAddress.value,
)

const countryLabel = computed(() => {
  const code = creator.value?.country_code ?? null
  if (code === null) return ''
  return COUNTRY_OPTIONS.find((c) => c.code === code)?.label ?? code
})

function languageLabel(code: string | null): string | null {
  if (code === null) return null
  return languageEndonym(code)
}

const primaryLanguageLabel = computed(() => languageLabel(creator.value?.primary_language ?? null))
const secondaryLanguageLabels = computed(() =>
  (creator.value?.secondary_languages ?? [])
    .map((c) => languageLabel(c))
    .filter((l): l is string => l !== null),
)

const categoryLabels = computed(() =>
  (creator.value?.categories ?? []).map((cat) => t(`creator.ui.wizard.categories.${cat}`, cat)),
)

const socialAccountRows = computed(() => {
  const raw: ReadonlyArray<CreatorSocialAccountSummary> = creator.value?.social_accounts ?? []
  return raw.map((account) => ({
    platform: account.platform,
    handle: account.handle,
    profileUrl: account.profile_url,
    platformLabel: t(`creator.ui.wizard.social_platforms.${account.platform}`, account.platform),
  }))
})

const portfolioItems = computed(() => {
  const items: ReadonlyArray<CreatorPortfolioItemSummary> = creator.value?.portfolio ?? []
  return items.map((item) => ({
    id: item.id,
    kind: item.kind,
    title: item.title,
    description: item.description,
    thumbnailUrl: item.thumbnail_view_url ?? (item.kind === 'image' ? item.view_url : null),
    viewUrl: item.view_url,
    externalUrl: item.external_url,
    altText: item.title ?? t('app.roster.detail.portfolio.untitled'),
    processingStatus: item.processing_status,
    downloadUrl: item.download_url,
  }))
})

// Portfolio renders as a collapsible drawer (collapsed by default): the
// card header carries a down-chevron that expands the grid in place.
const portfolioExpanded = ref(false)

// ── Rating/notes editor state ────────────────────────────────────────────────
const ratingDraft = ref<number | null>(null)
const notesDraft = ref<string>('')
const saving = ref(false)
const saveError = ref<string | null>(null)
const savedSnackbar = ref(false)

// Add-to-pool picker (Sprint 6 Chunk 2b, D-2b-9). Gated by the same canEdit
// (admin/manager) computed; the dialog reflects + toggles this creator's pool
// membership.
const poolDialog = ref(false)
const poolSnackbar = ref<string | null>(null)

function onPoolChanged(message: string): void {
  poolSnackbar.value = message
}

// ── Blacklist state (Sprint 7, A7) ───────────────────────────────────────────
// The 2a detail surfaces AGENCY-WIDE blacklist status only (the relation's
// columns — D-2). Brand-scoped blacklists live in their own table and never
// touch the relation, so they are NOT reflected here; the dialog can still
// CREATE one. The detail always has a relation (the page 404s otherwise).
const blacklistDialog = ref(false)
const unblacklisting = ref(false)
const blacklistSnackbar = ref<string | null>(null)
const blacklistError = ref<string | null>(null)

const isBlacklisted = computed(() => attrs.value?.is_blacklisted ?? false)
const blacklistType = computed(() => attrs.value?.blacklist_type ?? 'hard')

function blacklistedDateLabel(): string | null {
  const at = attrs.value?.blacklisted_at ?? null
  return at === null ? null : new Date(at).toLocaleDateString()
}

function onBlacklisted(message: string): void {
  blacklistSnackbar.value = message
  void load()
}

async function unblacklist(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  unblacklisting.value = true
  blacklistError.value = null
  try {
    // The relation only ever carries an AGENCY-WIDE blacklist (D-2).
    await rosterApi.unblacklist(agencyId, creatorUlid.value, { scope: 'agency' })
    blacklistSnackbar.value = t('app.roster.blacklist.lifted')
    await load()
  } catch {
    blacklistError.value = t('app.roster.blacklist.liftFailed')
  } finally {
    unblacklisting.value = false
  }
}

const isDirty = computed(() => {
  if (attrs.value === null) return false
  const currentNotes = attrs.value.internal_notes ?? ''
  return ratingDraft.value !== attrs.value.internal_rating || notesDraft.value !== currentNotes
})

function seedDrafts(): void {
  if (attrs.value === null) return
  ratingDraft.value = attrs.value.internal_rating
  notesDraft.value = attrs.value.internal_notes ?? ''
}

async function load(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null || creatorUlid.value === '') return

  loading.value = true
  errorMessage.value = null
  try {
    const envelope = await rosterApi.show(agencyId, creatorUlid.value)
    detail.value = envelope.data
    seedDrafts()
  } catch (error) {
    errorMessage.value =
      error instanceof ApiError && error.status === 404
        ? t('app.roster.detail.notFound')
        : t('app.roster.detail.loadFailed')
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null || detail.value === null) return

  saving.value = true
  saveError.value = null
  try {
    const envelope = await rosterApi.updateRelation(agencyId, creatorUlid.value, {
      internal_rating: ratingDraft.value,
      internal_notes: notesDraft.value === '' ? null : notesDraft.value,
    })
    detail.value = envelope.data
    seedDrafts()
    savedSnackbar.value = true
  } catch {
    saveError.value = t('app.roster.detail.editor.saveFailed')
  } finally {
    saving.value = false
  }
}

function goBack(): void {
  void router.push({ name: 'roster.list' })
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="creator-detail" data-test="creator-detail-page">
    <v-btn
      variant="text"
      density="comfortable"
      prepend-icon="mdi-arrow-left"
      class="mb-2 px-0"
      data-test="creator-detail-back"
      @click="goBack"
    >
      {{ t('app.roster.detail.back') }}
    </v-btn>

    <v-alert
      v-if="errorMessage"
      type="error"
      variant="tonal"
      class="mb-4"
      data-test="creator-detail-error"
    >
      {{ errorMessage }}
    </v-alert>

    <v-skeleton-loader
      v-if="loading && detail === null"
      type="article, list-item-two-line, image"
      data-test="creator-detail-skeleton"
    />

    <template v-else-if="detail !== null && attrs !== null && creator !== null">
      <!-- Header: name + contact email + status chips (D-2a-8) -->
      <v-card variant="outlined" class="creator-detail__header-card">
        <v-card-text class="creator-detail__header d-flex align-start justify-space-between ga-3">
          <div class="creator-detail__header-text">
            <h1 class="text-h5 ma-0" data-test="creator-detail-name">{{ displayName }}</h1>
            <a
              v-if="email"
              :href="`mailto:${email}`"
              class="creator-detail__email"
              data-test="creator-detail-email"
            >
              {{ email }}
            </a>
            <div class="d-flex flex-wrap ga-2 mt-1">
              <v-chip size="small" variant="tonal" data-test="creator-detail-relationship-status">
                {{ t(`app.roster.status.${attrs.relationship_status}`) }}
              </v-chip>
              <v-chip size="small" variant="flat" data-test="creator-detail-application-status">
                {{ t(`app.roster.applicationStatus.${creator.application_status}`) }}
              </v-chip>
              <BlacklistBadge
                v-if="attrs.is_blacklisted"
                :type="blacklistType"
                :label="t(`app.roster.blacklist.badge.${blacklistType}`)"
                size="small"
                data-test="creator-detail-blacklist"
              />
            </div>
          </div>

          <!-- Header actions: message shortcut (AH-010b, D9) + add-to-pool
               (D-2b-9). The message shortcut mirrors the relationship gate; the
               pool action is admin/manager only (canEdit). -->
          <div class="d-flex flex-wrap ga-2">
            <v-btn
              v-if="canMessage"
              color="primary"
              variant="tonal"
              prepend-icon="mdi-message-text-outline"
              data-test="creator-detail-message"
              :to="{
                name: 'messages.thread',
                params: { creatorUlid },
                query: { name: displayName },
              }"
            >
              {{ t('app.messaging.relationship.inboxTitle') }}
            </v-btn>
            <v-btn
              v-if="canEdit"
              color="primary"
              variant="tonal"
              prepend-icon="mdi-account-multiple-plus-outline"
              data-test="creator-detail-add-to-pool"
              @click="poolDialog = true"
            >
              {{ t('app.pools.picker.openLabel') }}
            </v-btn>
          </div>
        </v-card-text>
      </v-card>

      <div class="creator-detail__body">
        <!-- Main column: the creator's public-facing profile content. -->
        <div class="creator-detail__main">
          <!-- Profile -->
          <v-card variant="outlined" data-test="creator-detail-profile">
            <v-card-title class="text-h6">
              {{ t('app.roster.detail.sections.profile') }}
            </v-card-title>
            <v-card-text class="d-flex flex-column ga-3">
              <p v-if="creator.bio" class="text-body-2 mb-0" data-test="creator-detail-bio">
                {{ creator.bio }}
              </p>
              <div class="creator-detail__profile-grid">
                <div>
                  <span class="creator-detail__label">{{ t('app.roster.fields.country') }}</span>
                  <CountryDisplay :code="creator.country_code" :label="countryLabel" />
                </div>
                <div>
                  <span class="creator-detail__label">{{ t('app.roster.fields.language') }}</span>
                  <LanguageList
                    :primary-label="primaryLanguageLabel"
                    :secondary-labels="secondaryLanguageLabels"
                  />
                </div>
                <div class="creator-detail__profile-categories">
                  <span class="creator-detail__label">{{ t('app.roster.fields.categories') }}</span>
                  <CategoryChips :labels="categoryLabels" />
                </div>
              </div>
            </v-card-text>
          </v-card>

          <!-- Contact details (AH-005) — phone / WhatsApp / mailing address.
               Rendered ONLY when the server surfaced the block (non-blacklisted
               relation). A blacklisted-but-rostered agency sees no card. -->
          <v-card v-if="hasContactDetails" variant="outlined" data-test="creator-detail-contact">
            <v-card-title class="text-h6">
              {{ t('app.roster.detail.sections.contact') }}
            </v-card-title>
            <v-card-text class="creator-detail__profile-grid">
              <div v-if="phone">
                <span class="creator-detail__label">{{ t('app.roster.fields.phone') }}</span>
                <a
                  :href="`tel:${phone}`"
                  class="creator-detail__email"
                  data-test="creator-detail-phone"
                >
                  {{ phone }}
                </a>
              </div>
              <div v-if="whatsapp">
                <span class="creator-detail__label">{{ t('app.roster.fields.whatsapp') }}</span>
                <span class="text-body-2" data-test="creator-detail-whatsapp">{{ whatsapp }}</span>
              </div>
              <div v-if="hasMailingAddress" class="creator-detail__profile-categories">
                <span class="creator-detail__label">{{ t('app.roster.fields.address') }}</span>
                <address class="creator-detail__address" data-test="creator-detail-address">
                  <span v-for="(line, i) in mailingAddressLines" :key="i">{{ line }}</span>
                </address>
              </div>
            </v-card-text>
          </v-card>

          <!-- Social accounts (accounts render; metrics are blocked → empty state) -->
          <v-card variant="outlined" data-test="creator-detail-social">
            <v-card-title class="text-h6">
              {{ t('app.roster.detail.sections.social') }}
            </v-card-title>
            <v-card-text>
              <SocialAccountList
                :accounts="socialAccountRows"
                :empty-label="t('app.roster.detail.social.empty')"
              />
            </v-card-text>
          </v-card>

          <!-- Social metrics — data-blocked empty state (D-2a-10) -->
          <v-card variant="outlined" data-test="creator-detail-metrics">
            <v-card-title class="text-h6">
              {{ t('app.roster.detail.sections.metrics') }}
            </v-card-title>
            <v-card-text>
              <CEmptyState
                title-tag="h3"
                data-test="creator-detail-metrics-empty"
                :title="t('app.roster.detail.metrics.empty.heading')"
                :body="t('app.roster.detail.metrics.empty.body')"
              >
                <template #icon>
                  <v-icon icon="mdi-chart-line" size="48" color="medium-emphasis" />
                </template>
              </CEmptyState>
            </v-card-text>
          </v-card>

          <!-- Portfolio — a collapsible drawer (collapsed by default). The
               header is the toggle; the down-chevron expands the grid in
               place. With no items the grid stays open to show the empty
               state and the chevron is suppressed. -->
          <v-card variant="outlined" data-test="creator-detail-portfolio">
            <v-card-title
              class="d-flex align-center justify-space-between text-h6 creator-detail__portfolio-head"
              :class="{ 'creator-detail__portfolio-head--clickable': portfolioItems.length > 0 }"
              :role="portfolioItems.length > 0 ? 'button' : undefined"
              :tabindex="portfolioItems.length > 0 ? 0 : undefined"
              :aria-expanded="portfolioItems.length > 0 ? portfolioExpanded : undefined"
              @click="portfolioItems.length > 0 && (portfolioExpanded = !portfolioExpanded)"
              @keydown.enter.prevent="
                portfolioItems.length > 0 && (portfolioExpanded = !portfolioExpanded)
              "
              @keydown.space.prevent="
                portfolioItems.length > 0 && (portfolioExpanded = !portfolioExpanded)
              "
            >
              <span class="d-flex align-center ga-2">
                {{ t('app.roster.detail.sections.portfolio') }}
                <span
                  v-if="portfolioItems.length > 0"
                  class="text-body-2 text-medium-emphasis"
                  data-test="creator-detail-portfolio-count"
                >
                  ({{ portfolioItems.length }})
                </span>
              </span>
              <v-btn
                v-if="portfolioItems.length > 0"
                :icon="portfolioExpanded ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                variant="text"
                size="small"
                :aria-label="t('creator.ui.wizard.steps.portfolio.view_all_label')"
                data-test="creator-detail-portfolio-toggle"
                @click.stop="portfolioExpanded = !portfolioExpanded"
              />
            </v-card-title>
            <v-expand-transition>
              <v-card-text v-show="portfolioExpanded || portfolioItems.length === 0">
                <PortfolioGallery
                  :items="portfolioItems"
                  :editable="false"
                  :empty-label="t('app.roster.detail.portfolio.empty')"
                  :video-label="t('creator.ui.wizard.steps.portfolio.video_badge_label')"
                  :link-label="t('creator.ui.wizard.steps.portfolio.link_badge_label')"
                  :preview-label="t('creator.ui.wizard.steps.portfolio.preview_label')"
                  :close-label="t('creator.ui.wizard.steps.portfolio.preview_close')"
                  :processing-label="t('creator.ui.wizard.steps.portfolio.processing_label')"
                  :failed-label="t('creator.ui.wizard.steps.portfolio.failed_label')"
                  :download-label="t('creator.ui.wizard.steps.portfolio.download_label')"
                  :copy-link-label="t('creator.ui.wizard.steps.portfolio.copy_link_label')"
                />
              </v-card-text>
            </v-expand-transition>
          </v-card>

          <!-- Availability (read-only, consumes the Sprint-5 agency endpoint) -->
          <v-card variant="outlined" data-test="creator-detail-availability">
            <v-card-title class="text-h6">
              {{ t('app.roster.detail.sections.availability') }}
            </v-card-title>
            <v-card-text>
              <AgencyAvailabilityCalendar
                v-if="agencyStore.currentAgencyId"
                :agency-id="agencyStore.currentAgencyId"
                :creator-ulid="creatorUlid"
              />
            </v-card-text>
          </v-card>

          <!-- Campaign history — Sprint-8-blocked empty state (D-2a-10) -->
          <v-card variant="outlined" data-test="creator-detail-campaigns">
            <v-card-title class="text-h6">
              {{ t('app.roster.detail.sections.campaigns') }}
            </v-card-title>
            <v-card-text>
              <CEmptyState
                title-tag="h3"
                data-test="creator-detail-campaigns-empty"
                :title="t('app.roster.detail.campaigns.empty.heading')"
                :body="t('app.roster.detail.campaigns.empty.body')"
              >
                <template #icon>
                  <v-icon icon="mdi-history" size="48" color="medium-emphasis" />
                </template>
              </CEmptyState>
            </v-card-text>
          </v-card>
        </div>

        <!-- Right rail: the agency's private management tools. Sticky on wide
             screens so rating/notes + blacklist stay in view while scrolling. -->
        <aside class="creator-detail__rail">
          <!-- Rating + notes editor (admin/manager) / read-only (staff) -->
          <v-card variant="outlined" data-test="creator-detail-rating-notes">
            <v-card-title class="text-h6">
              {{ t('app.roster.detail.sections.rating') }}
            </v-card-title>
            <v-card-text>
              <div class="creator-detail__rating-row">
                <span class="creator-detail__label">{{ t('app.roster.fields.rating') }}</span>
                <StarRatingInput
                  v-model="ratingDraft"
                  :readonly="!canEdit"
                  :aria-label="t('app.roster.fields.rating')"
                  :star-label="(n) => t('app.roster.detail.editor.starLabel', { n })"
                  data-test="creator-detail-rating"
                />
                <span
                  v-if="ratingDraft === null"
                  class="text-caption text-medium-emphasis"
                  data-test="creator-detail-rating-unset"
                >
                  {{ t('app.roster.detail.editor.ratingUnset') }}
                </span>
              </div>

              <!-- Editable notes (admin/manager) -->
              <template v-if="canEdit">
                <v-textarea
                  v-model="notesDraft"
                  :label="t('app.roster.detail.editor.notesLabel')"
                  :placeholder="t('app.roster.detail.editor.notesPlaceholder')"
                  variant="outlined"
                  rows="4"
                  auto-grow
                  counter="5000"
                  maxlength="5000"
                  hide-details="auto"
                  class="mt-3"
                  data-test="creator-detail-notes"
                />
                <v-alert
                  v-if="saveError"
                  type="error"
                  variant="tonal"
                  class="mt-2"
                  data-test="creator-detail-save-error"
                >
                  {{ saveError }}
                </v-alert>
                <div class="d-flex justify-end mt-2">
                  <v-btn
                    color="primary"
                    variant="flat"
                    :loading="saving"
                    :disabled="!isDirty || saving"
                    data-test="creator-detail-save"
                    @click="save"
                  >
                    {{ t('app.roster.detail.editor.save') }}
                  </v-btn>
                </div>
              </template>

              <!-- Read-only notes (staff) -->
              <template v-else>
                <div class="mt-3">
                  <span class="creator-detail__label">{{
                    t('app.roster.detail.editor.notesLabel')
                  }}</span>
                  <p
                    v-if="attrs.internal_notes"
                    class="text-body-2 mb-0"
                    data-test="creator-detail-notes-readonly"
                  >
                    {{ attrs.internal_notes }}
                  </p>
                  <span
                    v-else
                    class="text-body-2 text-medium-emphasis"
                    data-test="creator-detail-notes-readonly"
                  >
                    {{ t('app.roster.detail.editor.notesEmpty') }}
                  </span>
                </div>
              </template>
            </v-card-text>
          </v-card>

          <!-- Blacklist management (Sprint 7, A7) — admin/manager only. Shows the
               AGENCY-WIDE status (D-2) + the blacklist / un-blacklist actions. -->
          <v-card v-if="canEdit" variant="outlined" data-test="creator-detail-blacklist-section">
            <v-card-title class="text-h6">
              {{ t('app.roster.blacklist.section.title') }}
            </v-card-title>
            <v-card-text class="d-flex flex-column ga-3">
              <v-alert
                v-if="blacklistError"
                type="error"
                variant="tonal"
                data-test="creator-detail-blacklist-error"
              >
                {{ blacklistError }}
              </v-alert>

              <template v-if="isBlacklisted">
                <p class="text-body-2 mb-0" data-test="creator-detail-blacklist-status">
                  {{ t(`app.roster.blacklist.status.${blacklistType}`) }}
                  <template v-if="blacklistedDateLabel() !== null">
                    · {{ blacklistedDateLabel() }}</template
                  >
                </p>
                <div class="d-flex justify-start">
                  <v-btn
                    color="primary"
                    variant="tonal"
                    prepend-icon="mdi-account-check-outline"
                    :loading="unblacklisting"
                    data-test="creator-detail-unblacklist"
                    @click="unblacklist"
                  >
                    {{ t('app.roster.blacklist.liftAction') }}
                  </v-btn>
                </div>
              </template>

              <template v-else>
                <p
                  class="text-body-2 text-medium-emphasis mb-0"
                  data-test="creator-detail-blacklist-none"
                >
                  {{ t('app.roster.blacklist.section.none') }}
                </p>
                <div class="d-flex justify-start">
                  <v-btn
                    color="error"
                    variant="tonal"
                    prepend-icon="mdi-cancel"
                    data-test="creator-detail-blacklist-open"
                    @click="blacklistDialog = true"
                  >
                    {{ t('app.roster.blacklist.openAction') }}
                  </v-btn>
                </div>
              </template>
            </v-card-text>
          </v-card>
        </aside>
      </div>
    </template>

    <v-snackbar
      v-model="savedSnackbar"
      :timeout="3000"
      color="success"
      data-test="creator-detail-saved"
    >
      {{ t('app.roster.detail.editor.saved') }}
    </v-snackbar>

    <!-- Add-to-pool picker dialog (D-2b-9) -->
    <AddToPoolDialog
      v-if="agencyStore.currentAgencyId && canEdit"
      v-model="poolDialog"
      :agency-id="agencyStore.currentAgencyId"
      :creator-ulid="creatorUlid"
      @changed="onPoolChanged"
    />

    <!-- Blacklist dialog (Sprint 7, A7) -->
    <BlacklistCreatorDialog
      v-if="agencyStore.currentAgencyId && canEdit"
      v-model="blacklistDialog"
      :agency-id="agencyStore.currentAgencyId"
      :creator-ulid="creatorUlid"
      :has-relation="true"
      @blacklisted="onBlacklisted"
    />

    <v-snackbar
      :model-value="blacklistSnackbar !== null"
      :timeout="3000"
      color="success"
      data-test="creator-detail-blacklist-snackbar"
      @update:model-value="
        (v) => {
          if (!v) blacklistSnackbar = null
        }
      "
    >
      {{ blacklistSnackbar }}
    </v-snackbar>

    <v-snackbar
      :model-value="poolSnackbar !== null"
      :timeout="3000"
      color="success"
      data-test="creator-detail-pool-snackbar"
      @update:model-value="
        (v) => {
          if (!v) poolSnackbar = null
        }
      "
    >
      {{ poolSnackbar }}
    </v-snackbar>
  </div>
</template>

<style scoped>
.creator-detail {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-width: 1200px;
}

/* Two-column body: a flexible main column + a fixed-width management rail.
   Stacks to a single column below the md breakpoint. */
.creator-detail__body {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.creator-detail__main,
.creator-detail__rail {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

@media (min-width: 960px) {
  .creator-detail__body {
    flex-direction: row;
    align-items: flex-start;
  }
  .creator-detail__main {
    flex: 1 1 auto;
    min-width: 0;
  }
  .creator-detail__rail {
    flex: 0 0 340px;
    /* keep the agency's rating/notes + blacklist in view while the profile
       column scrolls */
    position: sticky;
    top: 16px;
  }
}

.creator-detail__header-text {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.creator-detail__email {
  font-size: 0.875rem;
  color: rgb(var(--v-theme-primary));
  text-decoration: none;
  width: fit-content;
}
.creator-detail__email:hover {
  text-decoration: underline;
}

.creator-detail__profile-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
}

.creator-detail__profile-categories {
  grid-column: 1 / -1;
}

.creator-detail__label {
  display: block;
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: rgb(var(--v-theme-on-surface-variant));
  margin-bottom: 4px;
}

.creator-detail__rating-row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.creator-detail__address {
  display: flex;
  flex-direction: column;
  font-style: normal;
  font-size: 0.875rem;
  line-height: 1.5;
}

.creator-detail__portfolio-head--clickable {
  cursor: pointer;
  user-select: none;
}

.creator-detail__portfolio-head--clickable:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}
</style>
