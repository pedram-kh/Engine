<script setup lang="ts">
/**
 * Campaign detail page (Sprint 8 Chunk 1) — the app's FIRST tabbed
 * (v-tabs + v-window) surface.
 *
 *   - Overview  — campaign summary (live).
 *   - Creators  — the assignment list (live, read-only; empty until Chunk 2
 *                 wires inviting).
 *   - Settings  — config edit (live; admin/manager only — `canEdit`).
 *   - Board / Drafts / Payments / Messages — empty-state "coming soon" tabs
 *     (their sprints are deferred; nothing is half-built).
 */

import {
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

const { t } = useI18n()
const route = useRoute()
const agencyStore = useAgencyStore()
const { currentRole } = storeToRefs(agencyStore)

const canEdit = computed(
  () => currentRole.value === 'agency_admin' || currentRole.value === 'agency_manager',
)

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

const comingSoonTabs = ['board', 'drafts', 'payments', 'messages'] as const

function toDateInput(iso: string | null): string | undefined {
  return iso ? iso.slice(0, 10) : undefined
}

function seedEditForm(c: CampaignResource): void {
  editForm.value = {
    brand_id: c.relationships.brand.data.id,
    name: c.attributes.name,
    objective: c.attributes.objective,
    budget_minor_units: c.attributes.budget_minor_units ?? 0,
    budget_currency: c.attributes.budget_currency ?? 'EUR',
    description: c.attributes.description ?? undefined,
    starts_at: toDateInput(c.attributes.starts_at),
    ends_at: toDateInput(c.attributes.ends_at),
    target_creator_count: c.attributes.target_creator_count ?? undefined,
    requires_per_campaign_contract: c.attributes.requires_per_campaign_contract,
    brief: c.attributes.brief ?? null,
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
  if (minor === null) return '—'
  return `${(minor / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })} ${currency ?? ''}`.trim()
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

        <!-- Creators (read-only assignment list) -->
        <v-window-item value="creators" data-test="panel-creators">
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
            <v-list-item
              v-for="a in assignments"
              :key="a.id"
              :title="a.attributes.creator?.display_name ?? '—'"
              :subtitle="t(`app.campaigns.assignmentStatus.${a.attributes.status}`)"
            />
          </v-list>
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
    </template>
  </div>
</template>
