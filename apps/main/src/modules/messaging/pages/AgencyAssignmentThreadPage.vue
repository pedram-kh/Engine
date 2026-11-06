<script setup lang="ts">
/**
 * AH-119 — a campaign (assignment) conversation inside the unified inbox: the agency thread
 * view fed by the assignment transport through `assignmentThreadTransport`. Keyed by the campaign
 * and assignment ULIDs in the route. The header (creator, brand and campaign tag, owner, "View
 * campaign") resolves from the unified inbox, narrowed to this campaign; the `?name=` hint covers
 * the first paint. The "⋯" menu carries the owner line and, for an admin or manager, Reassign.
 */

import type { MessageInboxRow } from '@catalyst/api-client'
import { storeToRefs } from 'pinia'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { type RouteLocationRaw, useRoute } from 'vue-router'
import { useDisplay } from 'vuetify'

import { creatorAvatarUrl, creatorIsErased, creatorLabel } from '@/core/creators/creatorLabel'
import { useAgencyStore } from '@/core/stores/useAgencyStore'
import AgencyRelationshipThreadView from '@/modules/agency/components/AgencyRelationshipThreadView.vue'
import { effectiveBrandColor } from '@/modules/agency/composables/brandColor'
import ThreadActionsMenu from '@/modules/agency/components/ThreadActionsMenu.vue'

import { assignmentThreadTransport } from '../api/agencyThreadTransport'
import { messageInboxApi } from '../api/messageInbox.api'
import { agencyChatTransport } from '../api/messaging.api'
import { filterQueryOf } from '../composables/inboxFilterQuery'

const { t } = useI18n()
const route = useRoute()
const agencyStore = useAgencyStore()
const { currentRole } = storeToRefs(agencyStore)
const display = useDisplay()

const campaignUlid = computed(() => String(route.params.campaignUlid ?? ''))
const assignmentUlid = computed(() => String(route.params.assignmentUlid ?? ''))
const nameHint = computed(() => (typeof route.query.name === 'string' ? route.query.name : ''))

// Back to the list keeps the inbox filters (they ride in the route query).
const backTo = computed<RouteLocationRaw | null>(() =>
  display.smAndDown.value ? { name: 'messages.inbox', query: filterQueryOf(route.query) } : null,
)

const row = ref<MessageInboxRow | null>(null)

const transport = computed(() => {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null || campaignUlid.value === '' || assignmentUlid.value === '') {
    return null
  }
  return assignmentThreadTransport(
    agencyChatTransport(agencyId, campaignUlid.value, assignmentUlid.value),
  )
})

const creator = computed(() => row.value?.attributes.creator)

const title = computed(() =>
  creatorLabel(creator.value, t, nameHint.value || t('app.messaging.relationship.inboxTitle')),
)
const avatarUrl = computed(() => creatorAvatarUrl(creator.value))

const profileTo = computed<RouteLocationRaw | null>(() => {
  const id = creator.value?.id ?? null
  return id === null || creatorIsErased(creator.value)
    ? null
    : { name: 'roster.detail', params: { ulid: id } }
})

const campaignTag = computed(() => {
  const a = row.value?.attributes
  if (a === undefined || a.campaign === null) {
    return null
  }
  return a.brand === null
    ? { text: a.campaign.name }
    : {
        text: t('app.messaging.inbox.tagCampaign', {
          brand: a.brand.name,
          campaign: a.campaign.name,
        }),
        color: effectiveBrandColor(a.brand.color, a.brand.id),
      }
})

const viewCampaignTo = computed<RouteLocationRaw | null>(() =>
  campaignUlid.value === ''
    ? null
    : { name: 'campaigns.detail', params: { ulid: campaignUlid.value } },
)

const canReassign = computed(
  () => currentRole.value === 'agency_admin' || currentRole.value === 'agency_manager',
)

const owner = ref<{ id: string; name: string } | null>(null)

// Clearing FIRST: until the new row lands the header shows the `?name=` hint, never the
// previous conversation's creator and tag.
watch(
  [campaignUlid, assignmentUlid, () => agencyStore.currentAgencyId],
  async ([campaign, assignment, agencyId]) => {
    row.value = null
    owner.value = null
    if (agencyId === null || campaign === '' || assignment === '') {
      return
    }
    try {
      const res = await messageInboxApi.list(agencyId, { campaign: [campaign], perPage: 100 })
      if (assignmentUlid.value !== assignment || agencyStore.currentAgencyId !== agencyId) {
        return
      }
      const found =
        res.data.find(
          (r) => r.type === 'assignment_thread' && r.attributes.assignment_id === assignment,
        ) ?? null
      row.value = found
      owner.value = found?.attributes.owner ?? null
    } catch {
      // The hint covers the header; not load-bearing.
    }
  },
  { immediate: true },
)
</script>

<template>
  <section data-test="agency-assignment-thread-page">
    <AgencyRelationshipThreadView
      :transport="transport"
      :title="title"
      :avatar-text="title"
      :avatar-url="avatarUrl"
      :back-to="backTo"
      :profile-to="profileTo"
      :campaign-tag="campaignTag"
      :view-campaign-to="viewCampaignTo"
      :closed-text="t('app.messaging.closed')"
      no-links
    >
      <template #actions>
        <ThreadActionsMenu
          v-if="agencyStore.currentAgencyId !== null && creator?.id"
          :agency-id="agencyStore.currentAgencyId"
          :creator-ulid="creator.id"
          :owner="owner"
          :can-reassign="canReassign"
          @changed="owner = $event"
        />
      </template>
    </AgencyRelationshipThreadView>
  </section>
</template>

<style scoped>
[data-test='agency-assignment-thread-page'] {
  height: 100%;
  min-height: 0;
}
</style>
