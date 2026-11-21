<script setup lang="ts">
/** PL-2.3 — the enlarged image: the original through the same authenticated read; its blob URL goes with the dialog. */
import type { ConceptImage } from '@catalyst/api-client'
import { useI18n } from 'vue-i18n'

import { useAuthenticatedImage } from '../composables/useAuthenticatedImage'

const props = defineProps<{ image: ConceptImage }>()
const emit = defineEmits<{ close: [] }>()

const { t } = useI18n()
const { url, failed, loading } = useAuthenticatedImage(() => props.image.file_url)
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
