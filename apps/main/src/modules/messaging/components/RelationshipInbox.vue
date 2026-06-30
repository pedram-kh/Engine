<script setup lang="ts">
/**
 * AH-010b — the net-new conversations inbox (D8). A shared, presentation-only
 * list used by BOTH surfaces (agency keyed by creator, creator keyed by agency):
 * each parent page fetches its own inbox rows and normalizes them to
 * {@link RelationshipInboxItem}, so the list itself is direction-agnostic.
 *
 * Each row: avatar, counterparty name, last-message preview, relative timestamp,
 * and an unread badge. Clicking routes to the full-screen thread (`to`).
 */

import type { RouteLocationRaw } from 'vue-router'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

export interface RelationshipInboxItem {
  /** Stable key (the thread or counterparty ULID). */
  id: string
  title: string
  preview: string | null
  lastMessageAt: string | null
  unreadCount: number
  avatarText: string
  avatarUrl?: string | null
  to: RouteLocationRaw
}

defineProps<{
  items: RelationshipInboxItem[]
  loading: boolean
  loadError: boolean
  /**
   * AH-013 — the currently-open conversation's id, used to highlight the active
   * row in the two-pane (WhatsApp Web) layout. Optional + defaulted, so the
   * single-pane callers are unaffected.
   */
  activeId?: string | null
}>()

/**
 * AH-012 (D8) — the empty state is no longer a dead end: it offers a
 * "Start a conversation" CTA. The action is INJECTED (the picker source differs
 * per side — creator → agencies, agency → creators), so the shared component
 * emits `start` and the parent opens its own picker rather than hardcoding one.
 */
const emit = defineEmits<{
  start: []
}>()

const { t, locale } = useI18n()

const dateFormatter = computed(() => new Intl.DateTimeFormat(locale.value, { dateStyle: 'short' }))

function formatStamp(iso: string | null): string {
  if (iso === null) {
    return ''
  }
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? '' : dateFormatter.value.format(date)
}
</script>

<template>
  <div class="rel-inbox" data-test="relationship-inbox">
    <v-skeleton-loader v-if="loading" type="list-item-avatar-two-line@4" />

    <v-alert v-else-if="loadError" type="error" variant="tonal" density="compact">
      {{ t('app.messaging.relationship.inboxLoadError') }}
    </v-alert>

    <div
      v-else-if="items.length === 0"
      class="rel-inbox__empty"
      data-test="relationship-inbox-empty"
    >
      <p class="rel-inbox__empty-text">{{ t('app.messaging.relationship.inboxEmpty') }}</p>
      <v-btn
        color="primary"
        variant="tonal"
        prepend-icon="mdi-message-plus-outline"
        data-test="relationship-inbox-start"
        @click="emit('start')"
      >
        {{ t('app.messaging.relationship.startConversation') }}
      </v-btn>
    </div>

    <v-list v-else lines="two" data-test="relationship-inbox-list">
      <v-list-item
        v-for="item in items"
        :key="item.id"
        :to="item.to"
        :active="item.id === activeId"
        color="primary"
        :data-test="`relationship-inbox-row-${item.id}`"
      >
        <template #prepend>
          <v-avatar size="40" color="primary">
            <v-img v-if="item.avatarUrl" :src="item.avatarUrl" :alt="item.title" />
            <span v-else class="text-body-2 font-weight-bold text-white">
              {{ (item.avatarText || '?')[0]?.toUpperCase() }}
            </span>
          </v-avatar>
        </template>

        <v-list-item-title>{{ item.title }}</v-list-item-title>
        <v-list-item-subtitle>
          {{ item.preview ?? t('app.messaging.rollup.noMessages') }}
        </v-list-item-subtitle>

        <template #append>
          <div class="rel-inbox__meta">
            <span class="rel-inbox__time">{{ formatStamp(item.lastMessageAt) }}</span>
            <v-badge
              v-if="item.unreadCount > 0"
              :content="item.unreadCount"
              color="primary"
              inline
              :data-test="`relationship-inbox-unread-${item.id}`"
            />
          </div>
        </template>
      </v-list-item>
    </v-list>
  </div>
</template>

<style scoped>
.rel-inbox__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  text-align: center;
  padding: 32px 0;
}

.rel-inbox__empty-text {
  opacity: 0.6;
  margin: 0;
}

.rel-inbox__meta {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 2px;
}

.rel-inbox__time {
  font-size: 0.7rem;
  opacity: 0.6;
}
</style>
