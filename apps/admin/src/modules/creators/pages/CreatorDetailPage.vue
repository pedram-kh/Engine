<script setup lang="ts">
/**
 * Admin Creator Detail Page — read-only view of a single Creator.
 *
 * Sprint 3 Chunk 3 sub-step 9. The page reuses every display-only
 * shared component from `@catalyst/ui` so the admin surface and
 * the creator-self wizard surface render the same data with
 * identical UI (Decision C1: display-shared, form-main).
 *
 * Per pause-condition-6 the page is intentionally READ-ONLY in
 * Sprint 3: per-field admin edit modals + PATCH endpoint + audit +
 * idempotency land in Chunk 4 alongside the approve/reject
 * workflow. The "Edit" affordance is deliberately absent here.
 *
 * Tenancy: this page hits `GET /api/v1/admin/creators/{ulid}`
 * (path-scoped admin tooling category in docs/security/tenancy.md
 * § 4 — Refinement 3 F1-style audit allowlist entry lands with
 * sub-step 12's doc fix-ups). The backend gates via
 * `auth:web_admin` + EnsureMfaForAdmins, mirrors `CreatorPolicy::view`.
 *
 * a11y (F2=b): each section is a `<section>` with an `<h2>`
 * heading; the chips inside the per-step status blocks carry
 * accessible names via the shared components.
 */

import { ApiError } from '@catalyst/api-client'
import type {
  CreatorKycVerificationSummary,
  CreatorResource,
  CreatorSocialAccountSummary,
} from '@catalyst/api-client'
import {
  CategoryChips,
  CompletenessBar,
  ContractStatusBadge,
  CountryDisplay,
  KycStatusBadge,
  LanguageList,
  PayoutMethodStatus,
  PortfolioGallery,
  SocialAccountList,
  TaxProfileDisplay,
} from '@catalyst/ui'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import { adminCreatorsApi } from '../api/creators.api'

type AdminCreatorPayload = CreatorResource

const { t } = useI18n()
const route = useRoute()

const creator = ref<AdminCreatorPayload | null>(null)
const isLoading = ref(false)
const errorKey = ref<string | null>(null)

const creatorUlid = computed(() => String(route.params.ulid ?? ''))

const countryLabel = computed(() => {
  const cc = creator.value?.attributes.country_code
  if (!cc) return ''
  return t(`countries.${cc}`, cc)
})

const categoryLabels = computed(() => {
  const cats = creator.value?.attributes.categories ?? []
  return cats.map((category) => t(`creator.ui.wizard.categories.${category}`, category))
})

const primaryLanguageLabel = computed(() => {
  const lang = creator.value?.attributes.primary_language
  if (!lang) return null
  return t(`languages.${lang}`, lang)
})

const secondaryLanguageLabels = computed(() => {
  const langs = creator.value?.attributes.secondary_languages ?? []
  return langs.map((code) => t(`languages.${code}`, code))
})

const socialAccountRows = computed(() => {
  const raw: ReadonlyArray<CreatorSocialAccountSummary> =
    creator.value?.attributes.social_accounts ?? []
  return raw.map((account) => ({
    platform: account.platform,
    handle: account.handle,
    profileUrl: account.profile_url,
    platformLabel: t(`creator.ui.wizard.social_platforms.${account.platform}`, account.platform),
  }))
})

const portfolioItems = computed(() => {
  const items = creator.value?.attributes.portfolio ?? []
  return items.map((item) => ({
    id: item.id,
    kind: item.kind,
    title: item.title,
    description: item.description,
    thumbnailUrl: item.thumbnail_path ?? item.s3_path,
    externalUrl: item.external_url,
    altText: item.title ?? t('creator.ui.wizard.steps.portfolio.untitled_item'),
  }))
})

const score = computed(() => creator.value?.attributes.profile_completeness_score ?? 0)
const completenessLabel = computed(() =>
  t('admin.creators.detail.completeness', { percent: score.value }),
)

const kycStatusLabel = computed(() => {
  const status = creator.value?.attributes.kyc_status ?? 'none'
  return t(`creator.ui.wizard.steps.kyc.status_labels.${status}`)
})

const payoutLabel = computed(() =>
  t(
    creator.value?.attributes.payout_method_set
      ? 'creator.ui.wizard.steps.payout.status_set'
      : 'creator.ui.wizard.steps.payout.status_unset',
  ),
)

const contractStatus = computed<'signed' | 'click_through_accepted' | 'none'>(() => {
  if (creator.value?.attributes.has_signed_master_contract) return 'signed'
  if (creator.value?.attributes.click_through_accepted_at) return 'click_through_accepted'
  return 'none'
})
const contractLabel = computed(() =>
  t(`creator.ui.wizard.steps.contract.status_labels.${contractStatus.value}`),
)

const taxLabel = computed(() =>
  t(
    creator.value?.attributes.tax_profile_complete
      ? 'creator.ui.wizard.steps.tax.status_complete'
      : 'creator.ui.wizard.steps.tax.status_incomplete',
  ),
)

const rejectionReason = computed(() => creator.value?.admin_attributes?.rejection_reason ?? null)
const kycVerifications = computed<ReadonlyArray<CreatorKycVerificationSummary>>(
  () => creator.value?.admin_attributes?.kyc_verifications ?? [],
)

async function load(): Promise<void> {
  if (creatorUlid.value === '') return
  isLoading.value = true
  errorKey.value = null
  try {
    const envelope = await adminCreatorsApi.show(creatorUlid.value)
    creator.value = envelope.data as AdminCreatorPayload
  } catch (error) {
    errorKey.value = error instanceof ApiError ? error.code : 'admin.creators.detail.load_failed'
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="admin-creator-detail" data-testid="admin-creator-detail">
    <header class="admin-creator-detail__header">
      <h1 class="text-h4">
        {{ creator?.attributes.display_name ?? t('admin.creators.detail.fallback_title') }}
      </h1>
      <p v-if="creator?.attributes.application_status" class="text-body-2 text-medium-emphasis">
        {{
          t('admin.creators.detail.application_status', {
            status: creator.attributes.application_status,
          })
        }}
      </p>
    </header>

    <div
      v-if="errorKey"
      role="alert"
      class="admin-creator-detail__error"
      data-testid="admin-creator-detail-error"
    >
      {{ t(errorKey) }}
    </div>

    <v-progress-circular
      v-if="isLoading && creator === null"
      indeterminate
      color="primary"
      data-testid="admin-creator-detail-loading"
    />

    <template v-else-if="creator !== null">
      <CompletenessBar :score="score" :label="completenessLabel" />

      <section
        v-if="rejectionReason"
        class="admin-creator-detail__rejection"
        data-testid="admin-creator-detail-rejection"
      >
        <h2 class="text-h6">{{ t('admin.creators.detail.rejection_heading') }}</h2>
        <p>{{ rejectionReason }}</p>
      </section>

      <section class="admin-creator-detail__section">
        <h2 class="text-h6">{{ t('admin.creators.detail.profile_heading') }}</h2>
        <p v-if="creator.attributes.bio" class="text-body-2">
          {{ creator.attributes.bio }}
        </p>
        <div class="admin-creator-detail__row">
          <span>{{ t('creator.ui.wizard.fields.country') }}:</span>
          <CountryDisplay :code="creator.attributes.country_code" :label="countryLabel" />
        </div>
        <div class="admin-creator-detail__row">
          <span>{{ t('creator.ui.wizard.fields.categories') }}:</span>
          <CategoryChips :labels="categoryLabels" />
        </div>
        <div class="admin-creator-detail__row">
          <LanguageList
            :primary-label="primaryLanguageLabel"
            :secondary-labels="secondaryLanguageLabels"
          />
        </div>
      </section>

      <section class="admin-creator-detail__section">
        <h2 class="text-h6">{{ t('admin.creators.detail.social_heading') }}</h2>
        <SocialAccountList :accounts="socialAccountRows" />
      </section>

      <section class="admin-creator-detail__section">
        <h2 class="text-h6">{{ t('admin.creators.detail.portfolio_heading') }}</h2>
        <PortfolioGallery
          :items="portfolioItems"
          :editable="false"
          :empty-label="t('creator.ui.wizard.steps.portfolio.gallery_empty')"
          :video-label="t('creator.ui.wizard.steps.portfolio.video_badge_label')"
          :link-label="t('creator.ui.wizard.steps.portfolio.link_badge_label')"
        />
      </section>

      <section class="admin-creator-detail__section admin-creator-detail__status-grid">
        <div data-testid="admin-creator-detail-kyc">
          <h2 class="text-h6">{{ t('creator.ui.wizard.steps.kyc.name') }}</h2>
          <KycStatusBadge :status="creator.attributes.kyc_status" :label="kycStatusLabel" />
        </div>
        <div data-testid="admin-creator-detail-tax">
          <h2 class="text-h6">{{ t('creator.ui.wizard.steps.tax.name') }}</h2>
          <TaxProfileDisplay
            :is-complete="creator.attributes.tax_profile_complete"
            :label="taxLabel"
          />
        </div>
        <div data-testid="admin-creator-detail-payout">
          <h2 class="text-h6">{{ t('creator.ui.wizard.steps.payout.name') }}</h2>
          <PayoutMethodStatus :is-set="creator.attributes.payout_method_set" :label="payoutLabel" />
        </div>
        <div data-testid="admin-creator-detail-contract">
          <h2 class="text-h6">{{ t('creator.ui.wizard.steps.contract.name') }}</h2>
          <ContractStatusBadge :status="contractStatus" :label="contractLabel" />
        </div>
      </section>

      <section
        v-if="kycVerifications.length > 0"
        class="admin-creator-detail__section"
        data-testid="admin-creator-detail-kyc-history"
      >
        <h2 class="text-h6">{{ t('admin.creators.detail.kyc_history_heading') }}</h2>
        <v-table density="compact">
          <thead>
            <tr>
              <th>{{ t('admin.creators.detail.kyc_history.provider') }}</th>
              <th>{{ t('admin.creators.detail.kyc_history.status') }}</th>
              <th>{{ t('admin.creators.detail.kyc_history.started_at') }}</th>
              <th>{{ t('admin.creators.detail.kyc_history.completed_at') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in kycVerifications"
              :key="row.id"
              :data-testid="`admin-creator-detail-kyc-row-${row.id}`"
            >
              <td>{{ row.provider }}</td>
              <td>{{ row.status }}</td>
              <td>{{ row.started_at }}</td>
              <td>{{ row.completed_at }}</td>
            </tr>
          </tbody>
        </v-table>
      </section>
    </template>
  </section>
</template>

<style scoped>
.admin-creator-detail {
  display: flex;
  flex-direction: column;
  gap: 24px;
  max-width: 960px;
}

.admin-creator-detail__error {
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}

.admin-creator-detail__section {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.admin-creator-detail__rejection {
  padding: 12px 16px;
  border: 1px solid rgb(var(--v-theme-error));
  border-radius: 6px;
}

.admin-creator-detail__row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.admin-creator-detail__status-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
}
</style>
