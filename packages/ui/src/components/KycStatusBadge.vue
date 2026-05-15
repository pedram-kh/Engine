<script setup lang="ts">
/**
 * KycStatusBadge — render the creator's KYC status as a coloured
 * chip with a localized label.
 *
 * Sprint 3 Chunk 3 sub-step 7 (Decision C1: display-shared,
 * form-main). The consumer (creator wizard Step 5, admin
 * creator-detail page) passes the pre-localized label so this
 * package stays i18n-free.
 *
 * Status → Vuetify colour mapping:
 *   - none        → grey (neutral)
 *   - pending     → warning (in-flight)
 *   - verified    → success (terminal-success)
 *   - rejected    → error (terminal-failure)
 *   - not_required → info (forensic flag-OFF marker, Chunk 2
 *                    Q-flag-off-1)
 *
 * a11y (F2=b): the chip carries an accessible name via
 * `:aria-label`. Colour alone never conveys status — the icon +
 * label combination is the canonical signal.
 */

type KycStatus = 'none' | 'pending' | 'verified' | 'rejected' | 'not_required'

interface Props {
  status: KycStatus
  label: string
}

const props = defineProps<Props>()

const STATUS_COLOR: Record<KycStatus, string> = {
  none: 'grey',
  pending: 'warning',
  verified: 'success',
  rejected: 'error',
  not_required: 'info',
}

const STATUS_ICON: Record<KycStatus, string> = {
  none: 'mdi-help-circle-outline',
  pending: 'mdi-progress-clock',
  verified: 'mdi-check-decagram',
  rejected: 'mdi-alert-circle',
  not_required: 'mdi-flag-variant-outline',
}
</script>

<template>
  <v-chip
    :color="STATUS_COLOR[props.status]"
    :prepend-icon="STATUS_ICON[props.status]"
    :aria-label="props.label"
    :data-testid="`kyc-status-badge-${props.status}`"
    size="small"
    variant="tonal"
  >
    {{ props.label }}
  </v-chip>
</template>
