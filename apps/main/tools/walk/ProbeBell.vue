<script setup lang="ts">
/**
 * Harness only: the agency bell with a shared poll that already holds `count`, so the badge can
 * be pinned and photographed without an API. Provides the poll to its own subtree only.
 */

import { provide } from 'vue'

import AgencyNotificationBell from '@/modules/agency/components/AgencyNotificationBell.vue'
import { NOTIFICATION_POLL_KEY } from '@/modules/notifications/composables/notificationPollContext'
import { useNotificationPoll } from '@/modules/notifications/composables/useNotificationPoll'

const props = defineProps<{ count: number }>()

const poll = useNotificationPoll()
poll.set(props.count)
provide(NOTIFICATION_POLL_KEY, poll)
</script>

<template>
  <AgencyNotificationBell view-all-route="notifications" />
</template>
