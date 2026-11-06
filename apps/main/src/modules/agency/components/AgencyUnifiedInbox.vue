<script setup lang="ts">
/**
 * AH-119 — the unified agency inbox: Direct (relationship) and campaign (assignment)
 * conversations in one list, with the brand and campaign filters, the Mine chip and the
 * All / Unread toggle (Figma Messages frames 16:7838 and 16:8144).
 *
 * Presentation only: `AgencyMessagesPage` fetches (`useMessageInbox`), normalises the rows to
 * {@link UnifiedInboxItem} and owns the filters, which live in the route query. This is a sibling
 * of `AgencyRelationshipInbox`, which stays as it was and renders while the flag is off.
 *
 * Search filters the rows already loaded, by name, preview, brand and campaign; searching message
 * text is Stage B. The Figma subtitle is left out (the relationship subtitle reads "direct
 * conversations", which is wrong here, and the frame's wording has no key). The unread pill is the UNFILTERED total of threads with unread messages (P6);
 * the row badge is that thread's unread messages.
 */

import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RouteLocationRaw } from 'vue-router'
import type { MessageInboxFacets } from '@catalyst/api-client'

import { brandColorVar, effectiveBrandColor } from '../composables/brandColor'
import InboxFilterPopover, { type InboxFilterItem } from './InboxFilterPopover.vue'

export interface UnifiedInboxItem {
  /** Unique across both thread types. */
  key: string
  title: string
  preview: string | null
  previewIsAttachment: boolean
  lastMessageAt: string | null
  unreadCount: number
  avatarText: string
  avatarUrl?: string | null
  to: RouteLocationRaw
  /** True for a Direct conversation. */
  direct: boolean
  brandName: string | null
  /** The brand's ULID, so a brand with no colour of its own gets a stable derived one. */
  brandId?: string | null
  brandColor: string | null
  campaignName: string | null
}

export interface UnifiedInboxFilters {
  brand: string[]
  campaign: string[]
  unread: boolean
  owner: 'me' | 'all'
}

const props = defineProps<{
  items: UnifiedInboxItem[]
  facets: MessageInboxFacets
  /** Unfiltered number of threads with unread messages (the pill). */
  unreadThreadsTotal: number
  filters: UnifiedInboxFilters
  loading: boolean
  loadError: boolean
  loadingMore: boolean
  hasMore: boolean
  activeKey?: string | null
}>()

const emit = defineEmits<{
  start: []
  loadMore: []
  'update:filters': [value: UnifiedInboxFilters]
}>()

const { t, locale } = useI18n()

const query = ref('')

const brandItems = computed<InboxFilterItem[]>(() =>
  props.facets.brands.map((b) => ({
    id: b.id,
    name: b.name,
    color: effectiveBrandColor(b.color, b.id),
    unreadThreads: b.unread_threads,
  })),
)

// Picking brands narrows the campaign list to those brands' campaigns.
const campaignItems = computed<InboxFilterItem[]>(() =>
  props.facets.campaigns
    .filter(
      (c) =>
        props.filters.brand.length === 0 ||
        c.brand_id === null ||
        props.filters.brand.includes(c.brand_id),
    )
    .map((c) => ({ id: c.id, name: c.name, unreadThreads: c.unread_threads })),
)

const anyFilter = computed(
  () =>
    props.filters.brand.length > 0 ||
    props.filters.campaign.length > 0 ||
    props.filters.unread ||
    props.filters.owner === 'me',
)

function patch(partial: Partial<UnifiedInboxFilters>): void {
  emit('update:filters', { ...props.filters, ...partial })
}

function clearFilters(): void {
  emit('update:filters', { brand: [], campaign: [], unread: false, owner: 'all' })
}

const visibleItems = computed(() => {
  const needle = query.value.trim().toLowerCase()
  if (needle === '') {
    return props.items
  }
  return props.items.filter((item) =>
    [item.title, item.preview, item.brandName, item.campaignName].some((part) =>
      (part ?? '').toLowerCase().includes(needle),
    ),
  )
})

function tagText(item: UnifiedInboxItem): string {
  if (item.direct) {
    return t('app.messaging.inbox.tagDirect')
  }
  if (item.brandName !== null && item.campaignName !== null) {
    return t('app.messaging.inbox.tagCampaign', {
      brand: item.brandName,
      campaign: item.campaignName,
    })
  }
  return item.campaignName ?? item.brandName ?? ''
}

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

/** Today a time, earlier this year a day and month, an earlier year the full date. */
function formatStamp(iso: string | null, now: Date = new Date()): string {
  if (iso === null) {
    return ''
  }
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) {
    return ''
  }
  if (startOfDay(date) === startOfDay(now)) {
    return timeFormatter.value.format(date)
  }
  return date.getFullYear() === now.getFullYear()
    ? dayFormatter.value.format(date)
    : fullFormatter.value.format(date)
}

function initial(item: UnifiedInboxItem): string {
  return (item.avatarText || item.title || '?')[0]?.toUpperCase() ?? '?'
}
</script>

<template>
  <div class="agency-inbox" data-test="unified-inbox">
    <header class="agency-inbox__header" data-test="agency-inbox-header">
      <div class="agency-inbox__heading">
        <span class="agency-inbox__icon-tile" aria-hidden="true">
          <v-icon icon="mdi-message-outline" size="20" />
        </span>
        <div class="agency-inbox__titles">
          <div class="agency-inbox__title-row">
            <h2 class="agency-inbox__title">{{ t('app.messaging.relationship.inboxTitle') }}</h2>
            <span
              v-if="unreadThreadsTotal > 0"
              class="agency-inbox__unread-pill"
              data-test="agency-inbox-unread-pill"
              >{{
                t(
                  'app.messaging.relationship.inboxUnreadPill',
                  { count: unreadThreadsTotal },
                  unreadThreadsTotal,
                )
              }}</span
            >
          </div>
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
        v-model="query"
        :placeholder="t('app.messaging.relationship.inboxSearch')"
        :aria-label="t('app.messaging.relationship.inboxSearch')"
        prepend-inner-icon="mdi-magnify"
        density="compact"
        variant="outlined"
        hide-details
        class="agency-inbox__search"
        data-test="agency-inbox-search"
      />

      <div class="agency-inbox__filters" data-test="agency-inbox-filters">
        <InboxFilterPopover
          :model-value="filters.brand"
          :items="brandItems"
          :all-label="t('app.messaging.inbox.allBrands')"
          :heading="t('app.messaging.inbox.brandsHeading')"
          :search-placeholder="t('app.messaging.inbox.searchBrands')"
          :no-match="t('app.messaging.inbox.noBrandMatch')"
          icon="mdi-tag-outline"
          dots
          test-key="brand"
          @update:model-value="patch({ brand: $event })"
        />
        <InboxFilterPopover
          :model-value="filters.campaign"
          :items="campaignItems"
          :all-label="t('app.messaging.inbox.allCampaigns')"
          :heading="t('app.messaging.inbox.campaignsHeading')"
          :search-placeholder="t('app.messaging.inbox.searchCampaigns')"
          :no-match="t('app.messaging.inbox.noCampaignMatch')"
          icon="mdi-layers-outline"
          test-key="campaign"
          @update:model-value="patch({ campaign: $event })"
        />
        <button
          type="button"
          class="agency-inbox__mine"
          :class="{ 'agency-inbox__mine--on': filters.owner === 'me' }"
          :aria-pressed="filters.owner === 'me'"
          :aria-label="t('app.messaging.inbox.ownerFilterLabel')"
          data-test="agency-inbox-mine"
          @click="patch({ owner: filters.owner === 'me' ? 'all' : 'me' })"
        >
          <v-icon icon="mdi-account-outline" size="14" aria-hidden="true" />
          {{ t('app.messaging.inbox.ownerMine') }}
        </button>

        <div class="agency-inbox__toggle" role="radiogroup" data-test="agency-inbox-toggle">
          <button
            type="button"
            role="radio"
            class="agency-inbox__segment"
            :class="{ 'agency-inbox__segment--on': !filters.unread }"
            :aria-checked="!filters.unread"
            data-test="agency-inbox-toggle-all"
            @click="patch({ unread: false })"
          >
            {{ t('app.messaging.relationship.inboxFilterAll') }}
          </button>
          <button
            type="button"
            role="radio"
            class="agency-inbox__segment"
            :class="{ 'agency-inbox__segment--on': filters.unread }"
            :aria-checked="filters.unread"
            data-test="agency-inbox-toggle-unread"
            @click="patch({ unread: true })"
          >
            {{ t('app.messaging.relationship.inboxFilterUnread') }}
          </button>
        </div>
      </div>

      <button
        v-if="anyFilter"
        type="button"
        class="agency-inbox__clear"
        data-test="agency-inbox-clear-filters"
        @click="clearFilters"
      >
        <v-icon icon="mdi-close" size="12" aria-hidden="true" />
        {{ t('app.messaging.inbox.clearFilters') }}
      </button>
    </header>

    <div class="agency-inbox__body">
      <v-skeleton-loader v-if="loading" type="list-item-avatar-two-line@4" />

      <v-alert v-else-if="loadError" type="error" variant="tonal" density="compact">
        {{ t('app.messaging.relationship.inboxLoadError') }}
      </v-alert>

      <div
        v-else-if="items.length === 0 && !anyFilter"
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

      <p
        v-else-if="items.length === 0"
        class="agency-inbox__no-match"
        data-test="agency-inbox-filtered-empty"
      >
        {{ t('app.messaging.inbox.filteredEmpty') }}
      </p>

      <ul v-else class="agency-inbox__list" data-test="relationship-inbox-list">
        <li v-for="item in visibleItems" :key="item.key">
          <router-link
            :to="item.to"
            class="agency-inbox-row"
            :class="{
              'agency-inbox-row--active': item.key === activeKey,
              'agency-inbox-row--unread': item.unreadCount > 0,
            }"
            :data-test="`inbox-row-${item.key}`"
          >
            <span class="agency-inbox-row__dot" aria-hidden="true" />
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
                <v-icon
                  v-if="item.previewIsAttachment"
                  icon="mdi-paperclip"
                  size="14"
                  class="agency-inbox-row__clip"
                  aria-hidden="true"
                />
                <span class="agency-inbox-row__preview">{{
                  item.preview ??
                  (item.previewIsAttachment
                    ? t('app.messaging.attachment')
                    : t('app.messaging.rollup.noMessages'))
                }}</span>
                <span
                  v-if="item.unreadCount > 0"
                  class="agency-inbox-row__count"
                  :data-test="`inbox-unread-${item.key}`"
                  >{{ item.unreadCount }}</span
                >
              </span>
              <span
                v-if="tagText(item)"
                class="agency-inbox-row__tag"
                :class="{ 'agency-inbox-row__tag--direct': item.direct }"
                :data-test="`inbox-tag-${item.key}`"
              >
                <span
                  v-if="!item.direct && item.brandName !== null"
                  class="agency-inbox-row__tag-dot"
                  :style="{
                    backgroundColor: brandColorVar(
                      effectiveBrandColor(item.brandColor, item.brandId ?? ''),
                    ),
                  }"
                  aria-hidden="true"
                />{{ tagText(item) }}</span
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
        v-if="!loading && !loadError && items.length > 0 && visibleItems.length === 0"
        class="agency-inbox__no-match"
        data-test="agency-inbox-no-match"
      >
        {{ t('app.messaging.relationship.inboxNoMatch') }}
      </p>

      <div v-if="hasMore && !loading && !loadError" class="agency-inbox__more">
        <v-btn
          variant="text"
          size="small"
          :loading="loadingMore"
          data-test="agency-inbox-load-more"
          @click="emit('loadMore')"
        >
          {{ t('app.messaging.inbox.loadMore') }}
        </v-btn>
      </div>
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
/* Filter row: the two popover chips, the Mine chip and the All / Unread toggle. */
.agency-inbox__filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2, 8px);
}

.agency-inbox__filters .agency-inbox__toggle {
  align-self: auto;
  margin-left: auto;
}

.agency-inbox__mine {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1, 4px);
  padding: 7px 10px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: var(--radius-lg, 8px);
  background: transparent;
  color: rgb(var(--v-theme-on-surface-variant));
  font: inherit;
  font-size: var(--catalyst-typography-body-sm-size);
  line-height: var(--catalyst-typography-body-sm-line-height);
  cursor: pointer;
}

.agency-inbox__mine--on {
  border-color: rgb(var(--v-theme-primary));
  background-color: rgb(var(--v-theme-primary-container));
  color: rgb(var(--v-theme-primary-text));
}

.agency-inbox__mine:focus-visible,
.agency-inbox__clear:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 1px;
}

.agency-inbox__clear {
  align-self: flex-end;
  display: inline-flex;
  align-items: center;
  gap: var(--space-1, 4px);
  margin-top: calc(-1 * var(--space-2, 8px));
  padding: 0;
  border: 0;
  background: transparent;
  color: rgb(var(--v-theme-primary-text));
  font: inherit;
  font-size: var(--catalyst-typography-caption-size);
  line-height: var(--catalyst-typography-caption-line-height);
  font-weight: 500;
  cursor: pointer;
}

.agency-inbox__more {
  display: flex;
  justify-content: center;
  padding: var(--space-3, 12px) 0;
}

.agency-inbox-row__clip {
  flex: none;
  color: rgb(var(--v-theme-on-surface-variant));
}

.agency-inbox-row__tag-dot {
  display: inline-block;
  width: 6px;
  height: 6px;
  margin-right: 6px;
  border-radius: 50%;
  vertical-align: middle;
}
</style>
