<script setup lang="ts">
/**
 * AH-114 (S2.2) — the agency fork of `RelationshipInbox`: the Figma conversation list
 * (Messages frames 18:71 and 21:85). `RelationshipInbox.vue` is shared with the creator
 * web and native app and is not edited (plan ruling 9a; spec 5 pins its hash); this is an
 * agency-only copy without the `isNativePlatform()` branch.
 *
 * Like the original it is presentation-only: a parent fetches its own inbox rows and
 * normalises them to {@link AgencyInboxItem}. The row follows the Figma list row: an unread
 * dot, the avatar, the counterparty name and the time on the first line, the preview and the
 * unread count on the second, an optional neutral tag, and a chevron.
 *
 * WHAT IS BUILT AND WHAT IS NOT (plan §3, S2.2):
 *   - The header controls are built but each renders only when the parent supplies its word
 *     in `labels` (the search box, the unread pill, the All / Unread toggle). A control the
 *     page has no string for is omitted, not shown with a placeholder, so this component adds
 *     no i18n key of its own; the strings arrive with the page that adopts it (S3.4).
 *   - The tag renders only when an item carries a `tag` (none does today; the inbox rows have
 *     no brand or campaign label).
 *   - The brand and "wave" filters of the frame are NOT built: the data does not exist (the
 *     inbox item has no brand, and the product has no waves).
 *
 * Existing keys only: the title, subtitle, empty text, start action and "New conversation".
 * Times use `Intl` (a time today, a localised "yesterday", then a short date).
 */

import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RouteLocationRaw } from 'vue-router'

/** The inbox row. Structurally the shared `RelationshipInboxItem` plus an optional tag. */
export interface AgencyInboxItem {
  /** Stable key (the thread or counterparty ULID). */
  id: string
  title: string
  preview: string | null
  lastMessageAt: string | null
  unreadCount: number
  avatarText: string
  avatarUrl?: string | null
  to: RouteLocationRaw
  /** A neutral tag under the preview (for example a brand). Rendered only when set. */
  tag?: string | null
}

/**
 * Words the PARENT owns. Each optional entry switches one control on; without it the
 * control is not rendered.
 */
export interface AgencyInboxLabels {
  /** Placeholder of the search box; the box filters by name and preview. */
  search?: string
  /** The unread pill next to the title, given the number of unread conversations. */
  unreadPill?: (count: number) => string
  /** The two segments of the All / Unread toggle (both are needed to show it). */
  all?: string
  unread?: string
  /** Shown when a search or the unread filter leaves nothing. */
  noMatch?: string
}

const props = defineProps<{
  items: AgencyInboxItem[]
  loading: boolean
  loadError: boolean
  /** The open conversation's id, to highlight its row in the two-pane layout. */
  activeId?: string | null
  labels?: AgencyInboxLabels
}>()

const emit = defineEmits<{
  /** The header "New conversation" button and the empty-state button. */
  start: []
}>()

const { t, locale } = useI18n()

const query = ref('')
const unreadOnly = ref(false)

const unreadConversations = computed(
  () => props.items.filter((item) => item.unreadCount > 0).length,
)

const showToggle = computed(
  () => props.labels?.all !== undefined && props.labels?.unread !== undefined,
)

const visibleItems = computed(() => {
  const needle = query.value.trim().toLowerCase()
  return props.items.filter((item) => {
    if (unreadOnly.value && item.unreadCount === 0) {
      return false
    }
    if (needle === '') {
      return true
    }
    return (
      item.title.toLowerCase().includes(needle) ||
      (item.preview ?? '').toLowerCase().includes(needle)
    )
  })
})

const timeFormatter = computed(() => new Intl.DateTimeFormat(locale.value, { timeStyle: 'short' }))
const dayFormatter = computed(
  () => new Intl.DateTimeFormat(locale.value, { month: 'short', day: 'numeric' }),
)
const fullFormatter = computed(() => new Intl.DateTimeFormat(locale.value, { dateStyle: 'short' }))
const relativeFormatter = computed(
  () => new Intl.RelativeTimeFormat(locale.value, { numeric: 'auto' }),
)

function startOfDay(date: Date): number {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime()
}

/** A time today, "yesterday", a month and day this year, otherwise a short date. */
function formatStamp(iso: string | null, now: Date = new Date()): string {
  if (iso === null) {
    return ''
  }
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) {
    return ''
  }
  const dayDiff = Math.round((startOfDay(date) - startOfDay(now)) / 86_400_000)
  if (dayDiff === 0) {
    return timeFormatter.value.format(date)
  }
  if (dayDiff === -1) {
    return relativeFormatter.value.format(-1, 'day')
  }
  return date.getFullYear() === now.getFullYear()
    ? dayFormatter.value.format(date)
    : fullFormatter.value.format(date)
}

function initial(item: AgencyInboxItem): string {
  return (item.avatarText || item.title || '?')[0]?.toUpperCase() ?? '?'
}
</script>

<template>
  <div class="agency-inbox" data-test="relationship-inbox">
    <header class="agency-inbox__header" data-test="agency-inbox-header">
      <div class="agency-inbox__heading">
        <span class="agency-inbox__icon-tile" aria-hidden="true">
          <v-icon icon="mdi-message-outline" size="20" />
        </span>
        <div class="agency-inbox__titles">
          <div class="agency-inbox__title-row">
            <h2 class="agency-inbox__title">{{ t('app.messaging.relationship.inboxTitle') }}</h2>
            <span
              v-if="labels?.unreadPill !== undefined && unreadConversations > 0"
              class="agency-inbox__unread-pill"
              data-test="agency-inbox-unread-pill"
              >{{ labels.unreadPill(unreadConversations) }}</span
            >
          </div>
          <p class="agency-inbox__subtitle">{{ t('app.messaging.relationship.inboxSubtitle') }}</p>
        </div>
        <v-btn
          icon="mdi-pencil-outline"
          variant="outlined"
          size="small"
          class="agency-inbox__new"
          :aria-label="t('app.messaging.relationship.newConversation')"
          data-test="agency-inbox-new"
          @click="emit('start')"
        />
      </div>

      <v-text-field
        v-if="labels?.search !== undefined"
        v-model="query"
        :placeholder="labels.search"
        :aria-label="labels.search"
        prepend-inner-icon="mdi-magnify"
        density="compact"
        variant="outlined"
        hide-details
        class="agency-inbox__search"
        data-test="agency-inbox-search"
      />

      <div
        v-if="showToggle"
        class="agency-inbox__toggle"
        role="radiogroup"
        data-test="agency-inbox-toggle"
      >
        <button
          type="button"
          role="radio"
          class="agency-inbox__segment"
          :class="{ 'agency-inbox__segment--on': !unreadOnly }"
          :aria-checked="!unreadOnly"
          data-test="agency-inbox-toggle-all"
          @click="unreadOnly = false"
        >
          {{ labels?.all }}
        </button>
        <button
          type="button"
          role="radio"
          class="agency-inbox__segment"
          :class="{ 'agency-inbox__segment--on': unreadOnly }"
          :aria-checked="unreadOnly"
          data-test="agency-inbox-toggle-unread"
          @click="unreadOnly = true"
        >
          {{ labels?.unread }}
        </button>
      </div>
    </header>

    <div class="agency-inbox__body">
      <v-skeleton-loader v-if="loading" type="list-item-avatar-two-line@4" />

      <v-alert v-else-if="loadError" type="error" variant="tonal" density="compact">
        {{ t('app.messaging.relationship.inboxLoadError') }}
      </v-alert>

      <div
        v-else-if="items.length === 0"
        class="agency-inbox__empty"
        data-test="relationship-inbox-empty"
      >
        <p class="agency-inbox__empty-text">{{ t('app.messaging.relationship.inboxEmpty') }}</p>
        <v-btn
          color="primary"
          variant="flat"
          prepend-icon="mdi-message-plus-outline"
          data-test="relationship-inbox-start"
          @click="emit('start')"
        >
          {{ t('app.messaging.relationship.startConversation') }}
        </v-btn>
      </div>

      <ul v-else class="agency-inbox__list" data-test="relationship-inbox-list">
        <li v-for="item in visibleItems" :key="item.id">
          <router-link
            :to="item.to"
            class="agency-inbox-row"
            :class="{
              'agency-inbox-row--active': item.id === activeId,
              'agency-inbox-row--unread': item.unreadCount > 0,
            }"
            :data-test="`relationship-inbox-row-${item.id}`"
          >
            <span
              class="agency-inbox-row__dot"
              aria-hidden="true"
              :data-test="`agency-inbox-dot-${item.id}`"
            />
            <v-avatar size="40" color="primary" class="agency-inbox-row__avatar">
              <v-img v-if="item.avatarUrl" :src="item.avatarUrl" :alt="item.title" />
              <span v-else class="agency-inbox-row__initial">{{ initial(item) }}</span>
            </v-avatar>
            <span class="agency-inbox-row__main">
              <span class="agency-inbox-row__top">
                <span class="agency-inbox-row__name">{{ item.title }}</span>
                <span class="agency-inbox-row__time">{{ formatStamp(item.lastMessageAt) }}</span>
              </span>
              <span class="agency-inbox-row__mid">
                <span class="agency-inbox-row__preview">{{
                  item.preview ?? t('app.messaging.rollup.noMessages')
                }}</span>
                <span
                  v-if="item.unreadCount > 0"
                  class="agency-inbox-row__count"
                  :data-test="`relationship-inbox-unread-${item.id}`"
                  >{{ item.unreadCount }}</span
                >
              </span>
              <span
                v-if="item.tag"
                class="agency-inbox-row__tag"
                :data-test="`agency-inbox-tag-${item.id}`"
                >{{ item.tag }}</span
              >
            </span>
            <v-icon
              icon="mdi-chevron-right"
              size="16"
              class="agency-inbox-row__chev"
              aria-hidden="true"
            />
          </router-link>
        </li>
      </ul>

      <p
        v-if="
          !loading && !loadError && items.length > 0 && visibleItems.length === 0 && labels?.noMatch
        "
        class="agency-inbox__no-match"
        data-test="agency-inbox-no-match"
      >
        {{ labels.noMatch }}
      </p>
    </div>
  </div>
</template>

<style scoped>
.agency-inbox {
  display: flex;
  flex-direction: column;
  min-height: 0;
  height: 100%;
}

/* Header: 20 on the sides, 16 below, 16 between the blocks (Figma 20:87). */
.agency-inbox__header {
  display: flex;
  flex-direction: column;
  gap: var(--space-4, 16px);
  padding: var(--space-5, 20px) var(--space-5, 20px) var(--space-4, 16px);
  border-bottom: 1px solid rgb(var(--v-theme-border-color));
}

.agency-inbox__heading {
  display: flex;
  align-items: center;
  gap: var(--space-3, 12px);
}

.agency-inbox__icon-tile {
  flex: none;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-xl, 10px);
  background-color: rgb(var(--v-theme-primary-container));
  color: rgb(var(--v-theme-primary-text));
}

.agency-inbox__titles {
  flex: 1 1 auto;
  min-width: 0;
}

.agency-inbox__title-row {
  display: flex;
  align-items: center;
  gap: var(--space-2, 8px);
}

.agency-inbox__title {
  margin: 0;
  font-size: var(--catalyst-typography-heading-2-size);
  line-height: var(--catalyst-typography-heading-2-line-height);
  font-weight: var(--catalyst-typography-heading-2-weight);
  color: rgb(var(--v-theme-on-surface));
}

.agency-inbox__unread-pill {
  padding: 2px 8px;
  border-radius: 999px;
  background-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
  font-size: var(--catalyst-typography-caption-size);
  line-height: var(--catalyst-typography-caption-line-height);
  font-weight: 500;
  white-space: nowrap;
}

.agency-inbox__subtitle {
  margin: 0;
  font-size: var(--catalyst-typography-caption-size);
  line-height: var(--catalyst-typography-caption-line-height);
  color: rgb(var(--v-theme-on-surface-variant));
}

.agency-inbox__new {
  flex: none;
}

/* The All / Unread segmented control: a bordered track, the selected segment raised. */
.agency-inbox__toggle {
  align-self: flex-end;
  display: inline-flex;
  padding: 3px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: var(--radius-lg, 8px);
  background-color: rgb(var(--v-theme-surface));
}

.agency-inbox__segment {
  padding: 4px 10px;
  border: 0;
  border-radius: var(--radius-md, 6px);
  background: transparent;
  color: rgb(var(--v-theme-text-tertiary));
  font: inherit;
  font-size: var(--catalyst-typography-caption-size);
  line-height: var(--catalyst-typography-caption-line-height);
  font-weight: 500;
  cursor: pointer;
}

.agency-inbox__segment--on {
  background-color: rgb(var(--v-theme-surface-bright));
  color: rgb(var(--v-theme-on-surface));
}

.agency-inbox__segment:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 1px;
}

.agency-inbox__body {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  padding: var(--space-2, 8px);
}

.agency-inbox__list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.agency-inbox__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-3, 12px);
  text-align: center;
  padding: 32px 0;
}

.agency-inbox__empty-text,
.agency-inbox__no-match {
  margin: 0;
  color: rgb(var(--v-theme-on-surface-variant));
}

.agency-inbox__no-match {
  padding: 24px 12px;
  text-align: center;
}

/* The row (Figma 20:130 / 20:146): 10 left, 12 right, 12 vertical, 12 between, radius 10. */
.agency-inbox-row {
  display: flex;
  align-items: center;
  gap: var(--space-3, 12px);
  padding: 12px 12px 12px 10px;
  border: 1px solid transparent;
  border-radius: var(--radius-xl, 10px);
  color: inherit;
  text-decoration: none;
}

.agency-inbox-row:hover {
  background-color: rgb(var(--v-theme-surface-bright));
}

.agency-inbox-row:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}

.agency-inbox-row--active,
.agency-inbox-row--active:hover {
  background-color: rgb(var(--v-theme-primary-container));
  border-color: rgb(var(--v-theme-primary));
}

/* The dot's slot is always there so the avatars line up; it shows only for unread. */
.agency-inbox-row__dot {
  flex: none;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background-color: transparent;
}

.agency-inbox-row--unread .agency-inbox-row__dot {
  background-color: rgb(var(--v-theme-primary));
}

.agency-inbox-row__initial {
  font-size: var(--catalyst-typography-caption-size);
  line-height: var(--catalyst-typography-caption-line-height);
  font-weight: 500;
  color: rgb(var(--v-theme-on-primary));
}

.agency-inbox-row__main {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: var(--space-1, 4px);
}

.agency-inbox-row__top,
.agency-inbox-row__mid {
  display: flex;
  align-items: center;
  gap: var(--space-2, 8px);
  width: 100%;
  min-width: 0;
}

.agency-inbox-row__name {
  flex: 1 1 auto;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: var(--catalyst-typography-body-size);
  line-height: var(--catalyst-typography-body-line-height);
  font-weight: 500;
  color: rgb(var(--v-theme-on-surface));
}

.agency-inbox-row__time {
  flex: none;
  font-size: var(--catalyst-typography-caption-size);
  line-height: var(--catalyst-typography-caption-line-height);
  font-weight: 500;
  white-space: nowrap;
  color: rgb(var(--v-theme-text-tertiary));
}

.agency-inbox-row--unread .agency-inbox-row__time {
  color: rgb(var(--v-theme-primary-text));
}

.agency-inbox-row__preview {
  flex: 1 1 auto;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: var(--catalyst-typography-body-sm-size);
  line-height: var(--catalyst-typography-body-sm-line-height);
  color: rgb(var(--v-theme-on-surface-variant));
}

.agency-inbox-row__count {
  flex: none;
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  border-radius: 999px;
  background-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
  font-size: var(--catalyst-typography-caption-size);
  line-height: var(--catalyst-typography-caption-line-height);
  font-weight: 500;
}

/* One neutral tag style (plan ruling 7): the neutral tint with the neutral text colour. */
.agency-inbox-row__tag {
  position: relative;
  isolation: isolate;
  padding: 2px 8px;
  border-radius: 999px;
  color: rgb(var(--v-theme-neutral-text));
  font-size: var(--catalyst-typography-caption-size);
  line-height: var(--catalyst-typography-caption-line-height);
  font-weight: 500;
  white-space: pre;
}

.agency-inbox-row__tag::before {
  content: '';
  position: absolute;
  inset: 0;
  z-index: -1;
  border-radius: inherit;
  background-color: rgb(var(--v-theme-status-neutral));
  opacity: 0.15;
}

.agency-inbox-row__chev {
  flex: none;
  color: rgb(var(--v-theme-text-tertiary));
}
</style>
