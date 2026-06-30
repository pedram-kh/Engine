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
import { useRoute } from 'vue-router'
import { useDisplay } from 'vuetify'

import { useAgencyStore } from '@/core/stores/useAgencyStore'

import { relationshipMessagingApi } from '../api/relationshipMessaging.api'
import ContactPicker, { type ContactPickerItem } from '../components/ContactPicker.vue'
import RelationshipInbox, { type RelationshipInboxItem } from '../components/RelationshipInbox.vue'

const { t } = useI18n()
const agencyStore = useAgencyStore()
const route = useRoute()
const display = useDisplay()

// AH-013 — two-pane only at ≥ md; below that, one pane at a time (mobile).
const isDesktop = computed(() => display.mdAndUp.value)
const activeCreatorUlid = computed(() =>
  typeof route.params.creatorUlid === 'string' ? route.params.creatorUlid : '',
)
const hasSelection = computed(() => activeCreatorUlid.value !== '')
const showList = computed(() => isDesktop.value || !hasSelection.value)
const showDetail = computed(() => isDesktop.value || hasSelection.value)

const INBOX_POLL_INTERVAL_MS = 45000
const PICKER_PER_PAGE = 25
const SEARCH_DEBOUNCE_MS = 300

const rows = ref<AgencyRelationshipThreadRow[]>([])
const loading = ref(false)
const loadError = ref(false)

let cancelled = false
let timer: ReturnType<typeof setTimeout> | null = null

const items = computed<RelationshipInboxItem[]>(() =>
  rows.value
    .filter((row) => row.attributes.creator.id !== null)
    .map((row) => {
      const creator = row.attributes.creator
      const name = creator.display_name ?? t('app.messaging.participant')
      return {
        id: creator.id as string,
        title: name,
        preview: row.attributes.last_message_preview,
        lastMessageAt: row.attributes.last_message_at,
        unreadCount: row.attributes.unread_count,
        avatarText: name,
        avatarUrl: creator.avatar_url,
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
    const name = row.attributes.display_name ?? t('app.messaging.participant')
    return {
      id: row.id,
      title: name,
      avatarText: name,
      avatarUrl: row.attributes.avatar_url,
      to: {
        name: 'messages.thread',
        params: { creatorUlid: row.id },
        query: { name },
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
      <header class="msg-shell__list-header">
        <h1 class="text-h6 ma-0">{{ t('app.messaging.relationship.inboxTitle') }}</h1>
        <v-btn
          color="primary"
          variant="tonal"
          size="small"
          prepend-icon="mdi-message-plus-outline"
          data-test="agency-new-conversation"
          @click="openPicker"
        >
          {{ t('app.messaging.relationship.newConversation') }}
        </v-btn>
      </header>

      <div class="msg-shell__list-body">
        <RelationshipInbox
          :items="items"
          :loading="loading"
          :load-error="loadError"
          :active-id="activeCreatorUlid"
          @start="openPicker"
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
/* AH-013 — desktop two-pane (WhatsApp Web). Mobile falls back to plain flow
   (single pane at a time), so the split rules are gated on .msg-shell--split. */
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
