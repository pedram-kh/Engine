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

import ApproveCreatorDialog from '../components/ApproveCreatorDialog.vue'
import EditFieldModal from '../components/EditFieldModal.vue'
import EditFieldRow from '../components/EditFieldRow.vue'
import RejectCreatorDialog from '../components/RejectCreatorDialog.vue'
import { FIELD_EDIT_CONFIG, type EditFieldConfig } from '../config/field-edit'
import { adminCreatorsApi, type AdminEditableField } from '../api/creators.api'

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
 * Approve / reject affordance gating (Decision E2=b).
 *
 *   - approved → both buttons hidden (terminal state; idempotency
 *     would 409 anyway).
 *   - rejected → "Approve" is allowed (admins can move rejected back
 *     to approved via the dedicated approve workflow; the backend
 *     resets rejected_at / rejection_reason). "Reject" hidden.
 *   - pending / incomplete → both buttons visible.
 *
 * `incomplete` is technically pre-submission but admins may want to
 * approve a creator who only partially completed the wizard during
 * Phase-1 manual-bootstrapping flows (Sprint 1 chunk-2.4 supports
 * this via backend); leaving the affordance visible keeps the admin
 * unblocked. Backend retains final authority.
 */
const canApprove = computed(
  () => applicationStatus.value !== null && applicationStatus.value !== 'approved',
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
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
}
</style>
