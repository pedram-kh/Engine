<script setup lang="ts">
/**
 * NavBadgeIcon — a nav item's icon, optionally carrying an unread badge (AH-108).
 *
 * `count: null` is "this item has no badge": a bare icon, so every other nav item
 * renders exactly the DOM it did before. A number draws a `v-badge` over the icon
 * that HIDES at zero via `model-value` (not a `v-if`, the bell's pattern) and caps
 * at `99+` through the one shared `formatBadgeCount`.
 *
 * The accessible name is the BUTTON's, not this icon's: the layout puts the real
 * count (uncapped) in the Messages button's `aria-label`, because the glyph is a
 * capped, decorative summary of it.
 */
import { formatBadgeCount } from '@/modules/notifications/formatBadgeCount'

defineProps<{
  icon: string
  count: number | null
}>()
</script>

<template>
  <v-badge
    v-if="count !== null"
    :content="formatBadgeCount(count)"
    :model-value="count > 0"
    color="error"
    class="nav-badge-icon"
  >
    <v-icon :icon="icon" />
  </v-badge>
  <v-icon v-else :icon="icon" />
</template>
