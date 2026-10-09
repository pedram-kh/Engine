<script setup lang="ts">
/**
 * SocialBrandIcon — the platform's own glyph for the creator app's social cards
 * (Figma frames 04/05/13: icon/ig, icon/tt, icon/yt on a tinted tile).
 *
 * Instagram and YouTube come from the Material Design Icons set the app already
 * ships. MDI has no TikTok glyph (checked against the installed 7.4.x font), so
 * that one is the platform's single-path mark drawn inline — local, no network
 * request, no new dependency. The colour is `currentColor`: the tile decides it.
 */

import type { CreatorSocialPlatform } from '@catalyst/api-client'

defineProps<{ platform: CreatorSocialPlatform }>()

/** The TikTok mark (24×24 viewBox, single path). */
const TIKTOK_PATH =
  'M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z'
</script>

<template>
  <v-icon
    v-if="platform === 'instagram'"
    icon="mdi-instagram"
    size="18"
    aria-hidden="true"
    class="social-brand-icon"
  />
  <v-icon
    v-else-if="platform === 'youtube'"
    icon="mdi-youtube"
    size="18"
    aria-hidden="true"
    class="social-brand-icon"
  />
  <svg
    v-else
    class="social-brand-icon social-brand-icon--svg"
    width="18"
    height="18"
    viewBox="0 0 24 24"
    aria-hidden="true"
    focusable="false"
  >
    <path :d="TIKTOK_PATH" fill="currentColor" />
  </svg>
</template>
