<script setup lang="ts">
/**
 * NotificationCenter — the ONE shared notification-feed component (S11.0 Ch3a).
 *
 * USER-AGNOSTIC + SHELL-AGNOSTIC: the `/me/notifications` API is scoped to the
 * auth user (recipient_user_id), so this component takes no agency/role/shell
 * branch. The agency shell and the creator shell mount the exact same component
 * — the agency app-bar's bell renders it `variant="dropdown"`; both
 * `/notifications` (agency) and `/creator/notifications` (creator) route pages
 * render it `variant="page"`.
 *
 * Count reconciliation (D-5): when an optional `poll` handle is supplied (the
 * dropdown shares the bell's poll), mark-read/mark-all reconcile the badge
 * optimistically AND every feed fetch pushes `meta.unread_count` back through
 * `poll.set()` — so opening the dropdown is itself a reconcile point. On the
 * page variant no handle is passed; the app-bar badge's own 45 s poll is the
 * authoritative reconciler within one interval.
 *
 * D-7 deferred: row click marks read IN PLACE — no navigation. `subject` is on
 * the wire but intentionally unused this chunk.
 */

import type { NotificationResource } from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { notificationsApi } from '../api/notifications.api'
import type { NotificationPollHandle } from '../composables/useNotificationPoll'
import { notificationTemplateKey } from '../templates'

const props = withDefaults(
  defineProps<{
    variant: 'dropdown' | 'page'
    poll?: NotificationPollHandle | null
    /** Route NAME for the dropdown's "view all" link (dropdown variant only). */
    viewAllRoute?: string | null
  }>(),
  {
    poll: null,
    viewAllRoute: null,
  },
)

const { t } = useI18n()

const DROPDOWN_PER_PAGE = 8
const PAGE_PER_PAGE = 25
const perPage = computed(() => (props.variant === 'dropdown' ? DROPDOWN_PER_PAGE : PAGE_PER_PAGE))

const rows = ref<NotificationResource[]>([])
const loading = ref(false)
const errored = ref(false)
const page = ref(1)
const lastPage = ref(1)

const hasRows = computed(() => rows.value.length > 0)

function bodyText(row: NotificationResource): string {
  const key = notificationTemplateKey(row.attributes.notification_type)
  // Pass the row's `data` bag as named-interpolation params. Each template
  // references ONLY the keys its emit site sends; extra keys are ignored and
  // missing keys never appear because no template references a key its own
  // emit site doesn't provide.
  return t(key, { ...row.attributes.data })
}

/** Free-text detail line, rendered only when its key is present. */
function detailText(row: NotificationResource): { label: string; text: string } | null {
  const data = row.attributes.data
  if (typeof data.feedback === 'string' && data.feedback !== '') {
    return { label: t('notifications.center.feedbackLabel'), text: data.feedback }
  }
  if (typeof data.rejection_reason === 'string' && data.rejection_reason !== '') {
    return { label: t('notifications.center.reasonLabel'), text: data.rejection_reason }
  }
  return null
}

function isUnread(row: NotificationResource): boolean {
  return row.attributes.read_at === null
}

async function load(targetPage = 1): Promise<void> {
  loading.value = true
  errored.value = false
  try {
    const envelope = await notificationsApi.list({ page: targetPage, perPage: perPage.value })
    rows.value = envelope.data
    page.value = envelope.meta.page
    lastPage.value = envelope.meta.last_page
    // Feed fetch doubles as a count reconcile point (D-5).
    props.poll?.set(envelope.meta.unread_count)
  } catch {
    errored.value = true
  } finally {
    loading.value = false
  }
}

/** Re-fetch the most-recent slice. Exposed so the bell can refetch on open. */
async function reload(): Promise<void> {
  await load(1)
}

async function onRowClick(row: NotificationResource): Promise<void> {
  if (!isUnread(row)) {
    return
  }
  // Optimistic local + badge reconcile; server is idempotent so no guard needed.
  const previous = row.attributes.read_at
  row.attributes.read_at = new Date().toISOString()
  props.poll?.applyMarkRead()
  try {
    await notificationsApi.markRead(row.id)
  } catch {
    // Roll back the optimistic local flip; the steady poll reconciles the badge.
    row.attributes.read_at = previous
  }
}

async function onMarkAll(): Promise<void> {
  const now = new Date().toISOString()
  for (const row of rows.value) {
    if (row.attributes.read_at === null) {
      row.attributes.read_at = now
    }
  }
  props.poll?.applyReadAll()
  try {
    await notificationsApi.readAll()
  } catch {
    // Idempotent server-side; the steady poll reconciles any drift.
  }
}

function onPageChange(next: number): void {
  void load(next)
}

onMounted(() => {
  void load(1)
})

defineExpose({ reload, load })
</script>

<template>
  <div
    :data-test="`notification-center-${variant}`"
    :class="variant === 'dropdown' ? 'notification-center--dropdown' : 'notification-center--page'"
  >
    <!-- Header: title + mark-all -->
    <div class="d-flex align-center justify-space-between px-4 py-2">
      <span class="text-subtitle-2 font-weight-medium">
        {{
          variant === 'dropdown' ? t('notifications.center.title') : t('notifications.page.title')
        }}
      </span>
      <v-btn
        v-if="hasRows"
        variant="text"
        size="small"
        class="text-none"
        data-test="notification-mark-all"
        @click="onMarkAll"
      >
        {{ t('notifications.center.markAll') }}
      </v-btn>
    </div>

    <v-divider />

    <!-- Loading -->
    <div
      v-if="loading && !hasRows"
      class="px-4 py-6 text-center text-medium-emphasis"
      data-test="notification-loading"
    >
      {{ t('notifications.center.loading') }}
    </div>

    <!-- Error -->
    <div
      v-else-if="errored"
      class="px-4 py-6 text-center text-error"
      data-test="notification-error"
    >
      {{ t('notifications.center.error') }}
    </div>

    <!-- Empty -->
    <div
      v-else-if="!hasRows"
      class="px-4 py-6 text-center text-medium-emphasis"
      data-test="notification-empty"
    >
      {{ variant === 'dropdown' ? t('notifications.center.empty') : t('notifications.page.empty') }}
    </div>

    <!-- Feed -->
    <v-list v-else density="comfortable" data-test="notification-list">
      <v-list-item
        v-for="row in rows"
        :key="row.id"
        :class="{ 'notification-row--unread': isUnread(row) }"
        :data-test="`notification-row${isUnread(row) ? ' notification-row--unread' : ''}`"
        @click="onRowClick(row)"
      >
        <div class="d-flex align-start ga-2">
          <v-icon
            v-if="isUnread(row)"
            icon="mdi-circle"
            size="8"
            color="primary"
            class="mt-2"
            data-test="notification-unread-dot"
          />
          <div class="flex-grow-1">
            <div class="text-body-2" data-test="notification-body">{{ bodyText(row) }}</div>
            <div
              v-if="detailText(row)"
              class="text-caption text-medium-emphasis mt-1"
              data-test="notification-detail"
            >
              {{ detailText(row)?.label }}: {{ detailText(row)?.text }}
            </div>
          </div>
        </div>
      </v-list-item>
    </v-list>

    <!-- Dropdown footer: view-all link -->
    <template v-if="variant === 'dropdown' && viewAllRoute">
      <v-divider />
      <v-list density="compact">
        <v-list-item
          :to="{ name: viewAllRoute }"
          prepend-icon="mdi-bell-outline"
          :title="t('notifications.center.viewAll')"
          data-test="notification-view-all"
        />
      </v-list>
    </template>

    <!-- Page footer: pagination -->
    <div v-if="variant === 'page' && lastPage > 1" class="d-flex justify-center py-4">
      <v-pagination
        :model-value="page"
        :length="lastPage"
        :total-visible="7"
        density="comfortable"
        data-test="notification-pagination"
        @update:model-value="onPageChange"
      />
    </div>
  </div>
</template>

<style scoped>
.notification-center--dropdown {
  min-width: 360px;
  max-width: 420px;
}

.notification-row--unread {
  background-color: rgba(var(--v-theme-primary), 0.06);
}
</style>
