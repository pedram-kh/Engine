<script setup lang="ts">
/**
 * CreatorNotificationsPage — the creator's full notification feed (AH-115 L4.4, closes tech-debt row 6).
 *
 * The creator fork of `NotificationsPage`: a page header (the title and a link to the notification
 * settings) above the shared `<NotificationCenter variant="page" />`, whose rows take the native look
 * through the creator look (type icon disc, compact age, unread dot, "all caught up" footer). The agency
 * keeps `NotificationsPage` (the bare 720px card) unchanged; only `/creator/notifications` mounts this.
 * The title lives in the header here, so the card hides its own (`hide-title`). Inside the Capacitor app the page
 * is unchanged: the same bare card as `NotificationsPage`.
 */

import { useI18n } from 'vue-i18n'

import { isNativePlatform } from '@/core/platform/isNativePlatform'

import NotificationCenter from '../components/NotificationCenter.vue'

const { t } = useI18n()

// The Capacitor app keeps the page it has always rendered (the bare card, exactly `NotificationsPage`);
// the fork is the web's.
const inApp = isNativePlatform()
</script>

<template>
  <v-card v-if="inApp" max-width="720" class="mx-auto" data-test="notifications-page">
    <NotificationCenter variant="page" />
  </v-card>

  <section v-else class="creator-notifications" data-test="creator-notifications-page">
    <header class="creator-notifications__header">
      <h1 class="creator-notifications__title" data-test="creator-notifications-title">
        {{ t('notifications.page.title') }}
      </h1>
      <v-btn
        :to="{ name: 'creator.notifications.preferences' }"
        variant="text"
        size="small"
        class="text-none"
        prepend-icon="mdi-cog-outline"
        data-test="creator-notifications-settings"
      >
        {{ t('notifications.preferences.menuItem') }}
      </v-btn>
    </header>

    <v-card class="creator-notifications__card" data-test="notifications-page">
      <NotificationCenter variant="page" hide-title />
    </v-card>
  </section>
</template>

<style scoped>
.creator-notifications {
  max-width: 760px;
  margin: 0 auto;
}

.creator-notifications__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
}

.creator-notifications__title {
  margin: 0;
  font: var(--native-type-h1);
}
</style>
