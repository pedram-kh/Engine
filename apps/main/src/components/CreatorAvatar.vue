<script setup lang="ts">
/**
 * A creator's profile photo with a click-to-enlarge lightbox.
 *
 * Deliberately mirrors the {@link PortfolioGallery} lightbox that already sits
 * further down the same profile pages — a `v-dialog` holding the full asset
 * with a floating close button — so a profile has one preview behaviour, not
 * two. Labels arrive as props for the same reason they do there: the primitive
 * stays i18n-free, and the callers pass the keys they already own.
 *
 * Squared-off (rounded rect, not a circle) so a portrait crop keeps more of
 * the frame than a circular mask would allow.
 *
 * With no photo the display-name initial stands in and the avatar is INERT:
 * there is nothing to enlarge, so no click affordance is offered.
 */

import { computed, ref } from 'vue'

// Two roots (the avatar + its teleported dialog) means fallthrough has no
// single home — callers' `data-test` etc. are bound to the avatar by hand.
defineOptions({ inheritAttrs: false })

const props = withDefaults(
  defineProps<{
    src?: string | null
    /** Drives the alt text and the initial fallback. */
    name: string
    size?: number | string
    /** Accessible label for the enlarge affordance. */
    previewLabel?: string
    /** Accessible label for the lightbox close button. */
    closeLabel?: string
  }>(),
  {
    src: null,
    size: 72,
    previewLabel: 'Open preview',
    closeLabel: 'Close',
  },
)

const open = ref(false)

const initial = computed(() => (props.name || '?')[0]?.toUpperCase() ?? '?')
const canPreview = computed(() => typeof props.src === 'string' && props.src !== '')

function openPreview(): void {
  if (canPreview.value) {
    open.value = true
  }
}
</script>

<template>
  <v-avatar
    v-bind="$attrs"
    :size="size"
    rounded="lg"
    color="primary"
    :class="{ 'creator-avatar--clickable': canPreview }"
    :role="canPreview ? 'button' : undefined"
    :tabindex="canPreview ? 0 : undefined"
    :aria-label="canPreview ? previewLabel : undefined"
    @click="openPreview"
    @keydown.enter.prevent="openPreview"
    @keydown.space.prevent="openPreview"
  >
    <v-img v-if="src" :src="src" :alt="name" cover />
    <span v-else class="text-h6 font-weight-bold text-white">{{ initial }}</span>
  </v-avatar>

  <v-dialog v-model="open" max-width="640" class="creator-avatar__overlay">
    <div class="creator-avatar__preview" data-test="creator-avatar-preview">
      <button
        type="button"
        class="creator-avatar__close"
        :aria-label="closeLabel"
        data-test="creator-avatar-preview-close"
        @click="open = false"
      >
        <v-icon icon="mdi-close" size="24" aria-hidden="true" />
      </button>

      <img
        class="creator-avatar__preview-media"
        :src="src ?? undefined"
        :alt="name"
        data-test="creator-avatar-preview-image"
      />
    </div>
  </v-dialog>
</template>

<style scoped>
.creator-avatar--clickable {
  cursor: pointer;
}

.creator-avatar--clickable:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.creator-avatar__preview {
  position: relative;
  display: flex;
  justify-content: center;
  padding: 16px;
  background: rgb(var(--v-theme-surface));
  border-radius: 8px;
}

.creator-avatar__preview-media {
  max-width: 100%;
  max-height: 80vh;
  width: auto;
  height: auto;
  display: block;
  border-radius: 4px;
}

.creator-avatar__close {
  position: absolute;
  top: 8px;
  right: 8px;
  width: 36px;
  height: 36px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  border: 1px solid rgb(var(--v-theme-outline-variant));
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  cursor: pointer;
  z-index: 1;
}

.creator-avatar__close:hover,
.creator-avatar__close:focus-visible {
  background: rgb(var(--v-theme-surface-variant));
  outline: none;
}
</style>

<style>
/* Unscoped BY NECESSITY: the overlay teleports out of this component's subtree,
   so a scoped selector never reaches the scrim. Namespaced by the class bound
   to this dialog alone, so no other overlay is affected. */
.creator-avatar__overlay .v-overlay__scrim {
  backdrop-filter: blur(6px);
  opacity: 0.6;
}
</style>
