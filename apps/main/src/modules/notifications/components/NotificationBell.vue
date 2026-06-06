<script setup lang="ts">
/**
 * NotificationBell — the app-bar bell + unread badge + recent-slice dropdown
 * (S11.0 Ch3a, D-2 / D-3 / D-4).
 *
 * The FIRST `<v-badge>` in the codebase: a bell `v-btn` wrapped in
 * `<v-badge :content="count" :model-value="count > 0">` so the badge HIDES at
 * zero via `model-value` (not a `v-if`). Mounted between `<v-spacer />` and the
 * user-menu in BOTH app-bars with no shell-specific branch — only the
 * `viewAllRoute` prop (the route NAME of this shell's full-page archive)
 * differs.
 *
 * Owns the steady count poll (`useNotificationPoll`, flat 45 s, tab-visibility
 * gated, torn down on unmount). The dropdown shares that poll handle so
 * mark-read/mark-all reconcile the badge instantly, and each dropdown-open
 * refetch pushes `meta.unread_count` back as a reconcile point.
 */

import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { useNotificationPoll } from '../composables/useNotificationPoll'
import NotificationCenter from './NotificationCenter.vue'

defineProps<{
  /** Route NAME of this shell's full notifications page (agency vs creator). */
  viewAllRoute: string
}>()

const { t } = useI18n()

const poll = useNotificationPoll()
void poll.start()

const menuOpen = ref(false)
const centerRef = ref<InstanceType<typeof NotificationCenter> | null>(null)

// Refetch the recent slice each time the dropdown opens (a reconcile point
// alongside the steady poll).
watch(menuOpen, (open) => {
  if (open) {
    void centerRef.value?.reload()
  }
})
</script>

<template>
  <v-menu
    v-model="menuOpen"
    offset-y
    :close-on-content-click="false"
    location="bottom end"
    data-test="notification-menu-trigger-wrapper"
  >
    <template #activator="{ props: menuProps }">
      <v-badge
        :content="poll.unreadCount.value"
        :model-value="poll.unreadCount.value > 0"
        color="error"
        data-test="notification-badge"
      >
        <v-btn
          v-bind="menuProps"
          icon="mdi-bell-outline"
          variant="text"
          :aria-label="
            t('notifications.center.unreadBadgeLabel', { count: poll.unreadCount.value })
          "
          data-test="notification-bell-btn"
        />
      </v-badge>
    </template>

    <v-card data-test="notification-dropdown-card">
      <NotificationCenter
        ref="centerRef"
        variant="dropdown"
        :poll="poll"
        :view-all-route="viewAllRoute"
      />
    </v-card>
  </v-menu>
</template>
