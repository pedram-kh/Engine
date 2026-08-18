<script setup lang="ts">
/**
 * CreatorProfileContent (AH-080) — the actual profile body, extracted from
 * `CreatorProfileDialog` so the SAME markup can render either as a dialog's
 * body (the Creators-tab row + application mount contexts) OR inline inside
 * an existing `v-window-item` (the board card drawer's Profile tab, D2b) —
 * a drawer tab is not "open another dialog on top of this dialog", it needs
 * the content without a second `v-dialog` shell.
 *
 * Loads on mount (and again if the caller passes a different creator) via
 * `useCreatorProfile` — see that composable for the full fallback contract
 * (D3). Two payload modes, resolved there:
 *   - FULL — the roster-detail resource. Renders Profile, Contact (only when
 *     the server actually shipped the block — see `hasContactDetails`),
 *     Account creation, Socials, and the agency-private Rating/Notes +
 *     Blacklist management (D4) — the SAME wired components
 *     `CreatorDetailPage.vue` uses (`StarRatingInput`, `BlacklistCreatorDialog`,
 *     `rosterApi.updateRelation` / `.blacklist` / `.unblacklist`), not copies.
 *   - THIN — the discover public-profile resource, the truthful fallback for
 *     a creator this agency has no relation with. Renders Profile + Socials
 *     ONLY — `CreatorPublicProfileResource` structurally carries none of the
 *     other keys (§5.34 pins zero contact DOM on a thin payload). A visible
 *     line states the thinness honestly rather than rendering empty cards.
 *
 * i18n reuse (AH-080 read-pass overturn): section headers and field labels
 * read the SAME `app.roster.*` keys `CreatorDetailPage.vue` does, rather than
 * cloning a parallel namespace ×24 locales — cross-module `t()` reads are
 * already the house norm (e.g. `BoardCardDrawer.vue` reads
 * `app.campaigns.tabs.drafts`). Net-new copy is exactly the thinness line.
 */

import type { AgencyCreatorDetailProfile, CreatorPublicProfile } from '@catalyst/api-client'
import { languageEndonym } from '@catalyst/api-client'
import {
  BlacklistBadge,
  CategoryChips,
  CountryDisplay,
  LanguageList,
  SocialAccountList,
} from '@catalyst/ui'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { useCreatorProfile } from '@/composables/useCreatorProfile'
import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { COUNTRY_OPTIONS } from '@/modules/onboarding/data/countries'
import { rosterApi } from '@/modules/roster/api/roster.api'
import BlacklistCreatorDialog from '@/modules/roster/components/BlacklistCreatorDialog.vue'
import StarRatingInput from '@/modules/roster/components/StarRatingInput.vue'

import CreatorAvatar from './CreatorAvatar.vue'

const props = withDefaults(
  defineProps<{
    agencyId: string
    creatorUlid: string
    /**
     * The relation is GUARANTEED to exist (an applicant is rostered by
     * definition) — skip the 404-fallback dance, one fetch. See
     * `useCreatorProfile`'s docblock.
     */
    assumeFull?: boolean
  }>(),
  { assumeFull: false },
)

const { t } = useI18n()
const agencyStore = useAgencyStore()

const { loading, profile, error, load } = useCreatorProfile()

const canEdit = computed(
  () => agencyStore.currentRole === 'agency_admin' || agencyStore.currentRole === 'agency_manager',
)

const isFull = computed(() => profile.value?.mode === 'full')
const isThin = computed(() => profile.value?.mode === 'thin')

const fullCreator = computed<AgencyCreatorDetailProfile | null>(() =>
  profile.value?.mode === 'full' ? (profile.value.data.attributes.creator ?? null) : null,
)
const thinProfile = computed<CreatorPublicProfile | null>(() =>
  profile.value?.mode === 'thin' ? profile.value.data : null,
)

// ── Normalized fields — the shape both payloads share (profile + socials) ──
const displayName = computed(
  () =>
    fullCreator.value?.display_name ??
    thinProfile.value?.attributes.display_name ??
    t('app.roster.detail.unnamed'),
)
const avatarUrl = computed(
  () => fullCreator.value?.avatar_url ?? thinProfile.value?.attributes.avatar_url ?? null,
)
const bio = computed(() => fullCreator.value?.bio ?? thinProfile.value?.attributes.bio ?? null)
const countryCode = computed(
  () => fullCreator.value?.country_code ?? thinProfile.value?.attributes.country_code ?? null,
)
const countryLabel = computed(() => {
  const code = countryCode.value
  if (code === null) return ''
  return COUNTRY_OPTIONS.find((c) => c.code === code)?.label ?? code
})

function languageLabel(code: string | null): string | null {
  if (code === null) return null
  return languageEndonym(code)
}

const primaryLanguageLabel = computed(() => {
  const primary =
    fullCreator.value?.primary_language ?? thinProfile.value?.attributes.primary_language ?? null
  const accent = fullCreator.value?.accent ?? thinProfile.value?.attributes.accent ?? null
  const label = languageLabel(primary)
  if (label === null) return null
  return accent !== null && accent !== '' ? `${label} · ${accent}` : label
})
const secondaryLanguageLabels = computed(() =>
  (
    fullCreator.value?.secondary_languages ??
    thinProfile.value?.attributes.secondary_languages ??
    []
  )
    .map((c) => languageLabel(c))
    .filter((l): l is string => l !== null),
)

const categoryLabels = computed(() =>
  (fullCreator.value?.categories ?? thinProfile.value?.attributes.categories ?? []).map((cat) =>
    t(`creator.ui.wizard.categories.${cat}`, cat),
  ),
)
const companionLabels = computed(() =>
  (
    fullCreator.value?.content_companions ??
    thinProfile.value?.attributes.content_companions ??
    []
  ).map((key) => t(`creator.ui.wizard.companions.${key}`, key)),
)

const socialAccountRows = computed(() => {
  const raw =
    fullCreator.value?.social_accounts ?? thinProfile.value?.attributes.social_accounts ?? []
  return raw.map((account) => ({
    platform: account.platform,
    handle: account.handle,
    profileUrl: account.profile_url,
    platformLabel: t(`creator.ui.wizard.social_platforms.${account.platform}`, account.platform),
  }))
})

// ── Full-mode-only fields ───────────────────────────────────────────────────
const email = computed(() => fullCreator.value?.email ?? null)
const accountFirstName = computed(() => fullCreator.value?.account_name ?? null)
const accountLastName = computed(() => fullCreator.value?.account_last_name ?? null)
const hasAccountDetails = computed(
  () =>
    isFull.value &&
    (accountFirstName.value !== null || accountLastName.value !== null || email.value !== null),
)

// AH-005 contact block — present only when the server shipped it (a
// non-blacklisted relation, `CreatorPolicy::canSeeContactDetails`). Absence
// here on a FULL payload is a withheld-but-related case, NOT thin mode —
// the two are structurally different (§5.34 asserts the THIN case).
const phone = computed(() => fullCreator.value?.phone ?? null)
const whatsapp = computed(() => fullCreator.value?.whatsapp ?? null)
const addressStreet = computed(() => fullCreator.value?.address_street ?? null)
const addressPostalCode = computed(() => fullCreator.value?.address_postal_code ?? null)
const mailingAddressLines = computed<string[]>(() => {
  const c = fullCreator.value
  if (c === null) return []
  const cityLine = [c.address_postal_code, c.region].filter((p): p is string => !!p).join(' ')
  return [c.address_street ?? '', cityLine, countryLabel.value].filter((line) => line !== '')
})
const hasMailingAddress = computed(
  () => addressStreet.value !== null || addressPostalCode.value !== null,
)
const hasContactDetails = computed(
  () =>
    isFull.value && (phone.value !== null || whatsapp.value !== null || hasMailingAddress.value),
)

const isBlacklisted = computed(
  () => profile.value?.mode === 'full' && profile.value.data.attributes.is_blacklisted,
)
const blacklistType = computed(() =>
  profile.value?.mode === 'full'
    ? (profile.value.data.attributes.blacklist_type ?? 'hard')
    : 'hard',
)
const blacklistedDateLabel = computed<string | null>(() => {
  if (profile.value?.mode !== 'full') return null
  const at = profile.value.data.attributes.blacklisted_at
  return at === null ? null : new Date(at).toLocaleDateString()
})

// ── Rating/notes editor state (full mode, D4 — same wiring as CreatorDetailPage) ──
const ratingDraft = ref<number | null>(null)
const notesDraft = ref<string>('')
const saving = ref(false)
const saveError = ref<string | null>(null)
const savedSnackbar = ref(false)

const isDirty = computed(() => {
  if (profile.value?.mode !== 'full') return false
  const attrs = profile.value.data.attributes
  return (
    ratingDraft.value !== attrs.internal_rating || notesDraft.value !== (attrs.internal_notes ?? '')
  )
})

function seedDrafts(): void {
  if (profile.value?.mode !== 'full') return
  ratingDraft.value = profile.value.data.attributes.internal_rating
  notesDraft.value = profile.value.data.attributes.internal_notes ?? ''
}

async function save(): Promise<void> {
  if (profile.value?.mode !== 'full') return
  saving.value = true
  saveError.value = null
  try {
    const envelope = await rosterApi.updateRelation(props.agencyId, props.creatorUlid, {
      internal_rating: ratingDraft.value,
      internal_notes: notesDraft.value === '' ? null : notesDraft.value,
    })
    profile.value = { mode: 'full', data: envelope.data }
    seedDrafts()
    savedSnackbar.value = true
  } catch {
    saveError.value = t('app.roster.detail.editor.saveFailed')
  } finally {
    saving.value = false
  }
}

// ── Blacklist management (full mode only, canEdit) ─────────────────────────
const blacklistDialog = ref(false)
const unblacklisting = ref(false)
const blacklistSnackbar = ref<string | null>(null)
const blacklistError = ref<string | null>(null)

function onBlacklisted(message: string): void {
  blacklistSnackbar.value = message
  void refresh()
}

async function unblacklist(): Promise<void> {
  unblacklisting.value = true
  blacklistError.value = null
  try {
    await rosterApi.unblacklist(props.agencyId, props.creatorUlid, { scope: 'agency' })
    blacklistSnackbar.value = t('app.roster.blacklist.lifted')
    await refresh()
  } catch {
    blacklistError.value = t('app.roster.blacklist.liftFailed')
  } finally {
    unblacklisting.value = false
  }
}

async function refresh(): Promise<void> {
  await load(props.agencyId, props.creatorUlid, { assumeFull: props.assumeFull })
  seedDrafts()
}

watch(() => [props.agencyId, props.creatorUlid], refresh, { immediate: true })
</script>

<template>
  <div data-test="creator-profile-content" style="min-height: 200px">
    <v-skeleton-loader
      v-if="loading && profile === null"
      type="article, list-item-two-line"
      data-test="creator-profile-content-skeleton"
    />

    <v-alert
      v-else-if="error !== null"
      type="error"
      variant="tonal"
      data-test="creator-profile-content-error"
    >
      {{
        error === 'not-found' ? t('app.roster.detail.notFound') : t('app.roster.detail.loadFailed')
      }}
    </v-alert>

    <template v-else-if="profile !== null">
      <!-- Header: avatar + name (+ blacklist badge, full mode only) -->
      <div class="d-flex align-start ga-4 mb-4">
        <CreatorAvatar
          :src="avatarUrl"
          :name="displayName"
          :size="56"
          :preview-label="t('creator.ui.wizard.steps.portfolio.preview_label')"
          :close-label="t('creator.ui.wizard.steps.portfolio.preview_close')"
          data-test="creator-profile-content-avatar"
        />
        <div>
          <h2 class="text-h6 ma-0" data-test="creator-profile-content-name">
            {{ displayName }}
          </h2>
          <BlacklistBadge
            v-if="isBlacklisted"
            :type="blacklistType"
            :label="t(`app.roster.blacklist.badge.${blacklistType}`)"
            size="small"
            class="mt-1"
            data-test="creator-profile-content-blacklist-badge"
          />
        </div>
      </div>

      <!-- Thin mode states its thinness honestly — no empty contact
           skeletons, one plain sentence explaining why there is less. -->
      <v-alert
        v-if="isThin"
        type="info"
        variant="tonal"
        density="compact"
        class="mb-4"
        data-test="creator-profile-content-thin-notice"
      >
        {{ t('app.creatorProfile.thinNotice') }}
      </v-alert>

      <!-- Profile — both modes -->
      <v-card variant="outlined" class="mb-4" data-test="creator-profile-content-profile">
        <v-card-title class="text-subtitle-1">
          {{ t('app.roster.detail.sections.profile') }}
        </v-card-title>
        <v-card-text class="d-flex flex-column ga-3">
          <p v-if="bio" class="text-body-2 mb-0" data-test="creator-profile-content-bio">
            {{ bio }}
          </p>
          <div class="creator-profile-content__grid">
            <div>
              <span class="creator-profile-content__label">{{
                t('app.roster.fields.country')
              }}</span>
              <CountryDisplay :code="countryCode" :label="countryLabel" />
            </div>
            <div>
              <span class="creator-profile-content__label">{{
                t('app.roster.fields.language')
              }}</span>
              <LanguageList
                :primary-label="primaryLanguageLabel"
                :secondary-labels="secondaryLanguageLabels"
              />
            </div>
            <div class="creator-profile-content__grid-full">
              <span class="creator-profile-content__label">{{
                t('app.roster.fields.categories')
              }}</span>
              <CategoryChips :labels="categoryLabels" />
            </div>
            <div class="creator-profile-content__grid-full">
              <span class="creator-profile-content__label">{{
                t('app.roster.fields.companions')
              }}</span>
              <CategoryChips :labels="companionLabels" />
            </div>
          </div>
        </v-card-text>
      </v-card>

      <!-- Contact — full mode only, and only when the server shipped it -->
      <v-card
        v-if="hasContactDetails"
        variant="outlined"
        class="mb-4"
        data-test="creator-profile-content-contact"
      >
        <v-card-title class="text-subtitle-1">
          {{ t('app.roster.detail.sections.contact') }}
        </v-card-title>
        <v-card-text class="creator-profile-content__grid">
          <div v-if="phone">
            <span class="creator-profile-content__label">{{ t('app.roster.fields.phone') }}</span>
            <a
              :href="`tel:${phone}`"
              class="creator-profile-content__link"
              data-test="creator-profile-content-phone"
            >
              {{ phone }}
            </a>
          </div>
          <div v-if="whatsapp">
            <span class="creator-profile-content__label">{{
              t('app.roster.fields.whatsapp')
            }}</span>
            <span class="text-body-2" data-test="creator-profile-content-whatsapp">{{
              whatsapp
            }}</span>
          </div>
          <div v-if="hasMailingAddress" class="creator-profile-content__grid-full">
            <span class="creator-profile-content__label">{{ t('app.roster.fields.address') }}</span>
            <address
              class="creator-profile-content__address"
              data-test="creator-profile-content-address"
            >
              <span v-for="(line, i) in mailingAddressLines" :key="i">{{ line }}</span>
            </address>
          </div>
        </v-card-text>
      </v-card>

      <!-- Account creation — full mode only -->
      <v-card
        v-if="hasAccountDetails"
        variant="outlined"
        class="mb-4"
        data-test="creator-profile-content-account"
      >
        <v-card-title class="text-subtitle-1">
          {{ t('app.roster.detail.sections.account') }}
        </v-card-title>
        <v-card-text class="creator-profile-content__grid">
          <div>
            <span class="creator-profile-content__label">{{
              t('app.roster.fields.firstName')
            }}</span>
            <span class="text-body-2" data-test="creator-profile-content-account-first-name">
              {{ accountFirstName ?? '—' }}
            </span>
          </div>
          <div>
            <span class="creator-profile-content__label">{{
              t('app.roster.fields.lastName')
            }}</span>
            <span class="text-body-2" data-test="creator-profile-content-account-last-name">
              {{ accountLastName ?? '—' }}
            </span>
          </div>
          <div>
            <span class="creator-profile-content__label">{{ t('app.roster.fields.email') }}</span>
            <span class="text-body-2" data-test="creator-profile-content-account-email">
              {{ email ?? '—' }}
            </span>
          </div>
        </v-card-text>
      </v-card>

      <!-- Socials — both modes -->
      <v-card variant="outlined" class="mb-4" data-test="creator-profile-content-social">
        <v-card-title class="text-subtitle-1">
          {{ t('app.roster.detail.sections.social') }}
        </v-card-title>
        <v-card-text>
          <SocialAccountList
            :accounts="socialAccountRows"
            :empty-label="t('app.roster.detail.social.empty')"
          />
        </v-card-text>
      </v-card>

      <!-- Rating / notes — full mode only, D4: the SAME wired components
           CreatorDetailPage uses, not copies. -->
      <v-card
        v-if="isFull"
        variant="outlined"
        class="mb-4"
        data-test="creator-profile-content-rating-notes"
      >
        <v-card-title class="text-subtitle-1">
          {{ t('app.roster.detail.sections.rating') }}
        </v-card-title>
        <v-card-text>
          <div class="d-flex align-center ga-3">
            <span class="creator-profile-content__label">{{ t('app.roster.fields.rating') }}</span>
            <StarRatingInput
              v-model="ratingDraft"
              :readonly="!canEdit"
              :aria-label="t('app.roster.fields.rating')"
              :star-label="(n) => t('app.roster.detail.editor.starLabel', { n })"
              data-test="creator-profile-content-rating"
            />
          </div>

          <template v-if="canEdit">
            <v-textarea
              v-model="notesDraft"
              :label="t('app.roster.detail.editor.notesLabel')"
              :placeholder="t('app.roster.detail.editor.notesPlaceholder')"
              variant="outlined"
              rows="3"
              auto-grow
              counter="5000"
              maxlength="5000"
              hide-details="auto"
              class="mt-3"
              data-test="creator-profile-content-notes"
            />
            <v-alert
              v-if="saveError"
              type="error"
              variant="tonal"
              class="mt-2"
              data-test="creator-profile-content-save-error"
            >
              {{ saveError }}
            </v-alert>
            <div class="d-flex justify-end mt-2">
              <v-btn
                color="primary"
                variant="flat"
                :loading="saving"
                :disabled="!isDirty || saving"
                data-test="creator-profile-content-save"
                @click="save"
              >
                {{ t('app.roster.detail.editor.save') }}
              </v-btn>
            </div>
          </template>
          <template v-else>
            <div class="mt-3">
              <span class="creator-profile-content__label">{{
                t('app.roster.detail.editor.notesLabel')
              }}</span>
              <p
                v-if="profile.mode === 'full' && profile.data.attributes.internal_notes"
                class="text-body-2 mb-0"
                data-test="creator-profile-content-notes-readonly"
              >
                {{ profile.data.attributes.internal_notes }}
              </p>
              <span
                v-else
                class="text-body-2 text-medium-emphasis"
                data-test="creator-profile-content-notes-readonly"
              >
                {{ t('app.roster.detail.editor.notesEmpty') }}
              </span>
            </div>
          </template>
        </v-card-text>
      </v-card>

      <!-- Blacklist management — full mode + canEdit only -->
      <v-card
        v-if="isFull && canEdit"
        variant="outlined"
        data-test="creator-profile-content-blacklist-section"
      >
        <v-card-title class="text-subtitle-1">
          {{ t('app.roster.blacklist.section.title') }}
        </v-card-title>
        <v-card-text class="d-flex flex-column ga-3">
          <v-alert
            v-if="blacklistError"
            type="error"
            variant="tonal"
            data-test="creator-profile-content-blacklist-error"
          >
            {{ blacklistError }}
          </v-alert>

          <template v-if="isBlacklisted">
            <p class="text-body-2 mb-0" data-test="creator-profile-content-blacklist-status">
              {{ t(`app.roster.blacklist.status.${blacklistType}`) }}
              <template v-if="blacklistedDateLabel !== null">
                · {{ blacklistedDateLabel }}</template
              >
            </p>
            <div class="d-flex justify-start">
              <v-btn
                color="primary"
                variant="tonal"
                prepend-icon="mdi-account-check-outline"
                :loading="unblacklisting"
                data-test="creator-profile-content-unblacklist"
                @click="unblacklist"
              >
                {{ t('app.roster.blacklist.liftAction') }}
              </v-btn>
            </div>
          </template>
          <template v-else>
            <p
              class="text-body-2 text-medium-emphasis mb-0"
              data-test="creator-profile-content-blacklist-none"
            >
              {{ t('app.roster.blacklist.section.none') }}
            </p>
            <div class="d-flex justify-start">
              <v-btn
                color="error"
                variant="tonal"
                prepend-icon="mdi-cancel"
                data-test="creator-profile-content-blacklist-open"
                @click="blacklistDialog = true"
              >
                {{ t('app.roster.blacklist.openAction') }}
              </v-btn>
            </div>
          </template>
        </v-card-text>
      </v-card>
    </template>

    <!-- Blacklist dialog — the SAME component the roster detail page uses -->
    <BlacklistCreatorDialog
      v-if="isFull && canEdit"
      v-model="blacklistDialog"
      :agency-id="agencyId"
      :creator-ulid="creatorUlid"
      :has-relation="true"
      @blacklisted="onBlacklisted"
    />

    <v-snackbar
      v-model="savedSnackbar"
      :timeout="3000"
      color="success"
      data-test="creator-profile-content-saved"
    >
      {{ t('app.roster.detail.editor.saved') }}
    </v-snackbar>

    <v-snackbar
      :model-value="blacklistSnackbar !== null"
      :timeout="3000"
      color="success"
      data-test="creator-profile-content-blacklist-snackbar"
      @update:model-value="
        (v) => {
          if (!v) blacklistSnackbar = null
        }
      "
    >
      {{ blacklistSnackbar }}
    </v-snackbar>
  </div>
</template>

<style scoped>
.creator-profile-content__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px;
}

.creator-profile-content__grid-full {
  grid-column: 1 / -1;
}

.creator-profile-content__label {
  display: block;
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: rgb(var(--v-theme-on-surface-variant));
  margin-bottom: 4px;
}

.creator-profile-content__link {
  font-size: 0.875rem;
  color: rgb(var(--v-theme-primary));
  text-decoration: none;
  width: fit-content;
}
.creator-profile-content__link:hover {
  text-decoration: underline;
}

.creator-profile-content__address {
  display: flex;
  flex-direction: column;
  font-style: normal;
  font-size: 0.875rem;
  line-height: 1.5;
}
</style>
