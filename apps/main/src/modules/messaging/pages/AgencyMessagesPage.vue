<script setup lang="ts">
/**
 * AH-010b — the AGENCY conversations inbox (top-level "Messages"). Lists the
 * agency's relationship threads (one per connected creator), keyed by creator.
 * Org-level (Q4): any active member sees the same inbox. A 45s poll keeps unread
 * badges fresh. Clicking a row opens the full-screen thread.
 */

import type { AgencyRelationshipThreadRow, MessageableCreatorRow } from '@catalyst/api-client'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { useAgencyStore } from '@/core/stores/useAgencyStore'

import { relationshipMessagingApi } from '../api/relationshipMessaging.api'
import ContactPicker, { type ContactPickerItem } from '../components/ContactPicker.vue'
import RelationshipInbox, { type RelationshipInboxItem } from '../components/RelationshipInbox.vue'

const { t } = useI18n()
const agencyStore = useAgencyStore()

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
  <section data-test="agency-messages-page">
    <header class="d-flex align-start justify-space-between ga-3 mb-4">
      <div>
        <h1 class="text-h5 mb-1">{{ t('app.messaging.relationship.inboxTitle') }}</h1>
        <p class="text-body-2 text-medium-emphasis ma-0">
          {{ t('app.messaging.relationship.inboxSubtitle') }}
        </p>
      </div>
      <v-btn
        color="primary"
        variant="tonal"
        prepend-icon="mdi-message-plus-outline"
        data-test="agency-new-conversation"
        @click="openPicker"
      >
        {{ t('app.messaging.relationship.newConversation') }}
      </v-btn>
    </header>

    <RelationshipInbox
      :items="items"
      :loading="loading"
      :load-error="loadError"
      @start="openPicker"
    />

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
