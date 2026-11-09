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

import { useNativeLook } from '@/composables/useNativeLook'

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
  /**
   * AH-115 L4: the conversation's tag on the creator's unified inbox ("Direct", or "Brand · Campaign").
   * Absent for every other caller, which renders no tag.
   */
  tag?: string | null
  /** The tag is the Direct one (rendered neutral, not as a campaign chip). */
  tagDirect?: boolean
}

const props = defineProps<{
  items: RelationshipInboxItem[]
  loading: boolean
  loadError: boolean
  /**
   * AH-013 — the currently-open conversation's id, used to highlight the active
   * row in the two-pane (WhatsApp Web) layout. Optional + defaulted, so the
   * single-pane callers are unaffected.
   */
  activeId?: string | null
  /**
   * AH-115 L4: stamp each row by the unified-inbox rule (today a time, this year "Nov 9", older
   * "Nov 9, 2025"), the same rule as the agency inbox. Off by default: every other caller keeps
   * the short numeric date.
   */
  dayAwareStamps?: boolean
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

// A tagged row has a third line; the two-line row height would clip it.
const listLines = computed(() => (props.items.some((i) => i.tag) ? 'three' : 'two'))

/**
 * AH-100d: inside the creator app the CTA is the filled primary button. The
 * web's tonal variant puts Vuetify's `text-primary` (`color: ... !important`)
 * on the label, and the native sheet's mint fill made it mint-on-mint: a blank
 * pill. The label resolves fine; the paint did not. A filled button takes the
 * app's own on-accent label colour.
 */
const isNative = useNativeLook()

const dateFormatter = computed(() => new Intl.DateTimeFormat(locale.value, { dateStyle: 'short' }))
const timeFormatter = computed(() => new Intl.DateTimeFormat(locale.value, { timeStyle: 'short' }))
const dayFormatter = computed(
  () => new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'short' }),
)
const fullFormatter = computed(
  () => new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'short', year: 'numeric' }),
)

function startOfDay(date: Date): number {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime()
}

function formatStamp(iso: string | null, now: Date = new Date()): string {
  if (iso === null) {
    return ''
  }
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) {
    return ''
  }
  if (props.dayAwareStamps !== true) {
    return dateFormatter.value.format(date)
  }
  if (startOfDay(date) === startOfDay(now)) {
    return timeFormatter.value.format(date)
  }
  return date.getFullYear() === now.getFullYear()
    ? dayFormatter.value.format(date)
    : fullFormatter.value.format(date)
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
        :variant="isNative ? 'flat' : 'tonal'"
        prepend-icon="mdi-message-plus-outline"
        data-test="relationship-inbox-start"
        @click="emit('start')"
      >
        {{ t('app.messaging.relationship.startConversation') }}
      </v-btn>
    </div>

    <v-list v-else :lines="listLines" data-test="relationship-inbox-list">
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
        <div
          v-if="item.tag"
          class="rel-inbox__tag"
          :class="{ 'rel-inbox__tag--direct': item.tagDirect === true }"
          :data-test="`relationship-inbox-tag-${item.id}`"
        >
          {{ item.tag }}
        </div>

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

.rel-inbox__tag {
  display: inline-block;
  max-width: 100%;
  margin-top: 4px;
  padding: 1px 8px;
  overflow: hidden;
  border-radius: 999px;
  background: rgba(var(--v-theme-primary), 0.14);
  color: rgb(var(--v-theme-primary));
  font-size: 0.7rem;
  line-height: 1.5;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.rel-inbox__tag--direct {
  background: rgba(var(--v-theme-on-surface), 0.08);
  color: rgba(var(--v-theme-on-surface), 0.7);
}

.rel-inbox__time {
  font-size: 0.7rem;
  opacity: 0.6;
}
</style>
