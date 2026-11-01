<script setup lang="ts">
/**
 * Second probe page (harness only, `?probe=extra`): the primitive variants the agency
 * pages use and the default probe page does not carry. Nothing here is shared with the
 * creator baseline, so adding to it never changes a baseline hash.
 */

import type { RelationshipMessageResource } from '@catalyst/api-client'
import { ref } from 'vue'

import type { RelationshipChatTransport } from '@/modules/messaging/api/relationshipMessaging.api'

import AgencyKpiCard from '@/modules/agency/components/AgencyKpiCard.vue'
import AgencyRelationshipInbox, {
  type AgencyInboxItem,
} from '@/modules/agency/components/AgencyRelationshipInbox.vue'
import AgencyRelationshipThreadView from '@/modules/agency/components/AgencyRelationshipThreadView.vue'
import AgencyStatusPill from '@/modules/agency/components/AgencyStatusPill.vue'
import type { AgencyStatusTone } from '@/modules/agency/status/agencyStatusTones'

const on = ref(true)
const off = ref(false)
const text = ref('')
const combo = ref('')
const chip = ref(0)

const tableHeaders = [
  { title: 'Campaign', key: 'name' },
  { title: 'Budget', key: 'budget' },
]
const tableItems = [
  { name: 'Spring launch', budget: 4200 },
  { name: 'Summer reset', budget: 7000 },
  { name: 'Autumn capsule', budget: 8700 },
  { name: 'Holiday gifting', budget: 10200 },
]

// S2.2: fixed old timestamps so the stamps read as dates and the shots do not change daily.
const inboxItems: AgencyInboxItem[] = [
  {
    id: 'a',
    title: 'Jordan Reyes',
    preview: 'Perfect, see you at the shoot on Friday.',
    lastMessageAt: '2020-03-14T16:20:00+00:00',
    unreadCount: 0,
    avatarText: 'Jordan Reyes',
    to: '/campaigns',
  },
  {
    id: 'u',
    title: 'Nadia Okafor',
    preview: 'Sounds good, sending the draft tonight.',
    lastMessageAt: '2020-03-14T09:30:00+00:00',
    unreadCount: 3,
    avatarText: 'Nadia Okafor',
    to: '/campaigns',
    tag: 'Glow Co.',
  },
  {
    id: 'r',
    title: 'Ari Cole',
    preview: 'Contract attached.',
    lastMessageAt: '2020-03-13T18:05:00+00:00',
    unreadCount: 0,
    avatarText: 'Ari Cole',
    to: '/campaigns',
  },
  {
    id: 'l',
    title: 'A creator with a very long display name that cannot fit the row',
    preview: 'And a preview that is also far too long to fit on a single line of the list row.',
    lastMessageAt: '2019-11-02T12:00:00+00:00',
    unreadCount: 12,
    avatarText: 'A creator',
    to: '/campaigns',
  },
]
const inboxLabels = {
  search: 'Search conversations',
  unreadPill: (count: number) => `${count} unread`,
  all: 'All',
  unread: 'Unread',
  noMatch: 'No conversations match',
}

// S2.3: a stand-in conversation behind a fake transport (fixed old dates, so the day separators
// and the stamps do not change). Nothing here touches the API.
function threadMessage(
  id: string,
  at: string,
  attrs: Partial<RelationshipMessageResource['attributes']>,
): RelationshipMessageResource {
  return {
    id,
    type: 'relationship_message',
    attributes: {
      kind: 'text',
      sender_role: 'creator',
      body: null,
      attachments: [],
      is_own: false,
      sender: { name: 'Nadia Okafor' },
      read_by_counterparty: null,
      created_at: at,
      ...attrs,
    },
  }
}

const threadMessages = [
  threadMessage('t1', '2020-03-13T09:30:00+00:00', {
    body: 'Hi! I went through the brief and have a couple of questions about usage rights.',
  }),
  threadMessage('t2', '2020-03-13T09:42:00+00:00', {
    sender_role: 'agency_user',
    is_own: true,
    sender: { name: 'Jordan' },
    read_by_counterparty: true,
    body: 'Of course, happy to walk you through them. The contract has the details.',
  }),
  threadMessage('t3', '2020-03-13T09:45:00+00:00', {
    sender_role: 'agency_user',
    is_own: true,
    sender: { name: 'Jordan' },
    read_by_counterparty: true,
    kind: 'attachment_only',
    attachments: [
      {
        kind: 'file',
        s3_path: 'x',
        mime_type: 'application/pdf',
        name: 'Campaign brief.pdf',
        size_bytes: 1_200_000,
        view_url: 'https://files.example/brief.pdf',
      },
    ],
  }),
  threadMessage('t4', '2020-03-14T16:10:00+00:00', {
    body: 'Thanks, that clears it up. Sending the draft tonight.',
  }),
  threadMessage('t5', '2020-03-14T16:12:00+00:00', {
    sender_role: 'agency_user',
    is_own: true,
    sender: { name: 'Jordan' },
    read_by_counterparty: false,
    body: 'Perfect, see you on Friday.',
  }),
]

const threadTransport: RelationshipChatTransport = {
  list: () =>
    Promise.resolve({
      data: threadMessages,
      meta: {
        thread: {
          id: 'probe-thread',
          last_message_at: null,
          unread_count: 0,
          can_send: true,
          closed_reason: null,
        },
        has_more: false,
      },
    }),
  send: () => Promise.reject(new Error('probe transport: send is not wired')),
  markRead: () => Promise.resolve({ meta: { marked: 0, unread_count: 0 } }),
  attachmentInit: () => Promise.reject(new Error('probe transport: not wired')),
  attachmentComplete: () => Promise.reject(new Error('probe transport: not wired')),
}

const pillTones: Array<[AgencyStatusTone, string]> = [
  ['success', 'Approved'],
  ['info', 'Contacted'],
  ['warning', 'Awaiting'],
  ['danger', 'Declined'],
  ['neutral', 'Draft'],
  ['ai', 'Generated'],
]
</script>

<template>
  <section data-test="probe-extra" class="probe">
    <div class="d-flex ga-4 align-center my-3" data-test="extra-switches">
      <!-- The shape CampaignListPage uses: inset, compact, primary. -->
      <v-switch
        v-model="on"
        color="primary"
        density="compact"
        hide-details
        inset
        aria-label="On"
        data-test="extra-switch-on"
      />
      <v-switch
        v-model="off"
        color="primary"
        density="compact"
        hide-details
        inset
        aria-label="Off"
        data-test="extra-switch-off"
      />
    </div>

    <div class="d-flex ga-2 flex-wrap my-3" data-test="extra-buttons">
      <v-btn variant="outlined" color="primary">Outlined primary</v-btn>
      <v-btn variant="text" color="primary">Text primary</v-btn>
      <v-btn variant="tonal" color="primary">Tonal primary</v-btn>
      <v-btn variant="text" color="error">Text error</v-btn>
      <v-btn variant="outlined" size="small" class="text-none">Small secondary</v-btn>
    </div>

    <v-chip-group v-model="chip" data-test="extra-chips">
      <v-chip variant="outlined" filter>All</v-chip>
      <v-chip variant="outlined" filter>Draft</v-chip>
    </v-chip-group>

    <v-divider class="my-3" data-test="extra-divider" />

    <v-card class="pa-4 my-3" data-test="extra-card">
      <div class="d-flex flex-column ga-3" data-test="extra-fields">
        <v-text-field
          v-model="text"
          label="With hint"
          hint="A hint line"
          persistent-hint
          data-test="extra-hint"
        />
        <v-text-field
          label="With error"
          :error-messages="['This field is required']"
          data-test="extra-error"
        />
        <v-autocomplete label="Autocomplete" :items="['a', 'b']" data-test="extra-autocomplete" />
        <v-combobox
          v-model="combo"
          label="Combobox"
          :items="['a', 'b']"
          data-test="extra-combobox"
        />
        <v-text-field label="Disabled" disabled data-test="extra-disabled" />
      </div>
    </v-card>

    <!-- A data table WITH its default footer (the default probe table hides it). -->
    <v-data-table
      :headers="tableHeaders"
      :items="tableItems"
      class="my-3"
      data-test="extra-table"
    />

    <!-- S2.1: the status pill, every tone, plus table-mode values. -->
    <div class="d-flex ga-3 align-center flex-wrap my-3" data-test="extra-pills">
      <AgencyStatusPill
        v-for="[tone, label] in pillTones"
        :key="tone"
        :tone="tone"
        :label="label"
        :data-test="`extra-pill-${tone}`"
      />
      <AgencyStatusPill kind="campaign" status="active" data-test="extra-pill-campaign-active" />
      <AgencyStatusPill kind="roster" status="pending_request" data-test="extra-pill-roster" />
    </div>

    <!-- S2.4: the KPI card fork, each variant the page can ask for. -->
    <div class="extra-kpis" data-test="extra-kpis">
      <AgencyKpiCard
        label="Creators in roster"
        :value="128"
        icon="mdi-account-group-outline"
        tile-tone="info"
        to="/campaigns"
        data-test="extra-kpi-link"
      />
      <AgencyKpiCard
        label="Pending applications"
        :value="12"
        icon="mdi-inbox-arrow-down-outline"
        tile-tone="warning"
        data-test="extra-kpi-icon"
      />
      <AgencyKpiCard
        label="Active campaigns"
        :value="0"
        icon="mdi-bullhorn-outline"
        to="/campaigns"
        data-test="extra-kpi-ai"
      />
      <AgencyKpiCard
        label="Payments due"
        :value="3"
        icon="mdi-credit-card-outline"
        tile-tone="success"
        data-test="extra-kpi-success"
      />
      <AgencyKpiCard label="Briefs in draft" :value="4" data-test="extra-kpi-plain" />
      <AgencyKpiCard label="Payments" :value="null" data-test="extra-kpi-placeholder" />
      <AgencyKpiCard label="Loading" loading data-test="extra-kpi-loading" />
    </div>

    <!-- S2.2: the inbox fork on stand-in rows, in a 380px pane (the page owns the pane). -->
    <div class="extra-pane" data-test="extra-inbox-pane">
      <AgencyRelationshipInbox
        :items="inboxItems"
        :loading="false"
        :load-error="false"
        active-id="a"
        :labels="inboxLabels"
      />
    </div>

    <!-- S2.3: the conversation pane fork behind a stand-in transport, in a 640px pane. -->
    <div class="extra-pane extra-pane--thread" data-test="extra-thread-pane">
      <AgencyRelationshipThreadView
        :transport="threadTransport"
        title="Nadia Okafor"
        avatar-text="Nadia Okafor"
      />
    </div>
  </section>
</template>

<style scoped>
.extra-pane {
  width: 380px;
  height: 560px;
  margin-block: 12px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 12px;
  background-color: rgb(var(--v-theme-surface));
  overflow: hidden;
}

.extra-kpis {
  display: grid;
  grid-template-columns: repeat(2, 300px);
  gap: 16px;
  margin-block: 12px;
}

.extra-pane--thread {
  width: 640px;
}
</style>
