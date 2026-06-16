<script setup lang="ts">
/**
 * Admin Creator Detail Page — Creator drill-in with per-field edit.
 *
 * Initial scaffolding: Sprint 3 Chunk 3 sub-step 9 (read-only).
 * Per-field edit affordance: Sprint 3 Chunk 4 sub-step 9.
 *
 * Each of the 7 editable fields (`display_name`, `bio`, `country_code`,
 * `region`, `primary_language`, `secondary_languages`, `categories`)
 * is wrapped in {@link EditFieldRow}. Clicking the pencil opens
 * {@link EditFieldModal} with the matching {@link EditFieldConfig}.
 * The page owns the PATCH call so the modal stays decoupled from the
 * `adminCreatorsApi` and is easier to unit-test in isolation.
 * On save success the response envelope replaces the local `creator`
 * ref — the backend re-renders `CreatorResource::withAdmin(true)` so
 * the admin_attributes block (rejection_reason + kyc_verifications)
 * stays fresh too.
 *
 * Decision C1 stays honored: each row's *display* (chips, badges,
 * country block, language list) renders via the same shared
 * components used by the wizard's preview section
 * (`@catalyst/ui` — CategoryChips, CountryDisplay, LanguageList).
 * The form-side input controls live inline in
 * `EditFieldModal.vue` (form-main; admin re-implements the input
 * shells because the wizard's controls are not yet a shared package).
 *
 * `application_status` is intentionally NOT editable via this surface
 * (Decision E2=b; backend `AdminUpdateCreatorRequest` rejects it with
 * `creator.admin.field_status_immutable`). Approve / reject buttons
 * land in sub-step 10.
 *
 * Tenancy: same as the read pass — `GET /api/v1/admin/creators/{ulid}`
 * and `PATCH /api/v1/admin/creators/{ulid}` (path-scoped admin tooling
 * category in `docs/security/tenancy.md § 4`). Backend gates via
 * `auth:web_admin` + EnsureMfaForAdmins + `CreatorPolicy::view` /
 * `CreatorPolicy::update`.
 *
 * a11y (F2=b): edit buttons carry an accessible name composed of
 * "Edit" + the field label; section headings remain `<h2>`s.
 */

import { formatDateTime, ApiError, languageEndonym } from '@catalyst/api-client'
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

import ApproveCreatorDialog from '../components/ApproveCreatorDialog.vue'
import CreatorPaymentSection from '../components/CreatorPaymentSection.vue'
import EditFieldModal from '../components/EditFieldModal.vue'
import EditFieldRow from '../components/EditFieldRow.vue'
import RejectCreatorDialog from '../components/RejectCreatorDialog.vue'
import VerifyIdentityDialog from '../components/VerifyIdentityDialog.vue'
import { FIELD_EDIT_CONFIG, type EditFieldConfig } from '../config/field-edit'
import {
  adminCreatorsApi,
  type AdminCreatorAssignment,
  type AdminCreatorAuditLog,
  type AdminEditableField,
} from '../api/creators.api'

type AdminCreatorPayload = CreatorResource

const { t, locale } = useI18n()
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
  return languageEndonym(lang)
})

const secondaryLanguageLabels = computed(() => {
  const langs = creator.value?.attributes.secondary_languages ?? []
  return langs.map((code) => languageEndonym(code))
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
    // The `media` disk is private; we MUST read the backend-minted
    // signed URLs rather than constructing one from `*_path`. Fall
    // back to the full-size `view_url` only for images — a video's
    // `view_url` is the raw media file and is NOT a valid `<img src>`
    // (it would render a broken tile), so leave it null and let the
    // gallery show its play-badge placeholder.
    thumbnailUrl: item.thumbnail_view_url ?? (item.kind === 'image' ? item.view_url : null),
    viewUrl: item.view_url,
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

const creatorEmail = computed(() => creator.value?.admin_attributes?.email ?? null)
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

// ─── Read-only history (Sprint 13, D-4) ────────────────────────────────
const assignments = ref<AdminCreatorAssignment[]>([])
const assignmentsError = ref<string | null>(null)
const auditLogs = ref<AdminCreatorAuditLog[]>([])
const auditError = ref<string | null>(null)

async function loadHistory(): Promise<void> {
  if (creatorUlid.value === '') return
  try {
    const res = await adminCreatorsApi.assignments(creatorUlid.value)
    assignments.value = res.data
  } catch (error) {
    assignmentsError.value =
      error instanceof ApiError ? error.code : 'admin.creators.detail.assignments.load_failed'
  }
  try {
    const res = await adminCreatorsApi.auditLogs(creatorUlid.value)
    auditLogs.value = res.data
  } catch (error) {
    auditError.value =
      error instanceof ApiError ? error.code : 'admin.creators.detail.audit.load_failed'
  }
}

function formatHistoryDate(iso: string | null): string {
  return formatDateTime(iso, locale.value)
}

onMounted(() => {
  void load()
  void loadHistory()
})

const editingField = ref<AdminEditableField | null>(null)
const editModalOpen = ref(false)
const editErrorKey = ref<string | null>(null)
const isSavingEdit = ref(false)
const savedSnackbarOpen = ref(false)
const savedSnackbarField = ref<AdminEditableField | null>(null)

const editingConfig = computed<EditFieldConfig | null>(() =>
  editingField.value === null ? null : FIELD_EDIT_CONFIG[editingField.value],
)

const editingCurrentValue = computed<unknown>(() => {
  if (editingField.value === null || creator.value === null) return null
  const attrs = creator.value.attributes
  switch (editingField.value) {
    case 'display_name':
      return attrs.display_name ?? ''
    case 'bio':
      return attrs.bio ?? ''
    case 'country_code':
      return attrs.country_code ?? null
    case 'region':
      return attrs.region ?? ''
    case 'primary_language':
      return attrs.primary_language ?? null
    case 'secondary_languages':
      return [...(attrs.secondary_languages ?? [])]
    case 'categories':
      return [...(attrs.categories ?? [])]
    default:
      return null
  }
})

const savedSnackbarText = computed(() => {
  if (savedSnackbarField.value === null) return ''
  const config = FIELD_EDIT_CONFIG[savedSnackbarField.value]
  return t('admin.creators.detail.edit.saved', { field: t(config.labelKey) })
})

function openEdit(field: AdminEditableField): void {
  editingField.value = field
  editErrorKey.value = null
  editModalOpen.value = true
}

function closeEdit(): void {
  editModalOpen.value = false
  editingField.value = null
  editErrorKey.value = null
}

async function handleEditSave(payload: {
  field: AdminEditableField
  value: unknown
  reason: string | null
}): Promise<void> {
  if (creator.value === null) return
  isSavingEdit.value = true
  editErrorKey.value = null
  try {
    const envelope = await adminCreatorsApi.updateField(
      creatorUlid.value,
      payload.field,
      payload.value,
      payload.reason,
    )
    creator.value = envelope.data as AdminCreatorPayload
    savedSnackbarField.value = payload.field
    savedSnackbarOpen.value = true
    closeEdit()
  } catch (error) {
    editErrorKey.value =
      error instanceof ApiError ? error.code : 'admin.creators.detail.edit.save_failed'
  } finally {
    isSavingEdit.value = false
  }
}

const approveDialogOpen = ref(false)
const rejectDialogOpen = ref(false)
const approveErrorKey = ref<string | null>(null)
const rejectErrorKey = ref<string | null>(null)
const isApproving = ref(false)
const isRejecting = ref(false)
const decisionSnackbarOpen = ref(false)
const decisionSnackbarKey = ref<string | null>(null)

const creatorDisplayName = computed(
  () => creator.value?.attributes.display_name ?? t('admin.creators.detail.fallback_title'),
)

const applicationStatus = computed(() => creator.value?.attributes.application_status ?? null)

/**
 * Approve / reject affordance gating (Decision E2=b + Sprint 4 Chunk 3
 * KYC gate).
 *
 *   - approved → both buttons hidden (terminal state; idempotency
 *     would 409 anyway).
 *   - rejected → "Approve" allowed (the backend resets rejected_at /
 *     rejection_reason). "Reject" hidden.
 *   - pending / incomplete → "Reject" visible; "Approve" only when KYC
 *     is cleared (see isKycCleared below — D-c3-7 / D-NEW-1).
 *
 * Backend retains final authority (422 `creator.kyc_not_verified` on an
 * un-cleared approve attempt).
 */
/**
 * Identity / KYC gate state (Sprint 4 Chunk 3).
 *
 *   - isKycCleared: kyc_status is `verified` (vendor/manual) OR
 *     `not_required` (flag-OFF terminal). This is the approve
 *     precondition (D-c3-7 / D-NEW-1) — mirrors the backend gate 1:1.
 *   - isKycVerified: specifically `verified`; once true the manual-verify
 *     affordance is hidden (re-verify would 409).
 *   - kycVendorAvailable: backend-driven (admin_attributes) — false when
 *     no real KYC vendor adapter is wired; drives the disabled vendor
 *     affordance (D-c3-6).
 */
const kycStatus = computed(() => creator.value?.attributes.kyc_status ?? 'none')
const isKycCleared = computed(
  () => kycStatus.value === 'verified' || kycStatus.value === 'not_required',
)
const isKycVerified = computed(() => kycStatus.value === 'verified')
const kycVendorAvailable = computed(
  () => creator.value?.admin_attributes?.kyc_vendor_available ?? false,
)
const kycMethod = computed(() => creator.value?.admin_attributes?.kyc_method ?? null)

/**
 * Approve requires KYC cleared (D-c3-7) on top of the existing
 * not-already-approved check. The backend re-validates as SOT (422
 * `creator.kyc_not_verified`); gating the affordance keeps the admin
 * from a guaranteed-fail click.
 */
const canApprove = computed(
  () =>
    applicationStatus.value !== null &&
    applicationStatus.value !== 'approved' &&
    isKycCleared.value,
)
const canReject = computed(
  () =>
    applicationStatus.value !== null &&
    applicationStatus.value !== 'approved' &&
    applicationStatus.value !== 'rejected',
)

function openApprove(): void {
  approveErrorKey.value = null
  approveDialogOpen.value = true
}

function closeApprove(): void {
  approveDialogOpen.value = false
  approveErrorKey.value = null
}

function openReject(): void {
  rejectErrorKey.value = null
  rejectDialogOpen.value = true
}

function closeReject(): void {
  rejectDialogOpen.value = false
  rejectErrorKey.value = null
}

async function handleApproveConfirm(payload: { welcomeMessage: string | null }): Promise<void> {
  if (creator.value === null) return
  isApproving.value = true
  approveErrorKey.value = null
  try {
    const envelope = await adminCreatorsApi.approve(creatorUlid.value, payload.welcomeMessage)
    creator.value = envelope.data as AdminCreatorPayload
    decisionSnackbarKey.value = 'admin.creators.detail.approve.success'
    decisionSnackbarOpen.value = true
    closeApprove()
  } catch (error) {
    approveErrorKey.value =
      error instanceof ApiError ? error.code : 'admin.creators.detail.approve.failed'
  } finally {
    isApproving.value = false
  }
}

async function handleRejectConfirm(payload: { rejectionReason: string }): Promise<void> {
  if (creator.value === null) return
  isRejecting.value = true
  rejectErrorKey.value = null
  try {
    const envelope = await adminCreatorsApi.reject(creatorUlid.value, payload.rejectionReason)
    creator.value = envelope.data as AdminCreatorPayload
    decisionSnackbarKey.value = 'admin.creators.detail.reject.success'
    decisionSnackbarOpen.value = true
    closeReject()
  } catch (error) {
    rejectErrorKey.value =
      error instanceof ApiError ? error.code : 'admin.creators.detail.reject.failed'
  } finally {
    isRejecting.value = false
  }
}

const verifyDialogOpen = ref(false)
const verifyErrorKey = ref<string | null>(null)
const isVerifying = ref(false)

function openVerify(): void {
  verifyErrorKey.value = null
  verifyDialogOpen.value = true
}

function closeVerify(): void {
  verifyDialogOpen.value = false
  verifyErrorKey.value = null
}

async function handleVerifyConfirm(payload: { note: string | null }): Promise<void> {
  if (creator.value === null) return
  isVerifying.value = true
  verifyErrorKey.value = null
  try {
    const envelope = await adminCreatorsApi.verifyIdentity(creatorUlid.value, payload.note)
    creator.value = envelope.data as AdminCreatorPayload
    decisionSnackbarKey.value = 'admin.creators.detail.verify.success'
    decisionSnackbarOpen.value = true
    closeVerify()
  } catch (error) {
    verifyErrorKey.value =
      error instanceof ApiError ? error.code : 'admin.creators.detail.verify.failed'
  } finally {
    isVerifying.value = false
  }
}

const decisionSnackbarText = computed(() =>
  decisionSnackbarKey.value === null ? '' : t(decisionSnackbarKey.value),
)

const decisionSnackbarColor = computed(() =>
  decisionSnackbarKey.value === 'admin.creators.detail.reject.success' ? 'warning' : 'success',
)
</script>

<template>
  <section class="admin-creator-detail" data-testid="admin-creator-detail">
    <header class="admin-creator-detail__header">
      <div class="admin-creator-detail__header-text">
        <h1 class="text-h4">
          {{ creator?.attributes.display_name ?? t('admin.creators.detail.fallback_title') }}
        </h1>
        <a
          v-if="creatorEmail"
          :href="`mailto:${creatorEmail}`"
          class="admin-creator-detail__email"
          data-testid="admin-creator-detail-email"
        >
          {{ creatorEmail }}
        </a>
        <p v-if="creator?.attributes.application_status" class="text-body-2 text-medium-emphasis">
          {{
            t('admin.creators.detail.application_status', {
              status: creator.attributes.application_status,
            })
          }}
        </p>
      </div>
      <div v-if="creator !== null" class="admin-creator-detail__header-actions">
        <v-btn
          v-if="canApprove"
          color="success"
          variant="elevated"
          data-testid="admin-creator-detail-approve"
          @click="openApprove"
        >
          {{ t('admin.creators.detail.approve.button') }}
        </v-btn>
        <v-btn
          v-if="canReject"
          color="error"
          variant="outlined"
          data-testid="admin-creator-detail-reject"
          @click="openReject"
        >
          {{ t('admin.creators.detail.reject.button') }}
        </v-btn>
      </div>
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

        <EditFieldRow
          label-key="admin.creators.detail.fields.display_name"
          test-id="admin-creator-detail-row-display_name"
          @edit="openEdit('display_name')"
        >
          <span data-testid="admin-creator-detail-value-display_name">
            {{ creator.attributes.display_name }}
          </span>
        </EditFieldRow>

        <EditFieldRow
          label-key="admin.creators.detail.fields.bio"
          test-id="admin-creator-detail-row-bio"
          @edit="openEdit('bio')"
        >
          <span data-testid="admin-creator-detail-value-bio">
            {{ creator.attributes.bio ?? '' }}
          </span>
        </EditFieldRow>

        <EditFieldRow
          label-key="admin.creators.detail.fields.country_code"
          test-id="admin-creator-detail-row-country_code"
          @edit="openEdit('country_code')"
        >
          <CountryDisplay :code="creator.attributes.country_code" :label="countryLabel" />
        </EditFieldRow>

        <EditFieldRow
          label-key="admin.creators.detail.fields.region"
          test-id="admin-creator-detail-row-region"
          @edit="openEdit('region')"
        >
          <span data-testid="admin-creator-detail-value-region">
            {{ creator.attributes.region ?? '' }}
          </span>
        </EditFieldRow>

        <EditFieldRow
          label-key="admin.creators.detail.fields.primary_language"
          test-id="admin-creator-detail-row-primary_language"
          @edit="openEdit('primary_language')"
        >
          <LanguageList :primary-label="primaryLanguageLabel" :secondary-labels="[]" />
        </EditFieldRow>

        <EditFieldRow
          label-key="admin.creators.detail.fields.secondary_languages"
          test-id="admin-creator-detail-row-secondary_languages"
          @edit="openEdit('secondary_languages')"
        >
          <LanguageList :primary-label="null" :secondary-labels="secondaryLanguageLabels" />
        </EditFieldRow>

        <EditFieldRow
          label-key="admin.creators.detail.fields.categories"
          test-id="admin-creator-detail-row-categories"
          @edit="openEdit('categories')"
        >
          <CategoryChips :labels="categoryLabels" />
        </EditFieldRow>
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

      <section class="admin-creator-detail__section">
        <h2 class="text-h6">{{ t('admin.creators.detail.onboarding_heading') }}</h2>
        <div class="admin-creator-detail__status-grid">
          <div class="admin-creator-detail__status-card" data-testid="admin-creator-detail-kyc">
            <span class="admin-creator-detail__status-label">
              {{ t('creator.ui.wizard.steps.kyc.name') }}
            </span>
            <KycStatusBadge :status="creator.attributes.kyc_status" :label="kycStatusLabel" />
          </div>
          <div class="admin-creator-detail__status-card" data-testid="admin-creator-detail-tax">
            <span class="admin-creator-detail__status-label">
              {{ t('creator.ui.wizard.steps.tax.name') }}
            </span>
            <TaxProfileDisplay
              :is-complete="creator.attributes.tax_profile_complete"
              :label="taxLabel"
            />
          </div>
          <div class="admin-creator-detail__status-card" data-testid="admin-creator-detail-payout">
            <span class="admin-creator-detail__status-label">
              {{ t('creator.ui.wizard.steps.payout.name') }}
            </span>
            <PayoutMethodStatus
              :is-set="creator.attributes.payout_method_set"
              :label="payoutLabel"
            />
          </div>
          <div
            class="admin-creator-detail__status-card"
            data-testid="admin-creator-detail-contract"
          >
            <span class="admin-creator-detail__status-label">
              {{ t('creator.ui.wizard.steps.contract.name') }}
            </span>
            <ContractStatusBadge :status="contractStatus" :label="contractLabel" />
          </div>
        </div>
      </section>

      <section class="admin-creator-detail__section" data-testid="admin-creator-detail-identity">
        <h2 class="text-h6">{{ t('admin.creators.detail.identity.heading') }}</h2>
        <p class="text-body-2 text-medium-emphasis">
          {{ t('admin.creators.detail.identity.status', { status: kycStatusLabel }) }}
          <span v-if="kycMethod !== null" data-testid="admin-creator-detail-identity-method">
            ·
            {{ t(`admin.creators.detail.identity.method_labels.${kycMethod}`) }}
          </span>
        </p>
        <div class="admin-creator-detail__identity-actions">
          <v-btn
            v-if="!isKycVerified"
            color="primary"
            variant="elevated"
            data-testid="admin-creator-detail-verify-manual"
            @click="openVerify"
          >
            {{ t('admin.creators.detail.identity.verify_manual') }}
          </v-btn>
          <v-tooltip
            v-if="!kycVendorAvailable"
            location="top"
            :text="t('admin.creators.detail.identity.vendor_disabled_tooltip')"
          >
            <template #activator="{ props: tooltipProps }">
              <!-- Disabled affordance, not dead code (D-c3-6): no backend
                   wiring — activates when a vendor adapter lands. -->
              <span v-bind="tooltipProps">
                <v-btn variant="outlined" disabled data-testid="admin-creator-detail-verify-vendor">
                  {{ t('admin.creators.detail.identity.verify_vendor') }}
                </v-btn>
              </span>
            </template>
          </v-tooltip>
          <v-btn
            v-else
            variant="outlined"
            disabled
            data-testid="admin-creator-detail-verify-vendor"
          >
            {{ t('admin.creators.detail.identity.verify_vendor') }}
          </v-btn>
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

      <section class="admin-creator-detail__section" data-testid="admin-creator-detail-assignments">
        <h2 class="text-h6">{{ t('admin.creators.detail.assignments_heading') }}</h2>
        <div
          v-if="assignmentsError"
          role="alert"
          class="admin-creator-detail__error"
          data-testid="admin-creator-detail-assignments-error"
        >
          {{ t(assignmentsError) }}
        </div>
        <p
          v-else-if="assignments.length === 0"
          class="text-body-2 text-medium-emphasis"
          data-testid="admin-creator-detail-assignments-empty"
        >
          {{ t('admin.creators.detail.assignments.empty') }}
        </p>
        <v-table v-else density="compact">
          <thead>
            <tr>
              <th>{{ t('admin.creators.detail.assignments.campaign') }}</th>
              <th>{{ t('admin.creators.detail.assignments.brand') }}</th>
              <th>{{ t('admin.creators.detail.assignments.agency') }}</th>
              <th>{{ t('admin.creators.detail.assignments.status') }}</th>
              <th>{{ t('admin.creators.detail.assignments.created_at') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in assignments"
              :key="row.id"
              :data-testid="`admin-creator-detail-assignment-row-${row.id}`"
            >
              <td>{{ row.attributes.campaign_name ?? '—' }}</td>
              <td>{{ row.attributes.brand_name ?? '—' }}</td>
              <td>{{ row.attributes.agency_name ?? '—' }}</td>
              <td>
                <v-chip size="x-small" variant="tonal">{{ row.attributes.status }}</v-chip>
              </td>
              <td>{{ formatHistoryDate(row.attributes.created_at) }}</td>
            </tr>
          </tbody>
        </v-table>
      </section>

      <section class="admin-creator-detail__section" data-testid="admin-creator-detail-audit">
        <h2 class="text-h6">{{ t('admin.creators.detail.audit_heading') }}</h2>
        <div
          v-if="auditError"
          role="alert"
          class="admin-creator-detail__error"
          data-testid="admin-creator-detail-audit-error"
        >
          {{ t(auditError) }}
        </div>
        <p
          v-else-if="auditLogs.length === 0"
          class="text-body-2 text-medium-emphasis"
          data-testid="admin-creator-detail-audit-empty"
        >
          {{ t('admin.creators.detail.audit.empty') }}
        </p>
        <v-table v-else density="compact">
          <thead>
            <tr>
              <th>{{ t('admin.creators.detail.audit.action') }}</th>
              <th>{{ t('admin.creators.detail.audit.actor') }}</th>
              <th>{{ t('admin.creators.detail.audit.reason') }}</th>
              <th>{{ t('admin.creators.detail.audit.created_at') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in auditLogs"
              :key="row.id"
              :data-testid="`admin-creator-detail-audit-row-${row.id}`"
            >
              <td>{{ row.attributes.action }}</td>
              <td>{{ row.attributes.actor_name ?? row.attributes.actor_email ?? '—' }}</td>
              <td>{{ row.attributes.reason ?? '—' }}</td>
              <td>{{ formatHistoryDate(row.attributes.created_at) }}</td>
            </tr>
          </tbody>
        </v-table>
      </section>

      <!-- Sprint 13 D-13: discrete swappable payment block (coming-soon). -->
      <CreatorPaymentSection />
    </template>

    <EditFieldModal
      v-if="editingConfig !== null"
      v-model="editModalOpen"
      :config="editingConfig"
      :current-value="editingCurrentValue"
      :error-key="editErrorKey"
      :is-saving="isSavingEdit"
      @save="handleEditSave"
      @cancel="closeEdit"
    />

    <ApproveCreatorDialog
      v-model="approveDialogOpen"
      :is-saving="isApproving"
      :error-key="approveErrorKey"
      :creator-display-name="creatorDisplayName"
      @confirm="handleApproveConfirm"
      @cancel="closeApprove"
    />

    <RejectCreatorDialog
      v-model="rejectDialogOpen"
      :is-saving="isRejecting"
      :error-key="rejectErrorKey"
      :creator-display-name="creatorDisplayName"
      @confirm="handleRejectConfirm"
      @cancel="closeReject"
    />

    <VerifyIdentityDialog
      v-model="verifyDialogOpen"
      :is-saving="isVerifying"
      :error-key="verifyErrorKey"
      :creator-display-name="creatorDisplayName"
      @confirm="handleVerifyConfirm"
      @cancel="closeVerify"
    />

    <v-snackbar
      v-model="savedSnackbarOpen"
      :timeout="3000"
      color="success"
      data-testid="admin-creator-detail-saved-snackbar"
    >
      {{ savedSnackbarText }}
    </v-snackbar>

    <v-snackbar
      v-model="decisionSnackbarOpen"
      :timeout="3000"
      :color="decisionSnackbarColor"
      data-testid="admin-creator-detail-decision-snackbar"
    >
      {{ decisionSnackbarText }}
    </v-snackbar>
  </section>
</template>

<style scoped>
.admin-creator-detail {
  display: flex;
  flex-direction: column;
  gap: 24px;
  max-width: 960px;
}

.admin-creator-detail__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}

.admin-creator-detail__header-text {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.admin-creator-detail__email {
  font-size: 0.875rem;
  color: rgb(var(--v-theme-primary));
  text-decoration: none;
  width: fit-content;
}

.admin-creator-detail__email:hover {
  text-decoration: underline;
}

.admin-creator-detail__header-actions {
  display: flex;
  gap: 8px;
  align-items: center;
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
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
}

.admin-creator-detail__status-card {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
  padding: 12px 16px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
  background: rgb(var(--v-theme-surface));
}

.admin-creator-detail__status-label {
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  overflow-wrap: anywhere;
}

.admin-creator-detail__identity-actions {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}
</style>
