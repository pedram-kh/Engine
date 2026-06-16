<script setup lang="ts">
/**
 * ActivityFeed — the workspace-home recent-activity list (Sprint 4 Chunk 1,
 * 1c). Self-contained widget: owns its data lifecycle (the thin
 * `dashboard.api.ts` + `useAgencyStore`, no data store — A8 house pattern),
 * mirroring the page's summary fetch.
 *
 * Rows come pre-curated + PII-scrubbed from the backend (agency-stamped,
 * action-allowlisted, per-action metadata whitelist). This component maps
 * each `action` to a localized template and interpolates the actor label +
 * the whitelisted metadata (e.g. bulk-invite counts). Unknown actions fall
 * back to a generic template — the feed never renders a raw action string.
 *
 * Empty / loading / error are all handled here (CEmptyState for the
 * zero-rows state), so the page just drops `<ActivityFeed />` in.
 */

import { formatDateTime } from '@catalyst/api-client'
import { CEmptyState } from '@catalyst/ui'
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { useAgencyStore } from '@/core/stores/useAgencyStore'

import { dashboardApi, type DashboardActivityItem } from '../api/dashboard.api'

const { t, locale } = useI18n()
const agencyStore = useAgencyStore()

const items = ref<DashboardActivityItem[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

// action string → flat i18n key (the action's dots would otherwise be read
// as i18n path separators). Keys not present here render the fallback.
const ACTION_LABEL_KEY: Record<string, string> = {
  'creator.invited': 'creatorInvited',
  'bulk_invite.completed': 'bulkInviteCompleted',
  'agency_creator_relation.created': 'agencyCreatorRelationCreated',
  'brand.created': 'brandCreated',
  'brand.archived': 'brandArchived',
  'brand.restored': 'brandRestored',
  'invitation.created': 'invitationCreated',
  'invitation.accepted': 'invitationAccepted',
  'agency_settings.updated': 'agencySettingsUpdated',
}

async function loadActivity(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  loading.value = true
  error.value = null

  try {
    const res = await dashboardApi.activity(agencyId)
    items.value = res.data
  } catch {
    error.value = t('dashboard.activity.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void loadActivity()
})

watch(
  () => agencyStore.currentAgencyId,
  (id) => {
    if (id !== null) void loadActivity()
  },
)

function rowLabel(item: DashboardActivityItem): string {
  const key = ACTION_LABEL_KEY[item.action] ?? 'fallback'
  const actor = item.actor_label ?? t('dashboard.activity.system')
  return t(`dashboard.activity.actions.${key}`, { actor, ...item.metadata })
}

function rowTime(item: DashboardActivityItem): string {
  return formatDateTime(item.created_at, locale.value)
}
</script>

<template>
  <div data-test="activity-feed">
    <v-alert v-if="error" type="error" variant="tonal" data-test="activity-feed-error">
      {{ error }}
    </v-alert>

    <v-skeleton-loader
      v-else-if="loading"
      type="list-item-two-line@3"
      data-test="activity-feed-loading"
    />

    <CEmptyState
      v-else-if="items.length === 0"
      data-test="dashboard-activity-empty"
      title-tag="h3"
      :body="t('dashboard.activity.empty')"
    >
      <template #icon>
        <v-icon icon="mdi-timeline-text-outline" size="48" color="medium-emphasis" />
      </template>
    </CEmptyState>

    <v-list v-else lines="two" data-test="activity-feed-list" bg-color="transparent">
      <v-list-item
        v-for="item in items"
        :key="item.id"
        :title="rowLabel(item)"
        :subtitle="rowTime(item)"
        data-test="activity-feed-item"
      >
        <template #prepend>
          <v-icon icon="mdi-circle-small" />
        </template>
      </v-list-item>
    </v-list>
  </div>
</template>
