<script setup lang="ts">
/**
 * PL-3.2b — one reference image of a hook or a line (frame 13:3787): a 104 x 68 thumbnail with a 6 px radius and an outline-token
 * border, a round remove button at its top right and the file name (11 px, tertiary) under it. The thumbnail is read through the
 * app's own authentication (`useAuthenticatedImage`, shared with the concept images) and shown from a blob URL; until it is ready,
 * or when it cannot be read, a placeholder icon stands in. The image itself is a button: Enter and Space enlarge it.
 */
import type { ScriptImage } from '@catalyst/api-client'
import { useI18n } from 'vue-i18n'

import { useAuthenticatedImage } from '../composables/useAuthenticatedImage'

const props = defineProps<{ image: ScriptImage; busy: boolean }>()
const emit = defineEmits<{ open: [image: ScriptImage]; remove: [image: ScriptImage] }>()

const { t } = useI18n()
const { url, failed } = useAuthenticatedImage(() =>
  props.image.has_thumbnail ? props.image.thumbnail_url : null,
)
</script>

<template>
  <div class="stile" data-test="script-image-tile" :data-image="image.id">
    <button
      type="button"
      class="stile__open"
      :aria-label="t('app.strategy.scripts.images.open', { name: image.original_filename })"
      :data-image-open="image.id"
      data-test="script-image-open"
      @click="emit('open', image)"
    >
      <img v-if="url" :src="url" :alt="image.original_filename" class="stile__img" />
      <v-icon
        v-else
        :icon="failed ? 'mdi-image-broken-variant' : 'mdi-image-outline'"
        size="20"
        class="stile__placeholder"
        data-test="script-image-placeholder"
      />
    </button>
    <button
      type="button"
      class="stile__remove"
      :disabled="busy"
      :aria-label="t('app.strategy.scripts.images.remove', { name: image.original_filename })"
      data-test="script-image-remove"
      @click="emit('remove', image)"
    >
      <v-icon icon="mdi-close" size="10" aria-hidden="true" />
    </button>
    <span class="stile__name" :title="image.original_filename">{{ image.original_filename }}</span>
  </div>
</template>

<style scoped>
.stile {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 4px;
  width: 104px;
  flex: none;
}
.stile__open {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 104px;
  height: 68px;
  padding: 0;
  overflow: hidden;
  border: 1px solid rgb(var(--v-theme-outline));
  border-radius: 6px;
  background: rgb(var(--v-theme-surface-bright));
  cursor: zoom-in;
}
.stile__open:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 1px;
}
.stile__img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.stile__placeholder {
  color: rgb(var(--v-theme-text-tertiary));
}
.stile__name {
  overflow: hidden;
  font-size: 11px;
  line-height: normal;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: rgb(var(--v-theme-text-tertiary));
}
.stile__remove {
  position: absolute;
  top: 4px;
  right: 4px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 3px;
  border: 0;
  border-radius: 9999px;
  background: color-mix(in srgb, rgb(var(--v-theme-background)) 80%, transparent);
  color: rgb(var(--v-theme-on-surface));
  cursor: pointer;
}
.stile__remove:disabled {
  cursor: default;
  opacity: 0.5;
}
.stile__remove:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
}
</style>
