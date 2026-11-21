<script setup lang="ts">
/**
 * PL-2.3 — the enlarged image: the original through the same authenticated read; its blob URL goes with the dialog.
 * Download saves that same original under its original filename: the bytes are the ones already fetched through the
 * cookie-authenticated endpoint, so no presigned or public address is ever made. Every role may download (the viewer
 * itself is open to all three). The control is named "Download" in the viewer's title bar; the frame has no viewer.
 */
import type { ConceptImage } from '@catalyst/api-client'
import { useI18n } from 'vue-i18n'

import { useAuthenticatedImage } from '../composables/useAuthenticatedImage'

const props = defineProps<{ image: ConceptImage }>()
const emit = defineEmits<{ close: [] }>()

const { t } = useI18n()
const { url, failed, loading } = useAuthenticatedImage(() => props.image.file_url)

/** A name the file system accepts: the stored original name with path separators and control characters removed. */
function saveName(name: string): string {
  // eslint-disable-next-line no-control-regex
  const clean = name.replace(/[\\/:*?"<>|\u0000-\u001f]/g, '_').trim()
  return clean === '' ? 'image' : clean
}

function download(): void {
  if (!url.value) return
  const a = document.createElement('a')
  a.href = url.value
  a.download = saveName(props.image.original_filename)
  a.rel = 'noopener'
  document.body.appendChild(a)
  a.click()
  a.remove()
}
</script>

<template>
  <v-dialog
    :model-value="true"
    max-width="960"
    scrollable
    data-test="concept-image-viewer"
    @update:model-value="emit('close')"
  >
    <v-card>
      <v-card-title class="d-flex align-center">
        <span class="text-body-1 text-truncate">{{ image.original_filename }}</span>
        <v-spacer />
        <v-btn
          variant="text"
          size="small"
          prepend-icon="mdi-download"
          class="mr-1"
          :disabled="!url"
          data-test="concept-image-download"
          @click="download"
        >
          {{ t('app.strategy.concepts.images.download') }}
        </v-btn>
        <v-btn
          icon="mdi-close"
          variant="text"
          size="small"
          :aria-label="t('app.strategy.concepts.images.close')"
          data-test="concept-image-viewer-close"
          @click="emit('close')"
        />
      </v-card-title>
      <v-card-text class="viewer__body">
        <v-progress-circular v-if="loading" indeterminate />
        <img v-else-if="url" :src="url" :alt="image.original_filename" class="viewer__img" />
        <p v-else-if="failed" class="text-body-2" data-test="concept-image-viewer-failed">
          {{ t('app.strategy.concepts.images.loadFailed') }}
        </p>
      </v-card-text>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.viewer__body {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 160px;
}
.viewer__img {
  max-width: 100%;
  max-height: 75dvh;
  object-fit: contain;
}
</style>
