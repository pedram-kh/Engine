<script setup lang="ts">
/**
 * PayoutMethodStatus — render whether the creator has a payout
 * method connected as a coloured chip with a localized label.
 *
 * Sprint 3 Chunk 3 sub-step 7 (Decision C1: display-shared,
 * form-main).
 *
 * The Phase-1 model is boolean: `payout_method_set` is either
 * `true` (Stripe Connect onboarding completed at vendor side and
 * the webhook landed) or `false` (not yet set up). The
 * "in-flight" state (Stripe redirected the creator back, but the
 * webhook hasn't arrived yet) is held inside `useVendorBounce`
 * locally — once `bootstrap()` reflects `payout_method_set=true`
 * the chip flips to "connected".
 *
 * Status → Vuetify colour mapping:
 *   - set        → success
 *   - unset      → grey (neutral, calls-to-action live elsewhere)
 *
 * a11y (F2=b): the chip carries an accessible name via
 * `:aria-label`.
 */

interface Props {
  isSet: boolean
  label: string
}

const props = defineProps<Props>()
</script>

<template>
  <v-chip
    :color="props.isSet ? 'success' : 'grey'"
    :prepend-icon="props.isSet ? 'mdi-check-decagram' : 'mdi-help-circle-outline'"
    :aria-label="props.label"
    :data-testid="`payout-method-status-${props.isSet ? 'set' : 'unset'}`"
    size="small"
    variant="tonal"
  >
    {{ props.label }}
  </v-chip>
</template>
