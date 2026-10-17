<script setup lang="ts">
/**
 * CampaignStatusChip — a campaign's lifecycle state as a chip (AH-107).
 *
 * `surface="assignment"` shows every status (Q3: the assignment list cards and
 * the detail header show all of them, Active included). `surface="job"` shows
 * Paused only (Q3), because that is the one state a creator browsing the board
 * needs to be warned about.
 *
 * Renders nothing for an absent or unknown status (an older backend does not
 * send the field), never a raw i18n key.
 *
 * ⚠ The `campaign-status-chip` rule is `flex: 0 0 auto` and it is load-bearing.
 * Vuetify's `.v-chip` is `min-width: 0; overflow: hidden`, so as a flex item it
 * shrinks and clips its own label when the row is tight: the AH-106 "Applied
 * chip is cut off" defect. This chip is only ever placed in a row of its own,
 * but a later edit that moves it must not be able to re-create the clip
 * silently, so the rule lives on the component and a source-inspection spec
 * pins it.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { campaignStatusPresentation } from '../campaignStatus'

const props = defineProps<{
  status: string | null | undefined
  surface: 'assignment' | 'job'
}>()

const { t } = useI18n()

const presentation = computed(() => {
  const p = campaignStatusPresentation(props.status)
  if (p === null) return null
  if (props.surface === 'job' && !p.showOnJob) return null
  return p
})
</script>

<template>
  <v-chip
    v-if="presentation !== null"
    class="campaign-status-chip"
    size="x-small"
    variant="tonal"
    :color="presentation.color"
    :data-campaign-status="status"
  >
    {{ t(presentation.labelKey) }}
  </v-chip>
</template>

<style scoped>
.campaign-status-chip {
  flex: 0 0 auto;
  align-self: flex-start;
}
</style>
