<script setup lang="ts">
/**
 * ProfileBasicsForm — the shared "profile basics" form body, extracted from
 * Step2ProfileBasicsPage (AH-009).
 *
 * Owns every field (avatar, display name, bio + live preview, country, region,
 * the AH-005 contact fieldset, primary language, categories), the saved-side
 * preview, and the `store.updateProfile` save call (incl. 422 field-error
 * mapping).
 *
 * It is deliberately HOST-AGNOSTIC: it renders NO <form>, NO submit button,
 * NO navigation and makes NO routing decision. The host owns the <v-form>,
 * the submit affordance, any forward/completeness gate, and decides what
 * happens after a successful save. This is what lets one form back two hosts:
 *
 *   - Step2ProfileBasicsPage (wizard): wraps this in its <v-form>, keeps the
 *     avatar+category forward-gate, the "Save and continue" label, the nav to
 *     onboarding.connections, and the wizard-mount hydration watch.
 *   - CreatorProfilePage (post-onboarding edit): wraps this in its own form,
 *     applies the lifecycle-aware completeness floor, snackbars on save.
 *
 * Readiness: emits `readiness` ({ hasAvatar, hasCategory, floorMet }) whenever
 * the gating inputs change so each host builds its own gate without reaching
 * into this component's internals.
 *
 * Hydration is HOST-DRIVEN via the exposed `hydrate()` + `isPristine`, so the
 * wizard keeps its existing onMounted + guarded-watch behaviour verbatim and
 * the profile page can re-hydrate once its bootstrap resolves.
 *
 * AH-003 (D5/D6) carry-over: the primary-language field is labelled "Native
 * language" (label only); the onboarding `secondary_languages` input was
 * removed, so the save payload deliberately omits `secondary_languages` to
 * avoid clearing existing values (the backend rule is `sometimes`).
 */

import { CategoryChips, CountryDisplay, LanguageList } from '@catalyst/ui'
import {
  ApiError,
  extractFieldErrors,
  languageEndonym,
  worldLanguageOptions,
} from '@catalyst/api-client'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import AvatarUploadDrop from './AvatarUploadDrop.vue'
import { renderBio } from '../composables/useBioRenderer'
import { COUNTRY_OPTIONS, labelForCountryCode } from '../data/countries'
import { DIAL_CODE_OPTIONS, dialCodeForCountry, splitPhoneValue } from '../data/dialCodes'
import { useOnboardingStore } from '../stores/useOnboardingStore'

/** Readiness signal the host uses to build its own gate. */
interface ProfileBasicsReadiness {
  /** Avatar persisted (store-derived). */
  hasAvatar: boolean
  /** At least one category selected. */
  hasCategory: boolean
  /** Full backend `isProfileComplete` mirror (all required fields present). */
  floorMet: boolean
}

const emit = defineEmits<{
  readiness: [value: ProfileBasicsReadiness]
}>()

const { t } = useI18n()
const store = useOnboardingStore()

/**
 * D9 — append the required-field indicator (asterisk) to a translated label.
 * The marker is locale-neutral (no translation key), so it needs no locale
 * files; the asterisk flags every field in the six-field floor so a creator
 * can tell blocking fields from score-only ones at a glance.
 */
function requiredLabel(key: string): string {
  return `${t(key)} *`
}

/**
 * Backend field-key union (matches `UpdateProfileRequest::rules()`).
 * The array-of-strings rule (`categories.*`) surfaces from Laravel's
 * validator as keys like `categories.0` — those collapse to the parent
 * field for UI purposes (the input is a multi-select chip group, no
 * per-item slot to bind to). We add the parent keys here so per-input
 * binding works on the canonical shape.
 */
type ProfileField =
  | 'display_name'
  | 'bio'
  | 'country_code'
  | 'region'
  | 'phone'
  | 'whatsapp'
  | 'address_street'
  | 'address_postal_code'
  | 'primary_language'
  | 'accent'
  | 'categories'

const displayName = ref('')
const bio = ref('')
const countryCode = ref<string | null>(null)
const region = ref<string | null>(null)
// AH-005 — optional contact details. All optional; partial entry is fine.
// phone / whatsapp are split into a dial-code part and a local-number part;
// they are joined back to a single string on save (e.g. "+34 612 345 678").
const phoneDial = ref('')
const phoneLocal = ref('')
const whatsappDial = ref('')
const whatsappLocal = ref('')
const addressStreet = ref('')
const addressPostalCode = ref('')
const primaryLanguage = ref<string | null>(null)
const accent = ref('')
const categories = ref<string[]>([])
const submitErrorKey = ref<string | null>(null)
const fieldErrors = ref<Partial<Record<ProfileField, readonly string[]>>>({})

// 28-category enum — must stay in sync with the backend
// `UpdateProfileRequest::rules()` `categories.*` list. NOTE: parity with the
// backend enum is NOT spec-verified for this copy — field-edit-config-parity
// pins only the ADMIN registry against the PHP; a drift here surfaces as a
// runtime 422, not a test failure (recorded in docs/tech-debt.md).
// Labels come from `creator.ui.wizard.categories.*` in every locale.
const CATEGORY_KEYS = [
  'fashion',
  'beauty',
  'lifestyle',
  'fitness',
  'travel',
  'food',
  'tech',
  'gaming',
  'music',
  'comedy',
  'education',
  'parenting',
  'business',
  'art',
  'sports',
  'pets',
  'photography',
  'home',
  'health',
  'finance',
  'automotive',
  'entertainment',
  'design',
  'dance',
  'sustainability',
  'news',
  'science',
  'other',
] as const

// Spoken-language options: the full world ISO 639-1 set, labelled by
// endonym (the single registry in @catalyst/api-client). The platform's
// rendered UI locales stay a separate, smaller set.
const languageOptions = worldLanguageOptions()

const categoryItems = computed(() =>
  CATEGORY_KEYS.map((key) => ({
    value: key,
    title: t(`creator.ui.wizard.categories.${key}`),
  })),
)

// "Select all" affordance for the category multi-select. `all` drives the
// checkbox's checked state; `some` drives its indeterminate state so partial
// selections read correctly. Toggling clears everything when all are already
// selected, otherwise selects the full set.
const allCategoriesSelected = computed(() => categories.value.length === CATEGORY_KEYS.length)
const someCategoriesSelected = computed(
  () => categories.value.length > 0 && !allCategoriesSelected.value,
)

function toggleSelectAllCategories(): void {
  categories.value = allCategoriesSelected.value ? [] : [...CATEGORY_KEYS]
}

const renderedBio = computed(() => renderBio(bio.value))

const countryLabel = computed(() => labelForCountryCode(countryCode.value))

const primaryLanguageLabel = computed(() => {
  if (primaryLanguage.value === null) return null
  const label = languageEndonym(primaryLanguage.value)
  const accentTrimmed = accent.value.trim()
  return accentTrimmed === '' ? label : `${label} · ${accentTrimmed}`
})

const categoryLabels = computed(() =>
  categories.value.map((key) => t(`creator.ui.wizard.categories.${key}`)),
)

const submitErrorMessage = computed(() =>
  submitErrorKey.value === null ? null : t(submitErrorKey.value),
)

// `hasAvatar` / `hasCategory` are still surfaced individually (some hosts read
// them for finer-grained hints), but the forward/floor gate is now the full
// `floorMet` mirror below. Avatar is persisted via its own immediate upload
// mutation, so we read it from the store; categories is local form state.
const hasAvatar = computed(() => store.creator?.attributes.avatar_path != null)
const hasCategory = computed(() => categories.value.length > 0)

/**
 * Full mirror of the backend `isProfileComplete` gate — the SIX-FIELD FLOOR
 * (display_name, country, region, primary_language, ≥1 category, avatar).
 * region joined the floor with D1; it uses trimmed-non-empty here while the BE
 * uses `!== null` — the field SET is the mirror invariant (pinned by the
 * floor-mirror parity spec), not the exact operator. Both wizard step 2's
 * forward gate (D2) and the profile page's hard floor key off this.
 *
 * NOTE: the "filled" test for region matches the optional-field `nullableTrim`
 * rule so BE-earned points and this floor never disagree on whitespace.
 */
const floorMet = computed(
  () =>
    displayName.value.trim() !== '' &&
    countryCode.value !== null &&
    (region.value?.trim() ?? '') !== '' &&
    primaryLanguage.value !== null &&
    categories.value.length > 0 &&
    hasAvatar.value,
)

/**
 * Pristine = the form has not been hydrated/edited yet. Mirrors the wizard's
 * original `displayName === ''` re-hydration guard; exposed so the wizard host
 * keeps its hydration watch behaviour verbatim.
 */
const isPristine = computed(() => displayName.value === '')

function currentReadiness(): ProfileBasicsReadiness {
  return {
    hasAvatar: hasAvatar.value,
    hasCategory: hasCategory.value,
    floorMet: floorMet.value,
  }
}

watch([hasAvatar, hasCategory, floorMet], () => emit('readiness', currentReadiness()), {
  immediate: true,
})

// Belt-and-suspenders: guarantee the host gets a readiness value after this
// component (and any host-driven hydration) has settled on mount.
onMounted(() => emit('readiness', currentReadiness()))

function hydrate(): void {
  const attrs = store.creator?.attributes
  if (attrs === undefined) return
  displayName.value = attrs.display_name ?? ''
  bio.value = attrs.bio ?? ''
  countryCode.value = attrs.country_code ?? null
  region.value = attrs.region ?? null
  // AH-005 — split any existing stored value (e.g. "+34 612 345 678") back
  // into dial code + local number. Falls back to the country-derived dial
  // code when no known prefix is found (covers legacy free-text entries).
  const pSplit = splitPhoneValue(attrs.phone ?? null)
  phoneDial.value = pSplit.dialCode || dialCodeForCountry(attrs.country_code ?? null)
  phoneLocal.value = pSplit.local
  const wSplit = splitPhoneValue(attrs.whatsapp ?? null)
  whatsappDial.value = wSplit.dialCode || dialCodeForCountry(attrs.country_code ?? null)
  whatsappLocal.value = wSplit.local
  addressStreet.value = attrs.address_street ?? ''
  addressPostalCode.value = attrs.address_postal_code ?? ''
  primaryLanguage.value = attrs.primary_language ?? null
  accent.value = attrs.accent ?? ''
  categories.value = [...(attrs.categories ?? [])]
}

/** Trim, mapping an empty string to `null` (clear the optional field). */
function nullableTrim(value: string): string | null {
  const trimmed = value.trim()
  return trimmed === '' ? null : trimmed
}

async function save(): Promise<boolean> {
  submitErrorKey.value = null
  fieldErrors.value = {}
  try {
    await store.updateProfile({
      display_name: displayName.value,
      bio: bio.value === '' ? null : bio.value,
      country_code: countryCode.value ?? undefined,
      region: region.value,
      // AH-005 — join dial code + local number back to a single string.
      // An empty local-number field clears the value (null).
      phone: nullableTrim(phoneLocal.value)
        ? `${phoneDial.value} ${nullableTrim(phoneLocal.value) ?? ''}`.trim()
        : null,
      whatsapp: nullableTrim(whatsappLocal.value)
        ? `${whatsappDial.value} ${nullableTrim(whatsappLocal.value) ?? ''}`.trim()
        : null,
      address_street: nullableTrim(addressStreet.value),
      address_postal_code: nullableTrim(addressPostalCode.value),
      primary_language: primaryLanguage.value ?? undefined,
      // secondary_languages intentionally omitted (AH-003 D6): the input
      // was removed; the backend rule is `sometimes`, so omitting it
      // preserves any existing value rather than clearing it.
      accent: nullableTrim(accent.value),
      categories: categories.value,
    })
    return true
  } catch (error) {
    if (error instanceof ApiError) {
      fieldErrors.value = extractFieldErrors<ProfileField>(error)
    }
    if (Object.keys(fieldErrors.value).length === 0) {
      submitErrorKey.value = 'creator.ui.errors.upload_failed'
    }
    return false
  }
}

// When the creator changes their country, update the dial-code prefix for
// any field that is still empty or whose current dial code matches the OLD
// country (i.e. the user hasn't manually overridden it).
watch(countryCode, (newCode, oldCode) => {
  const newDial = dialCodeForCountry(newCode)
  if (!newDial) return
  const oldDial = dialCodeForCountry(oldCode ?? null)
  if (!phoneLocal.value || phoneDial.value === oldDial) phoneDial.value = newDial
  if (!whatsappLocal.value || whatsappDial.value === oldDial) whatsappDial.value = newDial
})

defineExpose({ save, hydrate, isPristine })
</script>

<template>
  <div class="profile-basics-form" data-testid="profile-basics-form">
    <AvatarUploadDrop />
    <p
      class="profile-basics-form__avatar-note text-caption"
      :class="{ 'profile-basics-form__avatar-note--missing': !hasAvatar }"
      data-testid="profile-avatar-required"
    >
      {{ t('creator.ui.wizard.fields.avatar_required') }}
    </p>

    <v-text-field
      v-model="displayName"
      :label="requiredLabel('creator.ui.wizard.fields.display_name')"
      :hint="t('creator.ui.wizard.fields.display_name_help')"
      persistent-hint
      :counter="60"
      :rules="[(v: string) => !!v || t('validation.field_required')]"
      :error-messages="fieldErrors.display_name"
      data-testid="profile-display-name"
      required
    />

    <v-textarea
      v-model="bio"
      :label="t('creator.ui.wizard.fields.bio')"
      :hint="`${t('creator.ui.wizard.fields.optional_prefix')} ${t('creator.ui.wizard.fields.bio_help')}`"
      persistent-hint
      rows="4"
      auto-grow
      :counter="500"
      :error-messages="fieldErrors.bio"
      data-testid="profile-bio"
    />

    <div
      v-if="bio.length > 0"
      class="profile-basics-form__bio-preview"
      data-testid="profile-bio-preview"
      v-html="renderedBio"
    ></div>

    <!-- Autocomplete (not plain select): the full ISO list is ~250 entries,
         so type-to-filter is the only usable interaction. -->
    <v-autocomplete
      v-model="countryCode"
      :items="COUNTRY_OPTIONS"
      item-title="label"
      item-value="code"
      :label="requiredLabel('creator.ui.wizard.fields.country')"
      :error-messages="fieldErrors.country_code"
      data-testid="profile-country"
    />

    <v-text-field
      v-model="region"
      :label="requiredLabel('creator.ui.wizard.fields.region')"
      :error-messages="fieldErrors.region"
      data-testid="profile-region"
    />

    <v-text-field
      v-model="addressStreet"
      :label="t('creator.ui.wizard.fields.address_street')"
      :counter="255"
      :error-messages="fieldErrors.address_street"
      data-testid="profile-address-street"
    />

    <v-text-field
      v-model="addressPostalCode"
      :label="t('creator.ui.wizard.fields.address_postal_code')"
      :counter="20"
      :error-messages="fieldErrors.address_postal_code"
      data-testid="profile-address-postal-code"
    />

    <fieldset class="profile-basics-form__contact" data-testid="profile-contact-section">
      <legend class="text-subtitle-2">
        {{ t('creator.ui.wizard.fields.contact_section') }}
      </legend>
      <p class="profile-basics-form__contact-note text-caption">
        {{ t('creator.ui.wizard.fields.contact_section_help') }}
      </p>

      <div class="profile-basics-form__tel-row">
        <v-autocomplete
          v-model="phoneDial"
          :items="DIAL_CODE_OPTIONS"
          item-title="label"
          item-value="dialCode"
          hide-details
          class="profile-basics-form__dial-code"
          data-testid="profile-phone-dial"
        >
          <template #selection="{ item }">
            <span class="profile-basics-form__dial-selection">
              {{ item.raw.flag }}&nbsp;{{ item.raw.dialCode }}
            </span>
          </template>
        </v-autocomplete>
        <v-text-field
          v-model="phoneLocal"
          type="tel"
          :label="t('creator.ui.wizard.fields.phone')"
          :counter="28"
          :error-messages="fieldErrors.phone"
          class="profile-basics-form__tel-number"
          data-testid="profile-phone"
        />
      </div>

      <div class="profile-basics-form__tel-row">
        <v-autocomplete
          v-model="whatsappDial"
          :items="DIAL_CODE_OPTIONS"
          item-title="label"
          item-value="dialCode"
          hide-details
          class="profile-basics-form__dial-code"
          data-testid="profile-whatsapp-dial"
        >
          <template #selection="{ item }">
            <span class="profile-basics-form__dial-selection">
              {{ item.raw.flag }}&nbsp;{{ item.raw.dialCode }}
            </span>
          </template>
        </v-autocomplete>
        <v-text-field
          v-model="whatsappLocal"
          type="tel"
          :label="t('creator.ui.wizard.fields.whatsapp')"
          :counter="28"
          :error-messages="fieldErrors.whatsapp"
          class="profile-basics-form__tel-number"
          data-testid="profile-whatsapp"
        />
      </div>
    </fieldset>

    <!-- Autocomplete: the world language list is ~175 entries. -->
    <v-autocomplete
      v-model="primaryLanguage"
      :items="languageOptions"
      item-title="label"
      item-value="value"
      :label="requiredLabel('creator.ui.wizard.fields.primary_language')"
      :error-messages="fieldErrors.primary_language"
      data-testid="profile-primary-language"
    />

    <v-text-field
      v-model="accent"
      :label="t('creator.ui.wizard.fields.accent')"
      :hint="t('creator.ui.wizard.fields.accent_help')"
      persistent-hint
      :counter="80"
      :error-messages="fieldErrors.accent"
      data-testid="profile-accent"
    />

    <fieldset class="profile-basics-form__categories" data-testid="profile-categories">
      <legend class="text-subtitle-2">
        {{ requiredLabel('creator.ui.wizard.fields.categories') }}
      </legend>
      <p class="profile-basics-form__categories-note text-caption">
        {{ t('creator.ui.wizard.fields.categories_help') }}
      </p>

      <label
        class="profile-basics-form__categories-select-all"
        data-testid="profile-categories-select-all"
      >
        <v-checkbox-btn
          :model-value="allCategoriesSelected"
          :indeterminate="someCategoriesSelected"
          density="compact"
          inline
          @click.prevent="toggleSelectAllCategories"
        />
        <span class="text-body-2">{{ t('creator.ui.wizard.fields.categories_select_all') }}</span>
      </label>

      <v-chip-group
        v-model="categories"
        multiple
        column
        filter
        class="profile-basics-form__category-chips"
      >
        <v-chip
          v-for="item in categoryItems"
          :key="item.value"
          :value="item.value"
          variant="outlined"
          :data-testid="`profile-category-chip-${item.value}`"
        >
          {{ item.title }}
        </v-chip>
      </v-chip-group>

      <div
        v-if="fieldErrors.categories !== undefined"
        class="profile-basics-form__error"
        data-testid="profile-categories-error"
      >
        {{ fieldErrors.categories.join(' ') }}
      </div>
    </fieldset>

    <div class="profile-basics-form__preview" data-testid="profile-preview">
      <h3 class="text-subtitle-2">{{ t('creator.ui.wizard.fields.country') }}</h3>
      <CountryDisplay :code="countryCode" :label="countryLabel" />
      <h3 class="text-subtitle-2">{{ t('creator.ui.wizard.fields.primary_language') }}</h3>
      <LanguageList :primary-label="primaryLanguageLabel" :secondary-labels="[]" />
      <h3 class="text-subtitle-2">{{ t('creator.ui.wizard.fields.categories') }}</h3>
      <CategoryChips :labels="categoryLabels" />
    </div>

    <div
      v-if="submitErrorMessage !== null"
      role="alert"
      class="profile-basics-form__error"
      data-testid="profile-submit-error"
    >
      {{ submitErrorMessage }}
    </div>
  </div>
</template>

<style scoped>
.profile-basics-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.profile-basics-form__bio-preview {
  padding: 12px 16px;
  border: 1px solid rgb(var(--v-theme-outline-variant, var(--v-theme-outline)));
  border-radius: 6px;
  background-color: rgb(var(--v-theme-surface));
  font-size: 0.9375rem;
  line-height: 1.6;
  overflow-wrap: break-word;
  word-break: break-word;
  overflow: hidden;
  min-width: 0;
}

.profile-basics-form__bio-preview :deep(*) {
  overflow-wrap: break-word;
  word-break: break-word;
  max-width: 100%;
}

.profile-basics-form__preview {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 16px;
  border: 1px solid rgb(var(--v-theme-outline-variant, var(--v-theme-outline)));
  border-radius: 6px;
  background-color: rgb(var(--v-theme-surface-variant));
  overflow: hidden;
  min-width: 0;
  overflow-wrap: break-word;
  word-break: break-word;
}

.profile-basics-form__error {
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}

.profile-basics-form__contact {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 16px;
  border: 1px solid rgb(var(--v-theme-outline-variant, var(--v-theme-outline)));
  border-radius: 6px;
}

.profile-basics-form__contact legend {
  padding: 0 6px;
}

.profile-basics-form__categories {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 16px;
  border: 1px solid rgb(var(--v-theme-outline-variant, var(--v-theme-outline)));
  border-radius: 6px;
}

.profile-basics-form__categories legend {
  padding: 0 6px;
}

.profile-basics-form__categories-note {
  margin-top: -4px;
  color: rgb(var(--v-theme-on-surface-variant));
}

.profile-basics-form__categories-select-all {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  cursor: pointer;
  align-self: flex-start;
}

.profile-basics-form__contact-note {
  margin-top: -8px;
  color: rgb(var(--v-theme-on-surface-variant));
}

.profile-basics-form__tel-row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

.profile-basics-form__dial-code {
  flex: 0 0 125px;
  min-width: 125px;
}

/* Vuetify autocomplete renders the #selection slot AND a live search <input>
   in the same flex container.  On mobile when focused that input wraps to a
   second line.  Force the row to stay on one line and shrink the search input
   to zero until the user actually starts typing — the input remains fully
   functional, it just doesn't push the layout. */
.profile-basics-form__dial-code :deep(.v-field__input) {
  flex-wrap: nowrap;
  align-items: center;
}
.profile-basics-form__dial-code :deep(.v-field__input input) {
  min-width: 0 !important;
  width: 0 !important;
  flex: 0 1 auto;
}

/* The selection slot renders a <span> so CSS text-overflow applies cleanly,
   unlike the native <input> that the autocomplete uses by default. */
.profile-basics-form__dial-selection {
  overflow: hidden;
  text-overflow: clip;
  white-space: nowrap;
  font-size: 0.875rem;
}

.profile-basics-form__tel-number {
  flex: 1 1 auto;
}

.profile-basics-form__avatar-note {
  text-align: center;
  margin-top: -4px;
  color: rgb(var(--v-theme-on-surface-variant));
}

.profile-basics-form__avatar-note--missing {
  color: rgb(var(--v-theme-error));
}
</style>
