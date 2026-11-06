<script setup lang="ts">
/**
 * AH-010b / AH-013 — the AGENCY conversations surface (top-level "Messages").
 * Lists the agency's relationship threads (one per connected creator), keyed by
 * creator. Org-level (Q4): any active member sees the same inbox. A 45s poll
 * keeps unread badges fresh.
 *
 * AH-013 — WhatsApp-Web two-pane on DESKTOP: this page is the persistent shell;
 * the conversation list lives in the left pane and the open thread renders into
 * the right pane via the nested `<router-view>` (the `messages.thread` child).
 * On MOBILE it stays single-pane — the list, then the thread full-screen — by
 * showing exactly one pane based on whether a conversation is selected.
 */

import type { AgencyRelationshipThreadRow, MessageableCreatorRow } from '@catalyst/api-client'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useDisplay } from 'vuetify'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

import { relationshipMessagingApi } from '../api/relationshipMessaging.api'
import ContactPicker, { type ContactPickerItem } from '../components/ContactPicker.vue'
import AgencyRelationshipInbox, {
  type AgencyInboxItem,
  type AgencyInboxLabels,
} from '@/modules/agency/components/AgencyRelationshipInbox.vue'
import AgencyUnifiedInbox, {
  type UnifiedInboxFilters,
  type UnifiedInboxItem,
} from '@/modules/agency/components/AgencyUnifiedInbox.vue'
import { creatorAvatarUrl, creatorLabel } from '@/core/creators/creatorLabel'
import { filtersFromQuery, filtersToQuery } from '../composables/inboxFilterQuery'
import { useMessageInbox, type InboxFilters } from '../composables/useMessageInbox'

const { t } = useI18n()
const agencyStore = useAgencyStore()
const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const display = useDisplay()

// AH-013 — two-pane only at ≥ md; below that, one pane at a time (mobile).
const isDesktop = computed(() => display.mdAndUp.value)
const activeCreatorUlid = computed(() =>
  typeof route.params.creatorUlid === 'string' ? route.params.creatorUlid : '',
)
const activeAssignmentUlid = computed(() =>
  typeof route.params.assignmentUlid === 'string' ? route.params.assignmentUlid : '',
)
const hasSelection = computed(
  () => activeCreatorUlid.value !== '' || activeAssignmentUlid.value !== '',
)

// AH-119 — the unified inbox replaces the Direct-only list while `messages_v2_inbox` is on
// (a `/me` switch; the API 404s the inbox otherwise). Off, nothing below changes.
const unifiedOn = computed(() => authStore.user?.attributes.features?.messages_v2_inbox === true)
const filters = computed<InboxFilters>(() => filtersFromQuery(route.query))
const inboxAgencyId = computed(() => (unifiedOn.value ? agencyStore.currentAgencyId : null))
const inbox = useMessageInbox(inboxAgencyId, filters)

function setFilters(next: UnifiedInboxFilters): void {
  void router.replace({
    query: {
      ...route.query,
      brand: undefined,
      campaign: undefined,
      unread: undefined,
      owner: undefined,
      ...filtersToQuery(next),
    },
  })
}

// A brand or campaign of another agency means nothing here: drop the selection on a switch.
watch(
  () => agencyStore.currentAgencyId,
  (_next, prev) => {
    if (prev !== null && prev !== undefined) {
      setFilters({ ...filters.value, brand: [], campaign: [] })
    }
  },
)

const unifiedItems = computed<UnifiedInboxItem[]>(() => {
  const filterQuery = filtersToQuery(filters.value)
  return inbox.rows.value.flatMap((row): UnifiedInboxItem[] => {
    const a = row.attributes
    const name = creatorLabel(a.creator, t, t('app.messaging.participant'))
    const base = {
      key: `${row.type}:${row.id}`,
      title: name,
      preview: a.preview,
      previewIsAttachment: a.preview_kind === 'attachment',
      lastMessageAt: a.last_message_at,
      unreadCount: a.unread_count,
      avatarText: name,
      avatarUrl: creatorAvatarUrl(a.creator),
      brandName: a.brand?.name ?? null,
      brandId: a.brand?.id ?? null,
      brandColor: a.brand?.color ?? null,
      campaignName: a.campaign?.name ?? null,
    }
    if (row.type === 'relationship_thread') {
      if (a.creator.id === null) {
        return []
      }
      return [
        {
          ...base,
          direct: true,
          to: {
            name: 'messages.thread',
            params: { creatorUlid: a.creator.id },
            query: { ...filterQuery, name },
          },
        },
      ]
    }
    if (a.campaign === null || a.assignment_id === null) {
      return []
    }
    return [
      {
        ...base,
        direct: false,
        to: {
          name: 'messages.assignment-thread',
          params: { campaignUlid: a.campaign.id, assignmentUlid: a.assignment_id },
          query: { ...filterQuery, name },
        },
      },
    ]
  })
})

const activeKey = computed(() => {
  const hit = inbox.rows.value.find((row) =>
    row.type === 'relationship_thread'
      ? activeCreatorUlid.value !== '' && row.attributes.creator.id === activeCreatorUlid.value
      : activeAssignmentUlid.value !== '' &&
        row.attributes.assignment_id === activeAssignmentUlid.value,
  )
  return hit === undefined ? null : `${hit.type}:${hit.id}`
})
const showList = computed(() => isDesktop.value || !hasSelection.value)
const showDetail = computed(() => isDesktop.value || hasSelection.value)

// S3.4 — the words the inbox header switches its search, unread pill and All / Unread toggle on with.
const inboxLabels = computed<AgencyInboxLabels>(() => ({
  search: t('app.messaging.relationship.inboxSearch'),
  unreadPill: (count: number) => t('app.messaging.relationship.inboxUnreadPill', { count }, count),
  all: t('app.messaging.relationship.inboxFilterAll'),
  unread: t('app.messaging.relationship.inboxFilterUnread'),
  noMatch: t('app.messaging.relationship.inboxNoMatch'),
}))

const INBOX_POLL_INTERVAL_MS = 45000
const PICKER_PER_PAGE = 25
const SEARCH_DEBOUNCE_MS = 300

const rows = ref<AgencyRelationshipThreadRow[]>([])
const loading = ref(false)
const loadError = ref(false)

let cancelled = false
let timer: ReturnType<typeof setTimeout> | null = null

const items = computed<AgencyInboxItem[]>(() =>
  rows.value
    .filter((row) => row.attributes.creator.id !== null)
    .map((row) => {
      const creator = row.attributes.creator
      const name = creatorLabel(creator, t, t('app.messaging.participant'))
      return {
        id: creator.id as string,
        title: name,
        preview: row.attributes.last_message_preview,
        lastMessageAt: row.attributes.last_message_at,
        unreadCount: row.attributes.unread_count,
        avatarText: name,
        avatarUrl: creatorAvatarUrl(creator),
        to: {
          name: 'messages.thread',
          params: { creatorUlid: creator.id as string },
          query: { name },
        },
      }
    }),
)

async function load(initial = false): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) {
    return
  }
  loading.value = initial && rows.value.length === 0
  try {
    const res = await relationshipMessagingApi.agencyInbox(agencyId)
    if (cancelled) {
      return
    }
    rows.value = [...res.data]
    loadError.value = false
  } catch {
    if (rows.value.length === 0) {
      loadError.value = true
    }
  } finally {
    loading.value = false
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

// ── New-conversation contact picker (AH-012) — searched + paginated (D6) ─────
const pickerOpen = ref(false)
const pickerLoading = ref(false)
const pickerLoadingMore = ref(false)
const pickerError = ref(false)
const pickerSearch = ref('')
const creators = ref<MessageableCreatorRow[]>([])
const pickerPage = ref(1)
const pickerLastPage = ref(1)

let searchTimer: ReturnType<typeof setTimeout> | null = null

const pickerItems = computed<ContactPickerItem[]>(() =>
  creators.value.map((row) => {
    const name = creatorLabel(row.attributes, t, t('app.messaging.participant'))
    return {
      id: row.id,
      title: name,
      avatarText: name,
      avatarUrl: creatorAvatarUrl(row.attributes),
      to: {
        name: 'messages.thread',
        params: { creatorUlid: row.id },
        query: { ...filtersToQuery(filters.value), name },
      },
    }
  }),
)

const pickerHasMore = computed(() => pickerPage.value < pickerLastPage.value)

async function fetchContacts(page: number, append: boolean): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) {
    return
  }
  if (append) {
    pickerLoadingMore.value = true
  } else {
    pickerLoading.value = creators.value.length === 0
  }
  pickerError.value = false
  try {
    const res = await relationshipMessagingApi.messageableCreators(agencyId, {
      search: pickerSearch.value,
      page,
      perPage: PICKER_PER_PAGE,
    })
    creators.value = append ? [...creators.value, ...res.data] : [...res.data]
    pickerPage.value = res.meta.page
    pickerLastPage.value = res.meta.last_page
  } catch {
    if (!append) {
      pickerError.value = true
    }
  } finally {
    pickerLoading.value = false
    pickerLoadingMore.value = false
  }
}

function openPicker(): void {
  pickerOpen.value = true
  void fetchContacts(1, false)
}

function loadMore(): void {
  if (pickerHasMore.value && !pickerLoadingMore.value) {
    void fetchContacts(pickerPage.value + 1, true)
  }
}

// Debounced search → reset to page 1.
watch(pickerSearch, () => {
  if (!pickerOpen.value) {
    return
  }
  if (searchTimer !== null) {
    clearTimeout(searchTimer)
  }
  searchTimer = setTimeout(() => {
    void fetchContacts(1, false)
  }, SEARCH_DEBOUNCE_MS)
})

onMounted(() => {
  cancelled = false
  if (unifiedOn.value) {
    return
  }
  void load(true)
  schedule()
})

onBeforeUnmount(() => {
  cancelled = true
  if (timer !== null) {
    clearTimeout(timer)
    timer = null
  }
  if (searchTimer !== null) {
    clearTimeout(searchTimer)
    searchTimer = null
  }
})
</script>

<template>
  <section
    data-test="agency-messages-page"
    class="msg-shell"
    :class="{ 'msg-shell--split': isDesktop }"
  >
    <div v-if="showList" class="msg-shell__list" data-test="messages-list-pane">
      <AgencyUnifiedInbox
        v-if="unifiedOn"
        :items="unifiedItems"
        :facets="inbox.facets.value"
        :unread-threads-total="inbox.unreadThreadsTotal.value"
        :filters="filters"
        :loading="inbox.loading.value"
        :load-error="inbox.loadError.value"
        :loading-more="inbox.loadingMore.value"
        :has-more="inbox.hasMore.value"
        :active-key="activeKey"
        @start="openPicker"
        @load-more="inbox.loadMore"
        @update:filters="setFilters"
      />
      <AgencyRelationshipInbox
        v-else
        :items="items"
        :loading="loading"
        :load-error="loadError"
        :active-id="activeCreatorUlid"
        :labels="inboxLabels"
        @start="openPicker"
      />
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
      :title="t('app.messaging.relationship.picker.titleCreators')"
      :items="pickerItems"
      :loading="pickerLoading"
      :load-error="pickerError"
      :empty-label="t('app.messaging.relationship.picker.emptyCreators')"
      searchable
      :search="pickerSearch"
      :search-placeholder="t('app.messaging.relationship.picker.searchPlaceholder')"
      :has-more="pickerHasMore"
      :loading-more="pickerLoadingMore"
      @update:search="pickerSearch = $event"
      @load-more="loadMore"
    />
  </section>
</template>

<style scoped>
/* S3.4 — full-bleed (the layout drops its padding on this route). The shell is the viewport below
   the app bar. Desktop: a 460px list pane on the sidebar fill with a right border, the thread
   taking the rest. Below md one pane at a time, filling the width. */
.msg-shell {
  display: flex;
  height: calc(100dvh - var(--v-layout-top, 0px));
  min-height: 0;
}

.msg-shell__list {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  min-width: 0;
  min-height: 0;
  background-color: rgb(var(--v-theme-sidebar));
}

.msg-shell--split .msg-shell__list {
  flex: 0 0 460px;
  max-width: 460px;
  border-right: 1px solid rgb(var(--v-theme-border-color));
}

.msg-shell__detail {
  flex: 1 1 auto;
  min-width: 0;
  min-height: 0;
}

.msg-shell__placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: rgb(var(--v-theme-on-surface-variant));
  text-align: center;
}
</style>
