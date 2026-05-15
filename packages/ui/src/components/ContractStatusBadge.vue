<script setup lang="ts">
/**
 * ContractStatusBadge — render the creator's master-contract
 * signature status as a coloured chip with a localized label.
 *
 * Sprint 3 Chunk 3 sub-step 7 (Decision C1: display-shared,
 * form-main).
 *
 * Three states the contract step can land in:
 *   - signed: `has_signed_master_contract` is true (vendor flow
 *     completed end-to-end).
 *   - click_through_accepted: `click_through_accepted_at` is set
 *     but `has_signed_master_contract` is false (flag-OFF path,
 *     Chunk 2 Q-flag-off-2 = (a)). The completeness calculator
 *     treats either signal as a hit.
 *   - none: neither signal is present.
 *
 * a11y (F2=b): the chip carries an accessible name via
 * `:aria-label`.
 */

type ContractStatus = 'signed' | 'click_through_accepted' | 'none'

interface Props {
  status: ContractStatus
  label: string
}

const props = defineProps<Props>()

const STATUS_COLOR: Record<ContractStatus, string> = {
  signed: 'success',
  click_through_accepted: 'success',
  none: 'grey',
}

const STATUS_ICON: Record<ContractStatus, string> = {
  signed: 'mdi-file-sign',
  click_through_accepted: 'mdi-check-decagram',
  none: 'mdi-file-document-outline',
}
</script>

<template>
  <v-chip
    :color="STATUS_COLOR[props.status]"
    :prepend-icon="STATUS_ICON[props.status]"
    :aria-label="props.label"
    :data-testid="`contract-status-badge-${props.status}`"
    size="small"
    variant="tonal"
  >
    {{ props.label }}
  </v-chip>
</template>
