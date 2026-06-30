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

import type { CreatorRelationshipThreadRow, MessageableAgencyRow } from '@catalyst/api-client'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useDisplay } from 'vuetify'

import { relationshipMessagingApi } from '../api/relationshipMessaging.api'
import ContactPicker, { type ContactPickerItem } from '../components/ContactPicker.vue'
import RelationshipInbox, { type RelationshipInboxItem } from '../components/RelationshipInbox.vue'

const { t } = useI18n()
const route = useRoute()
const display = useDisplay()

// AH-013 — two-pane only at ≥ md; below that, one pane at a time (mobile).
const isDesktop = computed(() => display.mdAndUp.value)
const activeAgencyUlid = computed(() =>
  typeof route.params.agencyUlid === 'string' ? route.params.agencyUlid : '',
)
const hasSelection = computed(() => activeAgencyUlid.value !== '')
const showList = computed(() => isDesktop.value || !hasSelection.value)
const showDetail = computed(() => isDesktop.value || hasSelection.value)

const INBOX_POLL_INTERVAL_MS = 45000

const rows = ref<CreatorRelationshipThreadRow[]>([])
const loading = ref(false)
const loadError = ref(false)

let cancelled = false
let timer: ReturnType<typeof setTimeout> | null = null

const items = computed<RelationshipInboxItem[]>(() =>
  rows.value
    .filter((row) => row.attributes.agency.id !== null)
    .map((row) => {
      const agency = row.attributes.agency
      const name = agency.name ?? t('app.messaging.participant')
      return {
        id: agency.id as string,
        title: name,
        preview: row.attributes.last_message_preview,
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
  loading.value = initial && rows.value.length === 0
  try {
    const res = await relationshipMessagingApi.creatorInbox()
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

      <div class="msg-shell__list-body">
        <RelationshipInbox
          :items="items"
          :loading="loading"
          :load-error="loadError"
          :active-id="activeAgencyUlid"
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
