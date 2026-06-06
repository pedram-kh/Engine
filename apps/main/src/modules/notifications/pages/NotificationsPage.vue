<script setup lang="ts">
/**
 * NotificationsPage — the full paginated archive (S11.0 Ch3a, D-2).
 *
 * A thin, branch-free wrapper around the shared `<NotificationCenter
 * variant="page" />`. Mounted by BOTH shells via two route records that differ
 * only in path/layout/guards (`/notifications` agency, `/creator/notifications`
 * creator) — the page itself is shell-agnostic because the `/me/notifications`
 * API is user-scoped. No `poll` handle is passed here; the app-bar bell's own
 * 45 s poll is the authoritative badge reconciler (D-5).
 */

import NotificationCenter from '../components/NotificationCenter.vue'
</script>

<template>
  <v-card max-width="720" class="mx-auto" data-test="notifications-page">
    <NotificationCenter variant="page" />
  </v-card>
</template>
