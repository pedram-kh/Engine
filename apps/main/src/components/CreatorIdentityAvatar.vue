<script setup lang="ts">
/**
 * CreatorIdentityAvatar — the small list/row avatar for a creator on an agency screen
 * (AH-104b-del B3). Not the profile photo with the lightbox (`CreatorAvatar`): this is the
 * 28–48px circle in a roster row, an application, a thread.
 *
 * Draws the signed image when there is one, else the initial; for an erased creator it never
 * draws an image and shows `?`, through the shared guard in `core/creators/creatorLabel`.
 * Pass any creator-shaped payload (`display_name`, `avatar_url`, `is_erased`, all optional).
 */

import { computed } from 'vue'

import {
  creatorAvatarAlt,
  creatorAvatarUrl,
  creatorInitial,
  type CreatorLike,
} from '@/core/creators/creatorLabel'

const props = withDefaults(
  defineProps<{
    creator: CreatorLike | null | undefined
    size?: number | string
    color?: string
  }>(),
  { size: 40, color: 'surface-variant' },
)

const url = computed(() => creatorAvatarUrl(props.creator))
const alt = computed(() => creatorAvatarAlt(props.creator))
const initial = computed(() => creatorInitial(props.creator))
</script>

<template>
  <v-avatar :size="size" :color="color" data-test="creator-identity-avatar">
    <v-img v-if="url" :src="url" :alt="alt" cover />
    <span v-else class="text-caption">{{ initial }}</span>
  </v-avatar>
</template>
