<script setup lang="ts">
/**
 * AH-114 (S2.5) — the agency fork of `NotificationBell` (Figma top-bar bell, frame 18:34).
 * `NotificationBell.vue` is shared with the creator shell and is not edited (plan ruling 9a).
 * Same behaviour: the shared poll when the shell provides one (else its own), the badge hides at
 * zero, the recent-slice dropdown (`NotificationCenter`, unchanged) reloads on open and closes on
 * navigation, `formatBadgeCount` caps the count. Only the look changes: a 20px bell with the red
 * count pill right against it (Figma places it beside the glyph, not over it; `v-badge inline`).
 * The aria label and every `data-test` are the original's, so the existing specs read the same;
 * the pill is also a click target for the menu. `AgencyLayout` is the only place that mounts it.
 */

import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import { formatBadgeCount } from '@/modules/notifications/formatBadgeCount'
import { injectNotificationPoll } from '@/modules/notifications/composables/notificationPollContext'
import { useNotificationPoll } from '@/modules/notifications/composables/useNotificationPoll'
import NotificationCenter from '@/modules/notifications/components/NotificationCenter.vue'

defineProps<{
  /** Route NAME of this shell's full notifications page (agency vs creator). */
  viewAllRoute: string
}>()

const { t } = useI18n()

// AH-108 — the creator shell provides ONE poll that the Messages tab shares; use it
// when it is there and do not start a second. With nothing provided (the agency
// layout) the bell owns its own poll, exactly as it always did.
const sharedPoll = injectNotificationPoll()
const poll = sharedPoll ?? useNotificationPoll()
if (sharedPoll === null) {
  void poll.start()
}

const menuOpen = ref(false)
const route = useRoute()
const centerRef = ref<InstanceType<typeof NotificationCenter> | null>(null)

// AH-101 (U1) — close on navigation. The menu is `:close-on-content-click="false"`
// (a tap on a notification row must not dismiss it before the row's own handler
// runs), so a route change made from INSIDE the card — "View all", or a row that
// deep-links — left the dropdown open on top of the page it had just navigated
// to. Watching the route closes it for every navigation, whatever caused it,
// instead of patching each item that can navigate. Not native-gated: the same
// defect existed on the web, it is simply easier to hit with a thumb.
watch(
  () => route.fullPath,
  () => {
    menuOpen.value = false
  },
)

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
        inline
        :model-value="poll.unreadCount.value > 0"
        color="error"
        class="agency-bell__badge"
        data-test="notification-badge"
      >
        <template #badge>
          <span class="agency-bell__count" @click.stop="menuOpen = !menuOpen">{{
            formatBadgeCount(poll.unreadCount.value)
          }}</span>
        </template>
        <v-btn
          v-bind="menuProps"
          icon="mdi-bell-outline"
          variant="text"
          class="agency-bell__btn"
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

<style scoped>
/* Figma top bar (36:182): a 20px bell with the count pill right against it, no overlap. The
   button is 28px wide (20px glyph, 4px each side) and the pill starts at the glyph's edge. */
.agency-bell__btn {
  width: 28px;
  height: 32px;
  color: rgb(var(--v-theme-on-surface-variant));
}

.agency-bell__btn :deep(.v-icon) {
  font-size: 20px;
  width: 20px;
  height: 20px;
}

.agency-bell__badge :deep(.v-badge__wrapper) {
  margin: 0;
}

/* The pill: 16px tall, 6px by 1px padding (on the inner span, so the whole pill is a click
   target), 10.5px bold with 14px line and 0.4px tracking, white on the danger fill. */
.agency-bell__badge :deep(.v-badge__badge) {
  min-width: 0;
  height: auto;
  padding: 0;
  border-radius: 999px;
  font-family: inherit;
  font-size: 10.5px;
  line-height: 14px;
  font-weight: 700;
  letter-spacing: 0.4px;
  text-transform: uppercase;
  margin-inline-start: -4px;
  /* `bg-error` forces the on-error colour (dark in the agency themes) with !important; the
     Figma pill is white. */
  color: rgb(var(--v-theme-text-inverse)) !important;
}

.agency-bell__count {
  display: block;
  padding: 1px 6px;
  cursor: pointer;
}
</style>
