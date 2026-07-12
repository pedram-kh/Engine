<script setup lang="ts">
/**
 * Campaign detail page (Sprint 8 Chunk 1) — the app's FIRST tabbed
 * (v-tabs + v-window) surface.
 *
 *   - Overview  — campaign summary (live).
 *   - Creators  — the assignment list (live, read-only; empty until Chunk 2
 *                 wires inviting).
 *   - Settings  — config edit (live; admin/manager only — `canEdit`).
 *   - Board / Drafts / Messages — live tabs (lazy-mounted where noted).
 *   - Payments — empty-state "coming soon".
 */

import {
  formatCurrency,
  ApiError,
  extractFieldErrors,
  type CampaignAssignmentResource,
  type CampaignResource,
  type CampaignStatus,
  type CreateCampaignPayload,
  type UpdateCampaignPayload,
} from '@catalyst/api-client'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'

import { CEmptyState } from '@catalyst/ui'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { campaignsApi } from '../api/campaigns.api'
import CampaignForm from '../components/CampaignForm.vue'
import InviteCreatorsDialog from '../components/InviteCreatorsDialog.vue'
import AttachContractDialog from '../components/AttachContractDialog.vue'
import ReinviteDialog from '../components/ReinviteDialog.vue'
import ReviewDraftDrawer from '../components/ReviewDraftDrawer.vue'
import ResolveVerificationDrawer from '../components/ResolveVerificationDrawer.vue'
import ViewPostedContentDrawer from '../components/ViewPostedContentDrawer.vue'
import CampaignMessagesPanel from '@/modules/messaging/components/CampaignMessagesPanel.vue'
import BoardView from '@/modules/boards/components/BoardView.vue'
import DraftsTab from '../components/DraftsTab.vue'

const { t, locale } = useI18n()
const route = useRoute()
const agencyStore = useAgencyStore()
const { currentRole } = storeToRefs(agencyStore)

const canEdit = computed(
  () => currentRole.value === 'agency_admin' || currentRole.value === 'agency_manager',
)

// The execute ability (D-6) — inviting is broader than editing: admin +
// manager + STAFF can invite creators to a campaign.
const canInvite = computed(
  () =>
    currentRole.value === 'agency_admin' ||
    currentRole.value === 'agency_manager' ||
    currentRole.value === 'agency_staff',
)

const canAttachContract = canInvite

const inviteDialog = ref(false)
const inviteSnackbar = ref<string | null>(null)

function onInvited(message: string): void {
  inviteSnackbar.value = message
  void loadAssignments()
}

const reinviteDialog = ref(false)
const reinviteTarget = ref<CampaignAssignmentResource | null>(null)
const reinviteSnackbar = ref<string | null>(null)

function openReinvite(assignment: CampaignAssignmentResource): void {
  reinviteTarget.value = assignment
  reinviteDialog.value = true
}

function onReinvited(): void {
  reinviteSnackbar.value = t('app.campaigns.reinvite.success')
  void loadAssignments()
}

const attachContractDialog = ref(false)
const attachContractTarget = ref<CampaignAssignmentResource | null>(null)
const attachContractSnackbar = ref<string | null>(null)
const attachContractError = ref<string | null>(null)

function openAttachContract(assignment: CampaignAssignmentResource): void {
  attachContractTarget.value = assignment
  attachContractDialog.value = true
}

function onContractAttached(): void {
  attachContractSnackbar.value = t('app.campaigns.contract.attach.success')
  void loadAssignments()
}

function onContractAttachError(message: string): void {
  attachContractError.value = message
}

// The per-campaign manual-contract flag (D-7), read from the assignments
// list meta. Gates the agency "proceed without a contract" action together
// with the campaign's `requires_per_campaign_contract` (false → optional).
const perCampaignContractEnabled = ref(false)
const proceedSnackbar = ref<string | null>(null)
const proceedError = ref<string | null>(null)

function canProceedWithoutContract(assignment: CampaignAssignmentResource): boolean {
  return (
    assignment.attributes.status === 'accepted' &&
    assignment.attributes.has_pending_contract !== true &&
    canAttachContract.value &&
    perCampaignContractEnabled.value &&
    campaign.value?.attributes.requires_per_campaign_contract === false
  )
}

async function proceedWithoutContract(assignment: CampaignAssignmentResource): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return
  try {
    await campaignsApi.proceedWithoutContract(agencyId, ulid.value, assignment.id)
    proceedSnackbar.value = t('app.campaigns.contract.proceedWithout.success')
    void loadAssignments()
  } catch (err) {
    proceedError.value =
      err instanceof ApiError && err.code === 'assignment.per_campaign_contract_required'
        ? t('app.campaigns.contract.proceedWithout.requiredError')
        : t('app.campaigns.contract.proceedWithout.error')
  }
}

// The draft-review surface (Sprint 9 Chunk 2, D-8) — the `review` ability is
// the execute ability: admin + manager + staff (mirrors canInvite).
const canReview = canInvite
const reviewDialog = ref(false)
const reviewTarget = ref<CampaignAssignmentResource | null>(null)
const reviewSnackbar = ref<string | null>(null)

function openReview(assignment: CampaignAssignmentResource): void {
  reviewTarget.value = assignment
  reviewDialog.value = true
}

function onReviewed(message: string): void {
  reviewSnackbar.value = message
  void loadAssignments()
  if (tab.value === 'drafts') {
    void draftsTabRef.value?.reload()
  }
}

const draftsTabRef = ref<InstanceType<typeof DraftsTab> | null>(null)

// The verification-failure resolution surface (verification-resolution chunk,
// D-7). Same `review` ability as the draft review. The row action shows only
// when the assignment is `posted` AND its latest verification FAILED.
const resolveDialog = ref(false)
const resolveTarget = ref<CampaignAssignmentResource | null>(null)

function canResolveVerification(a: CampaignAssignmentResource): boolean {
  return (
    canReview.value &&
    a.attributes.status === 'posted' &&
    (a.attributes.verification_status === 'not_found' ||
      a.attributes.verification_status === 'mismatch')
  )
}

function openResolve(assignment: CampaignAssignmentResource): void {
  resolveTarget.value = assignment
  resolveDialog.value = true
}

const viewPostDialog = ref(false)
const viewPostTarget = ref<CampaignAssignmentResource | null>(null)

// Read-only "view posted content" — offered on any row that already has a
// post (`verification_status !== null`), EXCEPT when the failure-resolution
// action is offered instead (that drawer shows the post + the actions).
function canViewPost(a: CampaignAssignmentResource): boolean {
  return canReview.value && a.attributes.verification_status !== null && !canResolveVerification(a)
}

function openViewPost(assignment: CampaignAssignmentResource): void {
  viewPostTarget.value = assignment
  viewPostDialog.value = true
}

function onResolved(message: string): void {
  reviewSnackbar.value = message
  void loadAssignments()
}

const ulid = computed(() => String(route.params.ulid))
const tab = ref<string>('overview')

const campaign = ref<CampaignResource | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

const assignments = ref<CampaignAssignmentResource[]>([])
const assignmentsLoading = ref(false)

// Settings edit state.
const editForm = ref<CreateCampaignPayload | null>(null)
const editStatus = ref<CampaignStatus>('draft')
const saving = ref(false)
const saveError = ref<string | null>(null)
const saveSuccess = ref(false)
const fieldErrors = ref<Partial<Record<string, readonly string[]>>>({})

const statusOptions: { title: string; value: CampaignStatus }[] = [
  { title: t('app.campaigns.status.draft'), value: 'draft' },
  { title: t('app.campaigns.status.active'), value: 'active' },
  { title: t('app.campaigns.status.paused'), value: 'paused' },
  { title: t('app.campaigns.status.completed'), value: 'completed' },
  { title: t('app.campaigns.status.cancelled'), value: 'cancelled' },
]

const comingSoonTabs = ['payments'] as const

function toDateInput(iso: string | null): string | undefined {
  return iso ? iso.slice(0, 10) : undefined
}

function seedEditForm(c: CampaignResource): void {
  // `objective`, `target_creator_count`, and `brief` are intentionally NOT
  // seeded: the simplified form omits them on save, so the backend `sometimes`
  // rules preserve their stored values by omission (D-1/D-2/D-3). Re-seeding
  // them here would re-send them and revive the brief overwrite path.
  editForm.value = {
    brand_id: c.relationships.brand.data.id,
    name: c.attributes.name,
    budget_minor_units: c.attributes.budget_minor_units ?? 0,
    budget_currency: c.attributes.budget_currency ?? 'EUR',
    description: c.attributes.description ?? undefined,
    starts_at: toDateInput(c.attributes.starts_at),
    ends_at: toDateInput(c.attributes.ends_at),
    requires_per_campaign_contract: c.attributes.requires_per_campaign_contract,
  }
  editStatus.value = c.attributes.status
}

async function loadCampaign(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  loading.value = true
  error.value = null
  try {
    const res = await campaignsApi.show(agencyId, ulid.value)
    campaign.value = res.data
    seedEditForm(res.data)
  } catch {
    error.value = t('app.campaigns.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

async function loadAssignments(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return
  assignmentsLoading.value = true
  try {
    const res = await campaignsApi.assignments(agencyId, ulid.value)
    assignments.value = res.data
    perCampaignContractEnabled.value = res.meta?.per_campaign_contract_enabled ?? false
  } catch {
    assignments.value = []
  } finally {
    assignmentsLoading.value = false
  }
}

onMounted(() => {
  void loadCampaign()
})

watch(tab, (value) => {
  if (value === 'creators' && assignments.value.length === 0) {
    void loadAssignments()
  }
})

async function onSaveSettings(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null || editForm.value === null) return

  saving.value = true
  saveError.value = null
  saveSuccess.value = false
  fieldErrors.value = {}

  // Brand is fixed after creation — the Settings tab never re-binds brand_id.
  const { brand_id, ...rest } = editForm.value
  void brand_id
  const payload: UpdateCampaignPayload = { ...rest, status: editStatus.value }

  try {
    const res = await campaignsApi.update(agencyId, ulid.value, payload)
    campaign.value = res.data
    seedEditForm(res.data)
    saveSuccess.value = true
  } catch (err) {
    if (err instanceof ApiError) {
      const grouped = extractFieldErrors<string>(err)
      fieldErrors.value = grouped
      if (Object.keys(grouped).length === 0) {
        saveError.value = `[${err.code}] ${err.message}`
      }
    } else {
      saveError.value = t('app.campaigns.errors.saveFailed')
    }
  } finally {
    saving.value = false
  }
}

function formatMoney(minor: number | null, currency: string | null): string {
  return formatCurrency(minor, currency, locale.value)
}
</script>

<template>
  <div data-test="campaign-detail-page">
    <div class="d-flex align-center mb-4">
      <v-btn
        icon="mdi-arrow-left"
        variant="text"
        size="small"
        :to="{ name: 'campaigns.list' }"
        class="mr-2"
        :aria-label="t('app.campaigns.actions.backToList')"
      />
      <h1 class="text-h5 ma-0" data-test="campaign-detail-heading">
        {{ campaign?.attributes.name ?? t('app.campaigns.detail.title') }}
      </h1>
    </div>

    <v-alert
      v-if="error"
      type="error"
      variant="tonal"
      class="mb-4"
      data-test="campaign-detail-error"
    >
      {{ error }}
    </v-alert>

    <v-skeleton-loader
      v-if="loading && !campaign"
      type="article"
      data-test="campaign-detail-skeleton"
    />

    <template v-else-if="campaign">
      <v-tabs v-model="tab" class="mb-4" data-test="campaign-tabs">
        <v-tab value="overview" data-test="tab-overview">{{
          t('app.campaigns.tabs.overview')
        }}</v-tab>
        <v-tab value="creators" data-test="tab-creators">{{
          t('app.campaigns.tabs.creators')
        }}</v-tab>
        <v-tab value="board" data-test="tab-board">{{ t('app.campaigns.tabs.board') }}</v-tab>
        <v-tab value="drafts" data-test="tab-drafts">{{ t('app.campaigns.tabs.drafts') }}</v-tab>
        <v-tab value="payments" data-test="tab-payments">{{
          t('app.campaigns.tabs.payments')
        }}</v-tab>
        <v-tab value="messages" data-test="tab-messages">{{
          t('app.campaigns.tabs.messages')
        }}</v-tab>
        <v-tab v-if="canEdit" value="settings" data-test="tab-settings">
          {{ t('app.campaigns.tabs.settings') }}
        </v-tab>
      </v-tabs>

      <v-window v-model="tab">
        <!-- Overview -->
        <v-window-item value="overview" data-test="panel-overview">
          <v-card class="pa-4" max-width="720">
            <v-list density="comfortable">
              <v-list-item
                :title="t('app.campaigns.fields.brand')"
                :subtitle="campaign.relationships.brand.data.name"
              />
              <v-list-item
                :title="t('app.campaigns.fields.objective')"
                :subtitle="t(`app.campaigns.objective.${campaign.attributes.objective}`)"
              />
              <v-list-item :title="t('app.campaigns.fields.status')">
                <template #subtitle>
                  <v-chip size="small" variant="tonal" data-test="overview-status">
                    {{ t(`app.campaigns.status.${campaign.attributes.status}`) }}
                  </v-chip>
                </template>
              </v-list-item>
              <v-list-item
                :title="t('app.campaigns.fields.budget')"
                :subtitle="
                  formatMoney(
                    campaign.attributes.budget_minor_units,
                    campaign.attributes.budget_currency,
                  )
                "
              />
              <v-list-item
                v-if="campaign.attributes.description"
                :title="t('app.campaigns.fields.description')"
                :subtitle="campaign.attributes.description"
              />
            </v-list>
          </v-card>
        </v-window-item>

        <!-- Creators (assignment list + invite picker) -->
        <v-window-item value="creators" data-test="panel-creators">
          <div class="d-flex justify-end mb-3">
            <v-btn
              v-if="canInvite"
              color="primary"
              variant="flat"
              prepend-icon="mdi-account-plus-outline"
              data-test="invite-creators-open"
              @click="inviteDialog = true"
            >
              {{ t('app.campaigns.invite.open') }}
            </v-btn>
          </div>

          <v-skeleton-loader v-if="assignmentsLoading" type="list-item-two-line@3" />
          <CEmptyState
            v-else-if="assignments.length === 0"
            data-test="creators-empty-state"
            title-tag="h2"
            :title="t('app.campaigns.creators.empty.heading')"
            :body="t('app.campaigns.creators.empty.body')"
          >
            <template #icon>
              <v-icon icon="mdi-account-multiple-outline" size="56" color="medium-emphasis" />
            </template>
          </CEmptyState>
          <v-list v-else data-test="creators-list">
            <v-list-item v-for="a in assignments" :key="a.id" :data-test="`creators-row-${a.id}`">
              <v-list-item-title class="d-flex align-center ga-2">
                {{ a.attributes.creator?.display_name ?? '—' }}
                <v-chip size="x-small" variant="tonal" :data-test="`creators-status-${a.id}`">
                  {{ t(`app.campaigns.assignmentStatus.${a.attributes.status}`) }}
                </v-chip>
              </v-list-item-title>
              <v-list-item-subtitle :data-test="`creators-fees-${a.id}`">
                <template v-if="a.attributes.status === 'countered'">
                  {{ t('app.campaigns.fees.offered') }}:
                  {{
                    formatMoney(
                      a.attributes.agreed_fee_minor_units,
                      a.attributes.agreed_fee_currency,
                    )
                  }}
                  · {{ t('app.campaigns.fees.countered') }}:
                  {{
                    formatMoney(
                      a.attributes.countered_fee_minor_units,
                      a.attributes.countered_fee_currency,
                    )
                  }}
                </template>
                <template v-else>
                  {{
                    formatMoney(
                      a.attributes.agreed_fee_minor_units,
                      a.attributes.agreed_fee_currency,
                    )
                  }}
                </template>
              </v-list-item-subtitle>
              <template #append>
                <v-btn
                  v-if="a.attributes.status === 'countered' && canInvite"
                  color="primary"
                  variant="flat"
                  size="small"
                  :data-test="`creators-reinvite-${a.id}`"
                  @click="openReinvite(a)"
                >
                  {{ t('app.campaigns.reinvite.action') }}
                </v-btn>
                <v-btn
                  v-if="
                    a.attributes.status === 'accepted' &&
                    canAttachContract &&
                    a.attributes.has_pending_contract !== true
                  "
                  color="primary"
                  variant="flat"
                  size="small"
                  :data-test="`creators-attach-contract-${a.id}`"
                  @click="openAttachContract(a)"
                >
                  {{ t('app.campaigns.contract.attach.action') }}
                </v-btn>
                <v-chip
                  v-if="
                    a.attributes.status === 'accepted' && a.attributes.has_pending_contract === true
                  "
                  size="small"
                  color="info"
                  variant="tonal"
                  :data-test="`creators-contract-pending-${a.id}`"
                >
                  {{ t('app.campaigns.contract.pending') }}
                </v-chip>
                <v-btn
                  v-if="canProceedWithoutContract(a)"
                  color="secondary"
                  variant="outlined"
                  size="small"
                  :data-test="`creators-proceed-without-contract-${a.id}`"
                  @click="proceedWithoutContract(a)"
                >
                  {{ t('app.campaigns.contract.proceedWithout.action') }}
                </v-btn>
                <v-btn
                  v-if="a.attributes.status === 'draft_submitted' && canReview"
                  color="primary"
                  variant="flat"
                  size="small"
                  :data-test="`creators-review-${a.id}`"
                  @click="openReview(a)"
                >
                  {{ t('app.campaigns.review.action') }}
                </v-btn>
                <v-btn
                  v-if="canResolveVerification(a)"
                  color="warning"
                  variant="flat"
                  size="small"
                  :data-test="`creators-resolve-${a.id}`"
                  @click="openResolve(a)"
                >
                  {{ t('app.campaigns.resolution.action') }}
                </v-btn>
                <v-btn
                  v-if="canViewPost(a)"
                  color="secondary"
                  variant="outlined"
                  size="small"
                  :data-test="`creators-view-post-${a.id}`"
                  @click="openViewPost(a)"
                >
                  {{ t('app.campaigns.viewPost.action') }}
                </v-btn>
              </template>
            </v-list-item>
          </v-list>

          <InviteCreatorsDialog
            v-if="canInvite && agencyStore.currentAgencyId"
            v-model="inviteDialog"
            :agency-id="agencyStore.currentAgencyId"
            :campaign-id="ulid"
            :campaign-currency="campaign?.attributes.budget_currency ?? null"
            @invited="onInvited"
          />

          <ReinviteDialog
            v-if="canInvite && agencyStore.currentAgencyId"
            v-model="reinviteDialog"
            :agency-id="agencyStore.currentAgencyId"
            :campaign-id="ulid"
            :assignment="reinviteTarget"
            :campaign-currency="campaign?.attributes.budget_currency ?? null"
            @success="onReinvited"
          />

          <AttachContractDialog
            v-if="canAttachContract && agencyStore.currentAgencyId"
            v-model="attachContractDialog"
            :agency-id="agencyStore.currentAgencyId"
            :campaign-id="ulid"
            :assignment="attachContractTarget"
            @success="onContractAttached"
            @error="onContractAttachError"
          />

          <v-snackbar
            :model-value="inviteSnackbar !== null"
            :timeout="4000"
            color="success"
            data-test="invite-creators-snackbar"
            @update:model-value="
              (v) => {
                if (!v) inviteSnackbar = null
              }
            "
          >
            {{ inviteSnackbar }}
          </v-snackbar>

          <v-snackbar
            :model-value="reinviteSnackbar !== null"
            :timeout="4000"
            color="success"
            data-test="reinvite-snackbar"
            @update:model-value="
              (v) => {
                if (!v) reinviteSnackbar = null
              }
            "
          >
            {{ reinviteSnackbar }}
          </v-snackbar>

          <v-snackbar
            :model-value="attachContractSnackbar !== null"
            :timeout="4000"
            color="success"
            data-test="attach-contract-snackbar"
            @update:model-value="
              (v) => {
                if (!v) attachContractSnackbar = null
              }
            "
          >
            {{ attachContractSnackbar }}
          </v-snackbar>

          <v-snackbar
            :model-value="attachContractError !== null"
            :timeout="5000"
            color="error"
            data-test="attach-contract-error-snackbar"
            @update:model-value="
              (v) => {
                if (!v) attachContractError = null
              }
            "
          >
            {{ attachContractError }}
          </v-snackbar>

          <v-snackbar
            :model-value="proceedSnackbar !== null"
            :timeout="4000"
            color="success"
            data-test="proceed-without-contract-snackbar"
            @update:model-value="
              (v) => {
                if (!v) proceedSnackbar = null
              }
            "
          >
            {{ proceedSnackbar }}
          </v-snackbar>

          <v-snackbar
            :model-value="proceedError !== null"
            :timeout="5000"
            color="error"
            data-test="proceed-without-contract-error"
            @update:model-value="
              (v) => {
                if (!v) proceedError = null
              }
            "
          >
            {{ proceedError }}
          </v-snackbar>
        </v-window-item>

        <!-- Board (Sprint 12) — the Kanban. Mounted with v-if so the 30s poll
             stops when the operator leaves the tab (§10.2 / Q3 — no background
             polling on an unviewed tab). -->
        <v-window-item value="board" data-test="panel-board">
          <BoardView
            v-if="tab === 'board' && agencyStore.currentAgencyId"
            :agency-id="agencyStore.currentAgencyId"
            :campaign-id="ulid"
            :can-configure="canEdit"
          />
        </v-window-item>

        <!-- Drafts — campaign-wide version list; fetch-on-open (Board precedent). -->
        <v-window-item value="drafts" data-test="panel-drafts">
          <DraftsTab
            v-if="tab === 'drafts' && agencyStore.currentAgencyId"
            ref="draftsTabRef"
            :agency-id="agencyStore.currentAgencyId"
            :campaign-id="ulid"
            :can-review="canReview"
            @open-review="openReview"
          />
        </v-window-item>

        <!-- Messages (Sprint 11) — the agency roll-up of the campaign's threads -->
        <v-window-item value="messages" data-test="panel-messages">
          <CampaignMessagesPanel
            v-if="agencyStore.currentAgencyId"
            :agency-id="agencyStore.currentAgencyId"
            :campaign-ulid="ulid"
          />
        </v-window-item>

        <!-- Coming-soon tabs -->
        <v-window-item
          v-for="key in comingSoonTabs"
          :key="key"
          :value="key"
          :data-test="`panel-${key}`"
        >
          <CEmptyState
            :data-test="`${key}-coming-soon`"
            title-tag="h2"
            :title="t(`app.campaigns.comingSoon.${key}.heading`)"
            :body="t(`app.campaigns.comingSoon.${key}.body`)"
          >
            <template #icon>
              <v-icon icon="mdi-clock-outline" size="56" color="medium-emphasis" />
            </template>
          </CEmptyState>
        </v-window-item>

        <!-- Settings (admin/manager) -->
        <v-window-item v-if="canEdit" value="settings" data-test="panel-settings">
          <v-card class="pa-6" max-width="720">
            <v-select
              v-model="editStatus"
              :label="t('app.campaigns.fields.status')"
              :items="statusOptions"
              item-title="title"
              item-value="value"
              data-test="campaign-settings-status"
            />
            <CampaignForm
              v-if="editForm"
              v-model="editForm"
              :brands="[]"
              hide-brand
              :submitting="saving"
              :submit-label="t('app.campaigns.actions.saveSettings')"
              :error="saveError"
              :field-errors="fieldErrors"
              @submit="onSaveSettings"
            />
            <v-snackbar
              :model-value="saveSuccess"
              :timeout="3000"
              color="success"
              data-test="settings-success-toast"
              @update:model-value="
                (v) => {
                  if (!v) saveSuccess = false
                }
              "
            >
              {{ t('app.campaigns.settings.saved') }}
            </v-snackbar>
          </v-card>
        </v-window-item>
      </v-window>

      <ReviewDraftDrawer
        v-if="canReview && agencyStore.currentAgencyId"
        v-model="reviewDialog"
        :agency-id="agencyStore.currentAgencyId"
        :campaign-id="ulid"
        :assignment="reviewTarget"
        @reviewed="onReviewed"
      />

      <ResolveVerificationDrawer
        v-if="canReview && agencyStore.currentAgencyId"
        v-model="resolveDialog"
        :agency-id="agencyStore.currentAgencyId"
        :campaign-id="ulid"
        :assignment="resolveTarget"
        @resolved="onResolved"
      />

      <ViewPostedContentDrawer
        v-if="canReview && agencyStore.currentAgencyId"
        v-model="viewPostDialog"
        :agency-id="agencyStore.currentAgencyId"
        :campaign-id="ulid"
        :assignment="viewPostTarget"
      />

      <v-snackbar
        :model-value="reviewSnackbar !== null"
        :timeout="4000"
        color="success"
        data-test="review-snackbar"
        @update:model-value="
          (v) => {
            if (!v) reviewSnackbar = null
          }
        "
      >
        {{ reviewSnackbar }}
      </v-snackbar>
    </template>
  </div>
</template>
