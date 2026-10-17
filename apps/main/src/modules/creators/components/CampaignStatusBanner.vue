<script setup lang="ts">
/**
 * CampaignStatusBanner — the assignment-detail notice for a campaign that is
 * paused, completed or cancelled (AH-107, Q2).
 *
 * DISPLAY ONLY. It informs; it does not lock anything. The assignment page keeps
 * every action it would otherwise offer (the draft form, accept, decline), and
 * the page spec pins that. The known tension (a "cancelled" banner above a form
 * that still works) is the Q2 ruling, carried by the tech-debt row "campaign
 * status does not gate creator actions".
 *
 * Renders nothing for draft / active / absent / unknown.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { campaignStatusPresentation } from '../campaignStatus'

const props = defineProps<{
  status: string | null | undefined
}>()

const { t } = useI18n()

const bannerKey = computed(() => campaignStatusPresentation(props.status)?.bannerKey ?? null)
</script>

<template>
  <v-alert
    v-if="bannerKey !== null"
    type="info"
    variant="tonal"
    density="comfortable"
    :data-campaign-status="status"
    data-testid="creator-campaign-status-banner"
  >
    {{ t(bannerKey) }}
  </v-alert>
</template>
