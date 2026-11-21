<script setup lang="ts">
/**
 * PL-2.3 — one reference image: the thumbnail, fetched through the app's own authentication and shown from a blob URL
 * that is revoked when the tile goes (see `useAuthenticatedImage`). While the thumbnail is not ready, or when it cannot
 * be read, a placeholder icon stands in. Click opens the enlarged view; the corner button removes the image.
 */
import type { ConceptImage } from '@catalyst/api-client'
import { useI18n } from 'vue-i18n'

import { useAuthenticatedImage } from '../composables/useAuthenticatedImage'

const props = defineProps<{ image: ConceptImage; busy: boolean }>()
const emit = defineEmits<{ open: [image: ConceptImage]; remove: [image: ConceptImage] }>()

const { t } = useI18n()
const { url, failed } = useAuthenticatedImage(() =>
  props.image.has_thumbnail ? props.image.thumbnail_url : null,
)
</script>

<template>
  <div class="image-tile" data-test="concept-image-tile">
    <button
      type="button"
      class="image-tile__open"
      :aria-label="t('app.strategy.concepts.images.open', { name: image.original_filename })"
      data-test="concept-image-open"
      @click="emit('open', image)"
    >
      <img v-if="url" :src="url" :alt="image.original_filename" class="image-tile__img" />
      <v-icon
        v-else
        :icon="failed ? 'mdi-image-broken-variant' : 'mdi-image-outline'"
        size="22"
        class="image-tile__placeholder"
        data-test="concept-image-placeholder"
      />
    </button>
    <button
      type="button"
      class="image-tile__remove"
      :disabled="busy"
      :aria-label="t('app.strategy.concepts.images.remove', { name: image.original_filename })"
      data-test="concept-image-remove"
      @click="emit('remove', image)"
    >
      <v-icon icon="mdi-close" size="12" />
    </button>
  </div>
</template>

<style scoped>
.image-tile {
  position: relative;
  width: 64px;
  height: 64px;
  flex: none;
}
.image-tile__open {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  padding: 0;
  overflow: hidden;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 8px;
  background: rgb(var(--v-theme-surface-bright));
  cursor: zoom-in;
}
.image-tile__img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.image-tile__placeholder {
  color: rgb(var(--v-theme-text-tertiary));
}
.image-tile__remove {
  position: absolute;
  top: -6px;
  right: -6px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  padding: 0;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 9999px;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  cursor: pointer;
}
.image-tile__remove:disabled {
  cursor: default;
  opacity: 0.5;
}
</style>
