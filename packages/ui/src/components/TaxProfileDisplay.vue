<script setup lang="ts">
/**
 * TaxProfileDisplay — render a creator's tax-profile-complete
 * status as a coloured chip with a localized label.
 *
 * Sprint 3 Chunk 3 sub-step 8 (Decision C1: display-shared,
 * form-main).
 *
 * Sensitive PII (legal_name, tax_id, address) is NEVER passed
 * into this component — the creator-self view renders only the
 * boolean completion flag, and admin drill-in (Sprint 4+) ships
 * a separate read-only view of the encrypted fields. The Phase-1
 * surface is intentionally minimal.
 *
 * a11y (F2=b): the chip carries an accessible name via
 * `:aria-label`.
 */

interface Props {
  isComplete: boolean
  label: string
}

const props = defineProps<Props>()
</script>

<template>
  <v-chip
    :color="props.isComplete ? 'success' : 'grey'"
    :prepend-icon="props.isComplete ? 'mdi-check-decagram' : 'mdi-help-circle-outline'"
    :aria-label="props.label"
    :data-testid="`tax-profile-display-${props.isComplete ? 'complete' : 'incomplete'}`"
    size="small"
    variant="tonal"
  >
    {{ props.label }}
  </v-chip>
</template>
