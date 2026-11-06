<script setup lang="ts">
/**
 * AH-119 (walk) — an inline message image: the thumbnail, and the lightbox it opens (tap or Enter).
 * Shared by the three message surfaces (campaign chat, creator Direct thread, agency Direct thread)
 * so the image looks and behaves the same in each, on the web and in the Capacitor app.
 *
 * A load failure is reported up (`error`), where the surface swaps the image for its file card.
 */

import { ref } from 'vue'

import ImageLightbox from './ImageLightbox.vue'

defineProps<{
  src: string | undefined
  alt: string
  name?: string | null
}>()

const emit = defineEmits<{ error: [] }>()

const open = ref(false)
</script>

<template>
  <button
    type="button"
    class="message-image-link"
    data-test="message-image-open"
    @click="open = true"
  >
    <img
      loading="lazy"
      :src="src"
      :alt="alt"
      class="message-image"
      data-test="message-image"
      @error="emit('error')"
    />
  </button>
  <ImageLightbox v-if="src" v-model="open" :src="src" :alt="alt" :name="name" />
</template>

<style scoped>
.message-image-link {
  display: inline-block;
  padding: 0;
  border: 0;
  background: transparent;
  cursor: zoom-in;
}

.message-image-link:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
  border-radius: 8px;
}

.message-image {
  display: block;
  max-width: 100%;
  max-height: 320px;
  border-radius: 8px;
  object-fit: contain;
}
</style>
