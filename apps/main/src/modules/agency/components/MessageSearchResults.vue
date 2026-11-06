<script setup lang="ts">
/**
 * AH-119 Stage B — the "Messages" result group under the threads in the unified agency inbox:
 * what the full-text message search found. Each hit shows the snippet (plain text, never HTML),
 * who wrote it ("You" for the viewer's own, else the creator's name) and the conversation it is
 * in; a hit is a link that opens that conversation.
 *
 * Presentation only: `AgencyMessagesPage` runs the search (`useMessageSearch`) and builds the
 * items. States: `minChars` (one character typed), `loading`, `error` (typing again retries), `empty`,
 * `results` (with "Load more" when the server has another page); `idle` renders nothing.
 */

import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RouteLocationRaw } from 'vue-router'

import type { MessageSearchState } from '@/modules/messaging/composables/useMessageSearch'

export interface MessageSearchItem {
  /** The message ULID: unique across the list. */
  key: string
  /** The conversation's counterparty, for "in {name}". */
  threadTitle: string
  /** "You", the creator's name, or null when the sender cannot be named. */
  senderLabel: string | null
  snippet: string
  sentAt: string
  to: RouteLocationRaw
}

defineProps<{
  state: MessageSearchState
  items: MessageSearchItem[]
  hasMore: boolean
  loadingMore: boolean
}>()

const emit = defineEmits<{ loadMore: [] }>()

const { t, locale } = useI18n()

const formatter = computed(
  () => new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'short' }),
)

function formatSent(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? '' : formatter.value.format(date)
}
</script>

<template>
  <section
    v-if="state !== 'idle'"
    class="agency-msg-search"
    data-test="message-search-results"
    :aria-label="t('app.messaging.search.resultsHeading')"
  >
    <h3 class="agency-msg-search__heading">{{ t('app.messaging.search.resultsHeading') }}</h3>

    <p v-if="state === 'minChars'" class="agency-msg-search__note" data-test="message-search-min">
      {{ t('app.messaging.search.minChars') }}
    </p>

    <v-skeleton-loader
      v-else-if="state === 'loading'"
      type="list-item-two-line@2"
      data-test="message-search-loading"
    />

    <v-alert
      v-else-if="state === 'error'"
      type="error"
      variant="tonal"
      density="compact"
      data-test="message-search-error"
    >
      {{ t('app.messaging.search.error') }}
    </v-alert>

    <p
      v-else-if="state === 'empty'"
      class="agency-msg-search__note"
      data-test="message-search-none"
    >
      {{ t('app.messaging.search.noResults') }}
    </p>

    <template v-else>
      <ul class="agency-msg-search__list" data-test="message-search-list">
        <li v-for="item in items" :key="item.key">
          <router-link :to="item.to" class="agency-msg-hit" :data-test="`message-hit-${item.key}`">
            <span class="agency-msg-hit__top">
              <span class="agency-msg-hit__thread">{{
                t('app.messaging.search.inThreadWith', { name: item.threadTitle })
              }}</span>
              <span class="agency-msg-hit__time">{{ formatSent(item.sentAt) }}</span>
            </span>
            <span class="agency-msg-hit__snippet" data-test="message-hit-snippet"
              ><span v-if="item.senderLabel !== null" class="agency-msg-hit__sender"
                >{{ item.senderLabel }}: </span
              >{{ item.snippet }}</span
            >
          </router-link>
        </li>
      </ul>
      <v-btn
        v-if="hasMore"
        variant="text"
        size="small"
        :loading="loadingMore"
        class="agency-msg-search__more"
        data-test="message-search-more"
        @click="emit('loadMore')"
      >
        {{ t('app.messaging.inbox.loadMore') }}
      </v-btn>
    </template>
  </section>
</template>

<style scoped>
.agency-msg-search {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 8px 0 4px;
  border-top: 1px solid rgb(var(--v-theme-border-color));
}

.agency-msg-search__heading {
  margin: 0;
  padding: 4px 12px;
  font-size: var(--catalyst-typography-caption-size);
  line-height: var(--catalyst-typography-caption-line-height);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  color: rgb(var(--v-theme-text-tertiary));
}

.agency-msg-search__note {
  margin: 0;
  padding: 8px 12px;
  color: rgb(var(--v-theme-on-surface-variant));
}

.agency-msg-search__list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.agency-msg-search__more {
  align-self: center;
}

.agency-msg-hit {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 10px 12px;
  border-radius: var(--radius-xl, 10px);
  color: inherit;
  text-decoration: none;
}

.agency-msg-hit:hover,
.agency-msg-hit:focus-visible {
  background: rgba(var(--v-theme-on-surface), 0.06);
}

.agency-msg-hit__top {
  display: flex;
  align-items: baseline;
  gap: var(--space-3, 12px);
}

.agency-msg-hit__thread {
  flex: 1 1 auto;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 500;
  color: rgb(var(--v-theme-on-surface));
}

.agency-msg-hit__time {
  flex: none;
  font-size: var(--catalyst-typography-caption-size);
  color: rgb(var(--v-theme-text-tertiary));
}

.agency-msg-hit__snippet {
  display: -webkit-box;
  overflow: hidden;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  font-size: var(--catalyst-typography-body-sm-size);
  line-height: var(--catalyst-typography-body-sm-line-height);
  color: rgb(var(--v-theme-on-surface-variant));
  overflow-wrap: anywhere;
}

.agency-msg-hit__sender {
  font-weight: 600;
}
</style>
