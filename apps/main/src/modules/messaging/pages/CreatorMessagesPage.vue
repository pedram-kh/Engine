<script setup lang="ts">
/**
 * AH-010b — the CREATOR conversations inbox (top-level "Messages"). Lists the
 * creator's relationship threads (one per connected agency), keyed by agency.
 * A 45s poll keeps unread badges fresh (longer than an open thread's ~15s poll,
 * the CampaignMessagesPanel precedent). Clicking a row opens the full-screen
 * thread.
 */

import type { CreatorRelationshipThreadRow } from '@catalyst/api-client'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { relationshipMessagingApi } from '../api/relationshipMessaging.api'
import RelationshipInbox, { type RelationshipInboxItem } from '../components/RelationshipInbox.vue'

const { t } = useI18n()

const INBOX_POLL_INTERVAL_MS = 45000

const rows = ref<CreatorRelationshipThreadRow[]>([])
const loading = ref(false)
const loadError = ref(false)

let cancelled = false
let timer: ReturnType<typeof setTimeout> | null = null

function httpAvatar(path: string | null): string | null {
  return path !== null && /^https?:\/\//i.test(path) ? path : null
}

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
        avatarUrl: httpAvatar(agency.logo_path),
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
  <section data-test="creator-messages-page">
    <header class="mb-4">
      <h1 class="text-h5 mb-1">{{ t('app.messaging.relationship.inboxTitle') }}</h1>
      <p class="text-body-2 text-medium-emphasis ma-0">
        {{ t('app.messaging.relationship.inboxSubtitle') }}
      </p>
    </header>

    <RelationshipInbox :items="items" :loading="loading" :load-error="loadError" />
  </section>
</template>
