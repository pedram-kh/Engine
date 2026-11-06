<script setup lang="ts">
/**
 * AH-119 (walk) — the full-screen view of an inline message image, for both shells.
 *
 * A fullscreen overlay on a dark backdrop: the image is contained, a download and a close button sit
 * top-right, and Esc or a click on the backdrop (anywhere that is not the image or a button)
 * closes it. Vuetify's overlay traps focus while open and gives it back to the thumbnail on close.
 * Nothing opens in a new tab.
 *
 * Download: the web saves the file (`downloadImageOnWeb`); the Capacitor app hands it to the system
 * share sheet (`shareImageOnNative`). The branch is the platform probe, never the viewport.
 */

import { useI18n } from 'vue-i18n'

import { isNativePlatform } from '@/core/platform/isNativePlatform'
import { downloadImageOnWeb, shareImageOnNative } from '@/core/platform/share/shareImage'

const props = defineProps<{
  modelValue: boolean
  src: string
  alt: string
  /** The attachment's file name, for the saved file and the share sheet title. */
  name?: string | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

const { t } = useI18n()
const native = isNativePlatform()

function close(): void {
  emit('update:modelValue', false)
}

async function onDownload(): Promise<void> {
  if (native) {
    await shareImageOnNative(props.src, props.name ?? null)
  } else {
    await downloadImageOnWeb(props.src, props.name ?? null)
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    fullscreen
    scrim="black"
    opacity="0.92"
    transition="fade-transition"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <div class="message-lightbox" data-test="message-lightbox" @click.self="close">
      <div class="message-lightbox__bar">
        <v-btn
          icon="mdi-download"
          variant="text"
          color="white"
          :aria-label="t('app.messaging.lightbox.download')"
          data-test="message-lightbox-download"
          @click="onDownload"
        />
        <v-btn
          icon="mdi-close"
          variant="text"
          color="white"
          autofocus
          :aria-label="t('app.messaging.lightbox.close')"
          data-test="message-lightbox-close"
          @click="close"
        />
      </div>
      <img
        :src="src"
        :alt="alt"
        class="message-lightbox__image"
        data-test="message-lightbox-image"
      />
    </div>
  </v-dialog>
</template>

<style scoped>
.message-lightbox {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  padding: calc(56px + env(safe-area-inset-top, 0px)) 16px
    calc(16px + env(safe-area-inset-bottom, 0px));
}

.message-lightbox__bar {
  position: absolute;
  top: calc(8px + env(safe-area-inset-top, 0px));
  right: 8px;
  display: flex;
  gap: 4px;
}

.message-lightbox__image {
  display: block;
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}
</style>
