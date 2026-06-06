<script setup lang="ts">
import type { MessageThreadRollupRow } from '@catalyst/api-client'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { agencyChatTransport, messagingApi, type ChatTransport } from '../api/messaging.api'
import ChatDialog from './ChatDialog.vue'

const props = defineProps<{
  agencyId: string
  campaignUlid: string
}>()

const { t } = useI18n()

/** Badges refresh on a longer interval than the open thread's ~15s poll (D-12). */
const ROLLUP_POLL_INTERVAL_MS = 45000

const rows = ref<MessageThreadRollupRow[]>([])
const loading = ref(false)
const loadError = ref(false)

const dialogOpen = ref(false)
const activeTransport = ref<ChatTransport | null>(null)
const activeTitle = ref<string | undefined>(undefined)

let cancelled = false
let timer: ReturnType<typeof setTimeout> | null = null

const hasThreads = computed(() => rows.value.length > 0)

async function loadRollup(initial = false): Promise<void> {
  loading.value = initial && rows.value.length === 0
  try {
    const res = await messagingApi.agencyRollup(props.agencyId, props.campaignUlid)
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
  }, ROLLUP_POLL_INTERVAL_MS)
}

async function tick(): Promise<void> {
  if (cancelled) {
    return
  }
  await loadRollup()
  if (cancelled) {
    return
  }
  schedule()
}

function openThread(row: MessageThreadRollupRow): void {
  if (row.attributes.assignment_id === null) {
    return
  }
  activeTransport.value = agencyChatTransport(
    props.agencyId,
    props.campaignUlid,
    row.attributes.assignment_id,
  )
  activeTitle.value = row.attributes.creator.display_name ?? t('app.messaging.title')
  dialogOpen.value = true
}

// When the operator closes a thread, refresh the badges (they just read it).
watch(dialogOpen, (open) => {
  if (!open) {
    void loadRollup()
  }
})

onMounted(() => {
  cancelled = false
  void loadRollup(true)
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
  <div class="campaign-messages" data-test="campaign-messages-panel">
    <v-skeleton-loader v-if="loading" type="list-item-two-line@3" />

    <v-alert v-else-if="loadError" type="error" variant="tonal" density="compact">
      {{ t('app.messaging.rollup.loadError') }}
    </v-alert>

    <p v-else-if="!hasThreads" class="campaign-messages__empty" data-test="campaign-messages-empty">
      {{ t('app.messaging.rollup.empty') }}
    </p>

    <v-list v-else lines="two" data-test="campaign-messages-list">
      <v-list-item
        v-for="row in rows"
        :key="row.id"
        :data-test="`thread-row-${row.id}`"
        @click="openThread(row)"
      >
        <v-list-item-title>
          {{ row.attributes.creator.display_name ?? t('app.messaging.participant') }}
        </v-list-item-title>
        <v-list-item-subtitle>
          {{ row.attributes.last_message_preview ?? t('app.messaging.rollup.noMessages') }}
        </v-list-item-subtitle>
        <template #append>
          <v-badge
            v-if="row.attributes.unread_count > 0"
            :content="row.attributes.unread_count"
            color="primary"
            inline
            :data-test="`thread-unread-${row.id}`"
          />
        </template>
      </v-list-item>
    </v-list>

    <ChatDialog v-model="dialogOpen" :transport="activeTransport" :title="activeTitle" />
  </div>
</template>

<style scoped>
.campaign-messages__empty {
  opacity: 0.6;
  text-align: center;
  padding: 24px 0;
}
</style>
