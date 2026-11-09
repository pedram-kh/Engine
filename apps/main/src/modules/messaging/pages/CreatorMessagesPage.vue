<script setup lang="ts">
/**
 * AH-010b / AH-013 — the CREATOR conversations surface (top-level "Messages").
 * Lists the creator's relationship threads (one per connected agency), keyed by
 * agency. A 45s poll keeps unread badges fresh (longer than an open thread's
 * ~15s poll, the CampaignMessagesPanel precedent).
 *
 * AH-013 — WhatsApp-Web two-pane on DESKTOP: this page is the persistent shell;
 * the list lives in the left pane and the open thread renders into the right
 * pane via the nested `<router-view>` (the `creator.messages.thread` child). On
 * MOBILE it stays single-pane (one pane at a time, based on the selection).
 */

import type {
  CreatorInboxRow,
  CreatorInboxSearchResult,
  CreatorRelationshipThreadRow,
  MessageableAgencyRow,
} from '@catalyst/api-client'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, type RouteLocationRaw } from 'vue-router'
import { useDisplay } from 'vuetify'

import CreatorMessageSearchResults, {
  type MessageSearchItem,
} from '../components/CreatorMessageSearchResults.vue'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

import { creatorInboxApi } from '../api/creatorInbox.api'
import { relationshipMessagingApi } from '../api/relationshipMessaging.api'
import ContactPicker, { type ContactPickerItem } from '../components/ContactPicker.vue'
import RelationshipInbox, { type RelationshipInboxItem } from '../components/RelationshipInbox.vue'
import { useMessageSearch } from '../composables/useMessageSearch'
import { useMessagesEditSearchFlag } from '../composables/useMessagesEditSearchFlag'
import { previewOrDeleted } from '../utils/messageRevision'

const { t } = useI18n()
const editSearchOn = useMessagesEditSearchFlag()
const route = useRoute()
const display = useDisplay()

// AH-013 — two-pane only at ≥ md; below that, one pane at a time (mobile).
const isDesktop = computed(() => display.mdAndUp.value)
const activeAgencyUlid = computed(() =>
  typeof route.params.agencyUlid === 'string' ? route.params.agencyUlid : '',
)
const hasSelection = computed(() => activeAgencyUlid.value !== '')
// The open conversation's row: v1 rows are keyed by agency; the unified rows by thread, and only a
// direct thread is ever open beside the list.
const activeRowId = computed(() => {
  if (!unifiedOn.value) {
    return activeAgencyUlid.value
  }
  const open = v2Rows.value.find(
    (r) => r.type === 'relationship_thread' && r.attributes.agency.id === activeAgencyUlid.value,
  )
  return open?.id ?? ''
})
const showList = computed(() => isDesktop.value || !hasSelection.value)
const showDetail = computed(() => isDesktop.value || hasSelection.value)

const INBOX_POLL_INTERVAL_MS = 45000

const authStore = useAuthStore()

// AH-115 L4 — the unified inbox (direct and campaign conversations in one list, tagged, with search)
// replaces the agency-only list while `messages_v2_creator_inbox` is on (a `/me` switch; the API 404s
// the endpoints otherwise). Off, nothing below changes: the list is today's.
const unifiedOn = computed(
  () => authStore.user?.attributes.features?.messages_v2_creator_inbox === true,
)

const rows = ref<CreatorRelationshipThreadRow[]>([])
const v2Rows = ref<CreatorInboxRow[]>([])
const v2Cursor = ref<string | null>(null)
const v2LoadingMore = ref(false)
const searchTerm = ref('')
const loading = ref(false)
const loadError = ref(false)

let cancelled = false
let timer: ReturnType<typeof setTimeout> | null = null

const items = computed<RelationshipInboxItem[]>(() =>
  unifiedOn.value ? v2Items.value : v1Items.value,
)

function v2Preview(row: CreatorInboxRow): string | null {
  const a = row.attributes
  if (a.preview_kind === 'deleted') {
    return t('app.messaging.actions.deleted')
  }
  if (a.preview_kind === 'attachment') {
    return t('app.messaging.attachment')
  }
  return a.preview
}

function v2Tag(row: CreatorInboxRow): string {
  const a = row.attributes
  if (row.type === 'relationship_thread') {
    return t('app.messaging.inbox.tagDirect')
  }
  if (a.brand !== null && a.campaign !== null) {
    return t('app.messaging.inbox.tagCampaign', { brand: a.brand.name, campaign: a.campaign.name })
  }
  return a.campaign?.name ?? a.brand?.name ?? ''
}

function v2To(row: CreatorInboxRow, name: string): RouteLocationRaw | null {
  const a = row.attributes
  if (row.type === 'relationship_thread') {
    return a.agency.id === null
      ? null
      : { name: 'creator.messages.thread', params: { agencyUlid: a.agency.id }, query: { name } }
  }
  // Q6: an assignment row opens the assignment's own chat.
  return a.assignment_id === null
    ? null
    : {
        name: 'creator.assignment.detail',
        params: { ulid: a.assignment_id },
        query: { focus: 'messages' },
      }
}

const v2Items = computed<RelationshipInboxItem[]>(() =>
  v2Rows.value.flatMap((row): RelationshipInboxItem[] => {
    const a = row.attributes
    const name = a.agency.name ?? t('app.messaging.participant')
    const to = v2To(row, name)
    if (to === null) {
      return []
    }
    return [
      {
        id: row.id,
        title: name,
        preview: v2Preview(row),
        lastMessageAt: a.last_message_at,
        unreadCount: a.unread_count,
        avatarText: name,
        avatarUrl: a.agency.logo_url,
        to,
        tag: v2Tag(row),
        tagDirect: row.type === 'relationship_thread',
      },
    ]
  }),
)

// The search field also narrows the loaded rows (name, preview, tag), as the agency field does.
const visibleItems = computed<RelationshipInboxItem[]>(() => {
  const needle = searchTerm.value.trim().toLowerCase()
  if (!unifiedOn.value || needle === '') {
    return items.value
  }
  return items.value.filter((item) =>
    [item.title, item.preview, item.tag].some((part) =>
      (part ?? '').toLowerCase().includes(needle),
    ),
  )
})

const v1Items = computed<RelationshipInboxItem[]>(() =>
  rows.value
    .filter((row) => row.attributes.agency.id !== null)
    .map((row) => {
      const agency = row.attributes.agency
      const name = agency.name ?? t('app.messaging.participant')
      return {
        id: agency.id as string,
        title: name,
        preview: previewOrDeleted(
          row.attributes.last_message_preview,
          row.attributes.last_message_is_deleted,
          editSearchOn.value,
          t('app.messaging.actions.deleted'),
        ),
        lastMessageAt: row.attributes.last_message_at,
        unreadCount: row.attributes.unread_count,
        avatarText: name,
        avatarUrl: agency.logo_url,
        to: {
          name: 'creator.messages.thread',
          params: { agencyUlid: agency.id as string },
          query: { name },
        },
      }
    }),
)

async function load(initial = false): Promise<void> {
  const unified = unifiedOn.value
  loading.value = initial && (unified ? v2Rows.value.length === 0 : rows.value.length === 0)
  try {
    if (unified) {
      const res = await creatorInboxApi.inbox()
      if (cancelled) {
        return
      }
      if (v2Cursor.value === null || v2Rows.value.length === 0) {
        v2Rows.value = [...res.data]
        v2Cursor.value = res.meta.next_cursor
      } else {
        // Later pages are loaded: refresh the first page in place and keep the rest.
        const fresh = new Set(res.data.map((r) => r.id))
        v2Rows.value = [...res.data, ...v2Rows.value.filter((r) => !fresh.has(r.id))]
      }
    } else {
      const res = await relationshipMessagingApi.creatorInbox()
      if (cancelled) {
        return
      }
      rows.value = [...res.data]
    }
    loadError.value = false
  } catch {
    if ((unified ? v2Rows.value : rows.value).length === 0) {
      loadError.value = true
    }
  } finally {
    loading.value = false
  }
}

async function loadMore(): Promise<void> {
  const cursor = v2Cursor.value
  if (cursor === null || v2LoadingMore.value) {
    return
  }
  v2LoadingMore.value = true
  try {
    const res = await creatorInboxApi.inbox(cursor)
    const known = new Set(v2Rows.value.map((r) => r.id))
    v2Rows.value = [...v2Rows.value, ...res.data.filter((r) => !known.has(r.id))]
    v2Cursor.value = res.meta.next_cursor
  } catch {
    // Transient: the cursor stays so the user can ask again.
  } finally {
    v2LoadingMore.value = false
  }
}

function schedule(): void {
  timer = setTimeout(() => {
    void tick()
  }, INBOX_POLL_INTERVAL_MS)
}

async function tick(): Promise<void> {
  if (cancelled) {
    return
  }
  await load()
  if (cancelled) {
    return
  }
  schedule()
}

// ── Message search (AH-115 L4) ───────────────────────────────────────────────
const searchKey = ref<string | null>('creator')
const messageSearch = useMessageSearch<CreatorInboxSearchResult>(
  searchKey,
  searchTerm,
  unifiedOn,
  (_key, q, cursor) => creatorInboxApi.search(q, cursor),
)

const searchItems = computed<MessageSearchItem[]>(() =>
  messageSearch.results.value.flatMap((hit): MessageSearchItem[] => {
    const a = hit.attributes
    const name = a.agency.name ?? t('app.messaging.participant')
    const to = v2To({ id: hit.id, type: hit.type, attributes: a }, name)
    if (to === null) {
      return []
    }
    return [
      {
        key: a.message_ulid,
        threadTitle: name,
        senderLabel: a.is_own ? t('app.messaging.search.fromYou') : name,
        snippet: a.snippet,
        sentAt: a.sent_at,
        to,
      },
    ]
  }),
)

// ── New-conversation contact picker (AH-012) ─────────────────────────────────
const pickerOpen = ref(false)
const pickerLoading = ref(false)
const pickerError = ref(false)
const agencies = ref<MessageableAgencyRow[]>([])

const pickerItems = computed<ContactPickerItem[]>(() =>
  agencies.value.map((row) => {
    const name = row.attributes.name ?? t('app.messaging.participant')
    return {
      id: row.id,
      title: name,
      avatarText: name,
      avatarUrl: row.attributes.logo_url,
      to: {
        name: 'creator.messages.thread',
        params: { agencyUlid: row.id },
        query: { name },
      },
    }
  }),
)

async function openPicker(): Promise<void> {
  pickerOpen.value = true
  pickerLoading.value = agencies.value.length === 0
  pickerError.value = false
  try {
    const res = await relationshipMessagingApi.messageableAgencies()
    agencies.value = [...res.data]
  } catch {
    pickerError.value = true
  } finally {
    pickerLoading.value = false
  }
}

onMounted(() => {
  cancelled = false
  void load(true)
  schedule()
})

onBeforeUnmount(() => {
  cancelled = true
  if (timer !== null) {
    clearTimeout(timer)
    timer = null
  }
})
</script>

<template>
  <section
    data-test="creator-messages-page"
    class="msg-shell"
    :class="{ 'msg-shell--split': isDesktop }"
  >
    <div v-if="showList" class="msg-shell__list" data-test="messages-list-pane">
      <header class="msg-shell__list-header">
        <h1 class="text-h6 ma-0">{{ t('app.messaging.relationship.inboxTitle') }}</h1>
        <v-btn
          color="primary"
          variant="tonal"
          size="small"
          prepend-icon="mdi-message-plus-outline"
          data-test="creator-new-conversation"
          @click="openPicker"
        >
          {{ t('app.messaging.relationship.newConversation') }}
        </v-btn>
      </header>

      <v-text-field
        v-if="unifiedOn"
        v-model="searchTerm"
        :placeholder="t('app.messaging.relationship.creatorInboxSearch')"
        :aria-label="t('app.messaging.relationship.creatorInboxSearch')"
        prepend-inner-icon="mdi-magnify"
        density="compact"
        variant="outlined"
        hide-details
        class="msg-shell__search"
        data-test="creator-inbox-search"
      />

      <div class="msg-shell__list-body">
        <RelationshipInbox
          :items="visibleItems"
          :loading="loading"
          :load-error="loadError"
          :active-id="activeRowId"
          day-aware-stamps
          @start="openPicker"
        />
        <v-btn
          v-if="unifiedOn && v2Cursor !== null && searchTerm.trim() === ''"
          variant="text"
          size="small"
          class="msg-shell__more"
          :loading="v2LoadingMore"
          data-test="creator-inbox-load-more"
          @click="loadMore"
        >
          {{ t('app.messaging.inbox.loadMore') }}
        </v-btn>
        <CreatorMessageSearchResults
          v-if="unifiedOn"
          :state="messageSearch.state.value"
          :items="searchItems"
          :has-more="messageSearch.hasMore.value"
          :loading-more="messageSearch.loadingMore.value"
          :query="searchTerm"
          @load-more="messageSearch.loadMore"
        />
      </div>
    </div>

    <div v-if="showDetail" class="msg-shell__detail" data-test="messages-detail-pane">
      <router-view v-if="hasSelection" />
      <div v-else class="msg-shell__placeholder" data-test="messages-placeholder">
        <v-icon icon="mdi-message-text-outline" size="48" class="mb-2" />
        <p class="ma-0">{{ t('app.messaging.relationship.selectConversation') }}</p>
      </div>
    </div>

    <ContactPicker
      v-model="pickerOpen"
      :title="t('app.messaging.relationship.picker.titleAgencies')"
      :items="pickerItems"
      :loading="pickerLoading"
      :load-error="pickerError"
      :empty-label="t('app.messaging.relationship.picker.emptyAgencies')"
    />
  </section>
</template>

<style scoped>
/* AH-013 — desktop two-pane (WhatsApp Web); mobile falls back to single pane. */
.msg-shell--split {
  display: flex;
  gap: 24px;
  height: calc(100vh - 150px);
  min-height: 420px;
}

.msg-shell__list {
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.msg-shell--split .msg-shell__list {
  flex: 0 0 340px;
  max-width: 340px;
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  padding-right: 8px;
}

.msg-shell__list-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}

.msg-shell__search {
  margin-bottom: 8px;
}

.msg-shell__more {
  align-self: center;
  margin: 4px auto;
}

.msg-shell__list-body {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
}

.msg-shell__detail {
  flex: 1 1 auto;
  min-width: 0;
}

.msg-shell__placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  min-height: 320px;
  opacity: 0.55;
  text-align: center;
}
</style>
