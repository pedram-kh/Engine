<script setup lang="ts">
/**
 * Step2ProfileBasicsPage — wizard Step 2 (Profile basics).
 *
 * Sprint 3 Chunk 3 sub-step 5. Form for display name, bio, avatar,
 * country, region, primary language, secondary languages, and
 * categories.
 *
 * Implementation notes:
 *   - Form lives in apps/main per Decision C1 (form-main); display
 *     sub-components (CountryDisplay, CategoryChips, LanguageList)
 *     come from `@catalyst/ui` and are used to render the "saved"
 *     side-by-side preview at the bottom of the form, so the
 *     creator can verify their last save while editing.
 *   - Bio uses the `renderBio` composable (markdown-it + DOMPurify)
 *     for live-preview.
 *   - Avatar uses {@link AvatarUploadDrop}, which manages its own
 *     state via {@link useAvatarUpload}.
 *   - Save uses Decision Q-wizard-4 (hybrid b+a): debounce per-field
 *     edits to send saves implicitly (via the next blur) AND offer
 *     an explicit "Save and continue" button at the bottom that
 *     triggers the same `updateProfile` mutation + navigates to
 *     Step 3 on success.
 *
 * Step 2 → Step 3 advance: after a successful save the page calls
 * `router.push({ name: 'onboarding.social' })`. The
 * `requireOnboardingAccess` guard already gates the wizard at the
 * group level, so no per-step gate is needed.
 *
 * Form validation: handled at the field level via Vuetify's
 * `:rules` prop. Server-side validation errors (422) are
 * translated via `useErrorMessage`.
 */

import { CategoryChips, CountryDisplay, LanguageList } from '@catalyst/ui'
import { ApiError, extractFieldErrors } from '@catalyst/api-client'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import AvatarUploadDrop from '../components/AvatarUploadDrop.vue'
import { renderBio } from '../composables/useBioRenderer'
import { COUNTRY_OPTIONS, labelForCountryCode } from '../data/countries'
import { useOnboardingStore } from '../stores/useOnboardingStore'

const { t } = useI18n()
const router = useRouter()
const store = useOnboardingStore()

/**
 * Backend field-key union (matches `UpdateProfileRequest::rules()`).
 * The array-of-strings rules (`secondary_languages.*`, `categories.*`)
 * surface from Laravel's validator as keys like `secondary_languages.0`
 * — those collapse to the parent field for UI purposes (the input is
 * a multi-select chip group, no per-item slot to bind to). We add the
 * parent keys here so per-input binding works on the canonical shape.
 */
type ProfileField =
  | 'display_name'
  | 'bio'
  | 'country_code'
  | 'region'
  | 'primary_language'
  | 'secondary_languages'
  | 'categories'

const displayName = ref('')
const bio = ref('')
const countryCode = ref<string | null>(null)
const region = ref<string | null>(null)
const primaryLanguage = ref<string | null>(null)
const secondaryLanguages = ref<string[]>([])
const categories = ref<string[]>([])
const submitErrorKey = ref<string | null>(null)
const fieldErrors = ref<Partial<Record<ProfileField, readonly string[]>>>({})

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
  'other',
] as const

const LANGUAGE_OPTIONS = [
  { code: 'en', label: 'English' },
  { code: 'pt', label: 'Português' },
  { code: 'it', label: 'Italiano' },
  { code: 'es', label: 'Español' },
  { code: 'fr', label: 'Français' },
  { code: 'de', label: 'Deutsch' },
] as const

const categoryItems = computed(() =>
  CATEGORY_KEYS.map((key) => ({
    value: key,
    title: t(`creator.ui.wizard.categories.${key}`),
  })),
)

const renderedBio = computed(() => renderBio(bio.value))

const countryLabel = computed(() => labelForCountryCode(countryCode.value))

const primaryLanguageLabel = computed(() => {
  if (primaryLanguage.value === null) return null
  return (
    LANGUAGE_OPTIONS.find((l) => l.code === primaryLanguage.value)?.label ?? primaryLanguage.value
  )
})

const secondaryLanguageLabels = computed(() =>
  secondaryLanguages.value.map(
    (code) => LANGUAGE_OPTIONS.find((l) => l.code === code)?.label ?? code,
  ),
)

const categoryLabels = computed(() =>
  categories.value.map((key) => t(`creator.ui.wizard.categories.${key}`)),
)

const isSaving = computed(() => store.isLoadingProfile)
const submitErrorMessage = computed(() =>
  submitErrorKey.value === null ? null : t(submitErrorKey.value),
)

// The backend's `isProfileComplete` gate requires an avatar AND at
// least one category (CompletenessScoreCalculator). Both were
// previously presented as optional in this form, so a creator could
// "Save and continue" with the step still incomplete — leaving them
// stuck at next_step=profile and 0 score credit for the step. Mirror
// the backend rule on the client: avatar is persisted via its own
// immediate upload mutation, so we read it from the store; categories
// is local form state.
const hasAvatar = computed(() => store.creator?.attributes.avatar_path != null)
const hasCategory = computed(() => categories.value.length > 0)
const canContinue = computed(() => hasAvatar.value && hasCategory.value)

function hydrateFromCreator(): void {
  const attrs = store.creator?.attributes
  if (attrs === undefined) return
  displayName.value = attrs.display_name ?? ''
  bio.value = attrs.bio ?? ''
  countryCode.value = attrs.country_code ?? null
  region.value = attrs.region ?? null
  primaryLanguage.value = attrs.primary_language ?? null
  secondaryLanguages.value = [...(attrs.secondary_languages ?? [])]
  categories.value = [...(attrs.categories ?? [])]
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
      primary_language: primaryLanguage.value ?? undefined,
      secondary_languages: secondaryLanguages.value,
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

async function onSubmit(): Promise<void> {
  // Guard the keyboard-submit path (Enter inside a field) too — the
  // button is disabled, but the form's submit event can still fire.
  if (!canContinue.value) return
  const ok = await save()
  if (ok) {
    await router.push({ name: 'onboarding.social' })
  }
}

watch(
  () => store.creator,
  (creator) => {
    if (creator !== null && displayName.value === '') {
      hydrateFromCreator()
    }
  },
)

onMounted(() => {
  hydrateFromCreator()
})
</script>

<template>
  <section class="profile-basics" data-testid="step-profile-basics">
    <header class="profile-basics__header">
      <h2 class="text-h5">{{ t('creator.ui.wizard.steps.profile.title') }}</h2>
      <p class="text-body-2 text-medium-emphasis">
        {{ t('creator.ui.wizard.steps.profile.description') }}
      </p>
    </header>

    <v-form class="profile-basics__form" @submit.prevent="onSubmit">
      <AvatarUploadDrop />
      <p
        class="profile-basics__avatar-note text-caption"
        :class="{ 'profile-basics__avatar-note--missing': !hasAvatar }"
        data-testid="profile-avatar-required"
      >
        {{ t('creator.ui.wizard.fields.avatar_required') }}
      </p>

      <v-text-field
        v-model="displayName"
        :label="t('creator.ui.wizard.fields.display_name')"
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
        :hint="t('creator.ui.wizard.fields.bio_help')"
        persistent-hint
        rows="4"
        auto-grow
        :counter="500"
        :error-messages="fieldErrors.bio"
        data-testid="profile-bio"
      />

      <div
        v-if="bio.length > 0"
        class="profile-basics__bio-preview"
        data-testid="profile-bio-preview"
        v-html="renderedBio"
      ></div>

      <v-select
        v-model="countryCode"
        :items="COUNTRY_OPTIONS"
        item-title="label"
        item-value="code"
        :label="t('creator.ui.wizard.fields.country')"
        :error-messages="fieldErrors.country_code"
        data-testid="profile-country"
      />

      <v-text-field
        v-model="region"
        :label="t('creator.ui.wizard.fields.region')"
        :error-messages="fieldErrors.region"
        data-testid="profile-region"
      />

      <v-select
        v-model="primaryLanguage"
        :items="LANGUAGE_OPTIONS"
        item-title="label"
        item-value="code"
        :label="t('creator.ui.wizard.fields.primary_language')"
        :error-messages="fieldErrors.primary_language"
        data-testid="profile-primary-language"
      />

      <v-select
        v-model="secondaryLanguages"
        :items="LANGUAGE_OPTIONS"
        item-title="label"
        item-value="code"
        :label="t('creator.ui.wizard.fields.secondary_languages')"
        multiple
        chips
        :error-messages="fieldErrors.secondary_languages"
        data-testid="profile-secondary-languages"
      />

      <v-select
        v-model="categories"
        :items="categoryItems"
        :label="t('creator.ui.wizard.fields.categories')"
        :hint="t('creator.ui.wizard.fields.categories_help')"
        persistent-hint
        multiple
        chips
        :rules="[
          (v: string[]) => (Array.isArray(v) && v.length > 0) || t('validation.field_required'),
        ]"
        :error-messages="fieldErrors.categories"
        data-testid="profile-categories"
      />

      <div class="profile-basics__preview" data-testid="profile-preview">
        <h3 class="text-subtitle-2">{{ t('creator.ui.wizard.fields.country') }}</h3>
        <CountryDisplay :code="countryCode" :label="countryLabel" />
        <h3 class="text-subtitle-2">{{ t('creator.ui.wizard.fields.primary_language') }}</h3>
        <LanguageList
          :primary-label="primaryLanguageLabel"
          :secondary-labels="secondaryLanguageLabels"
        />
        <h3 class="text-subtitle-2">{{ t('creator.ui.wizard.fields.categories') }}</h3>
        <CategoryChips :labels="categoryLabels" />
      </div>

      <div
        v-if="submitErrorMessage !== null"
        role="alert"
        class="profile-basics__error"
        data-testid="profile-submit-error"
      >
        {{ submitErrorMessage }}
      </div>

      <div class="profile-basics__actions">
        <p
          v-if="!canContinue"
          class="profile-basics__requirements text-body-2"
          data-testid="profile-requirements-hint"
        >
          {{ t('creator.ui.wizard.fields.step_requirements_hint') }}
        </p>
        <v-btn
          type="submit"
          color="primary"
          :loading="isSaving"
          :disabled="!canContinue"
          data-testid="profile-submit"
        >
          {{ t('creator.ui.wizard.actions.save_and_continue') }}
        </v-btn>
      </div>
    </v-form>
  </section>
</template>

<style scoped>
.profile-basics {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-width: 720px;
}

.profile-basics__form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.profile-basics__bio-preview {
  padding: 12px 16px;
  border: 1px solid rgb(var(--v-theme-outline-variant, var(--v-theme-outline)));
  border-radius: 6px;
  background-color: rgb(var(--v-theme-surface));
  font-size: 0.9375rem;
  line-height: 1.6;
}

.profile-basics__preview {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 16px;
  border: 1px solid rgb(var(--v-theme-outline-variant, var(--v-theme-outline)));
  border-radius: 6px;
  background-color: rgb(var(--v-theme-surface-variant));
}

.profile-basics__error {
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}

.profile-basics__avatar-note {
  text-align: center;
  margin-top: -4px;
  color: rgb(var(--v-theme-on-surface-variant));
}

.profile-basics__avatar-note--missing {
  color: rgb(var(--v-theme-error));
}

.profile-basics__actions {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 8px;
}

.profile-basics__requirements {
  color: rgb(var(--v-theme-on-surface-variant));
}
</style>
