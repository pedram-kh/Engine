<script setup lang="ts">
/**
 * AH-010b — the AGENCY conversations inbox (top-level "Messages"). Lists the
 * agency's relationship threads (one per connected creator), keyed by creator.
 * Org-level (Q4): any active member sees the same inbox. A 45s poll keeps unread
 * badges fresh. Clicking a row opens the full-screen thread.
 */

import type { AgencyRelationshipThreadRow } from '@catalyst/api-client'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { useAgencyStore } from '@/core/stores/useAgencyStore'

import { relationshipMessagingApi } from '../api/relationshipMessaging.api'
import RelationshipInbox, { type RelationshipInboxItem } from '../components/RelationshipInbox.vue'

const { t } = useI18n()
const agencyStore = useAgencyStore()

const INBOX_POLL_INTERVAL_MS = 45000

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
  <section data-test="agency-messages-page">
    <header class="mb-4">
      <h1 class="text-h5 mb-1">{{ t('app.messaging.relationship.inboxTitle') }}</h1>
      <p class="text-body-2 text-medium-emphasis ma-0">
        {{ t('app.messaging.relationship.inboxSubtitle') }}
      </p>
    </header>

    <RelationshipInbox :items="items" :loading="loading" :load-error="loadError" />
  </section>
</template>
