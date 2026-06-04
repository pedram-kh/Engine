<script setup lang="ts">
/**
 * BlacklistBadge — render an agency's blacklist of a creator as a coloured
 * chip with a localized label.
 *
 * Extracted (blacklist-in-pools chunk, D-1) from the inline `v-chip` that was
 * duplicated across the roster list + the 2a creator-detail, now reused by the
 * pool member list + the add-creators picker (the 3rd + 4th use — the point
 * where extraction is correct, not premature). Mirrors KycStatusBadge /
 * ContractStatusBadge: i18n-free, the pre-localized label is passed in so this
 * package stays string-free.
 *
 * Type → Vuetify colour (the hard/soft tonal map, behavior-preserving):
 *   - hard → error   (a hard exclusion)
 *   - soft → warning (a warn-only caution)
 *
 * `size` is a prop (default `small`) so consumers migrating off the inline
 * chips keep their exact prior sizing (the roster used `x-small`, the detail
 * `small`) — the migration must change nothing visible (D-2). Deliberately
 * icon-free: the inline chips carried no icon, so adding one would not be
 * behavior-preserving.
 *
 * a11y: the chip carries an accessible name via `:aria-label`.
 */

type BlacklistType = 'hard' | 'soft'

interface Props {
  type: BlacklistType
  label: string
  size?: string
}

const props = withDefaults(defineProps<Props>(), {
  size: 'small',
})

const TYPE_COLOR: Record<BlacklistType, string> = {
  hard: 'error',
  soft: 'warning',
}
</script>

<template>
  <v-chip
    :color="TYPE_COLOR[props.type]"
    :size="props.size"
    :aria-label="props.label"
    :data-testid="`blacklist-badge-${props.type}`"
    variant="tonal"
  >
    {{ props.label }}
  </v-chip>
</template>
