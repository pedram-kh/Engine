<script setup lang="ts">
/**
 * Portfolio upload grid (Sprint 3 Chunk 3 sub-step 3).
 *
 * Renders the upload queue managed by {@link usePortfolioUpload}.
 * Each tile shows the file name, MIME-derived kind, progress (when
 * uploading), and a remove button. Errors render inline per tile.
 *
 * NB — gallery of ALREADY-PERSISTED items is rendered by a separate
 * `PortfolioGallery` component (built in sub-step 6) reading from
 * the bootstrap state. This component owns ONLY the upload UI.
 *
 * a11y (F2=b):
 *   - `aria-live="polite"` region announces the queue length + the
 *     per-item status transitions.
 *   - File picker is a keyboard-accessible label/input pair.
 *   - Drag-and-drop is a pointer-only progressive enhancement.
 *   - Each item exposes per-tile aria labels with file name + status.
 */

import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import {
  PORTFOLIO_CONCURRENCY,
  PORTFOLIO_IMAGE_ALLOWED_MIME_TYPES,
  PORTFOLIO_IMAGE_MAX_MB,
  PORTFOLIO_VIDEO_ALLOWED_MIME_TYPES,
  PORTFOLIO_VIDEO_MAX_MB,
  usePortfolioUpload,
} from '../composables/usePortfolioUpload'

const { t } = useI18n()
const { items, enqueue, remove, inFlightCount, remainingSlots } = usePortfolioUpload()

const isDragOver = ref(false)
const acceptAttr = [
  ...PORTFOLIO_IMAGE_ALLOWED_MIME_TYPES,
  ...PORTFOLIO_VIDEO_ALLOWED_MIME_TYPES,
].join(',')

const sizeHint = computed(() =>
  t('creator.ui.upload.portfolio_size_hint', {
    image_mb: PORTFOLIO_IMAGE_MAX_MB,
    video_mb: PORTFOLIO_VIDEO_MAX_MB,
  }),
)

const slotsLabel = computed(() =>
  t('creator.ui.upload.portfolio_slots_remaining', {
    remaining: Math.max(0, remainingSlots.value),
  }),
)

const queueLiveText = computed(() => {
  if (items.value.length === 0) return ''
  return t('creator.ui.upload.portfolio_queue_status', {
    in_flight: inFlightCount.value,
    total: items.value.length,
    concurrency: PORTFOLIO_CONCURRENCY,
  })
})

function handleFiles(fileList: FileList | null): void {
  if (fileList === null) return
  for (const file of Array.from(fileList)) {
    enqueue(file)
  }
}

function onChange(event: Event): void {
  const input = event.target as HTMLInputElement
  handleFiles(input.files)
  input.value = ''
}

function onDrop(event: DragEvent): void {
  isDragOver.value = false
  handleFiles(event.dataTransfer?.files ?? null)
}

function onDragOver(): void {
  isDragOver.value = true
}

function onDragLeave(): void {
  isDragOver.value = false
}

async function onRemove(id: string): Promise<void> {
  await remove(id)
}

function itemErrorMessage(errKey: string | null, values: Record<string, string | number>): string {
  if (errKey === null) return ''
  return t(errKey, values)
}

function statusLabel(status: string): string {
  switch (status) {
    case 'pending':
      return t('creator.ui.upload.status_pending')
    case 'uploading':
      return t('creator.ui.upload.status_uploading')
    case 'done':
      return t('creator.ui.upload.status_done')
    case 'error':
      return t('creator.ui.upload.status_error')
    default:
      return ''
  }
}
</script>

<template>
  <div class="portfolio-upload-grid">
    <label
      class="portfolio-upload-grid__dropzone"
      :class="{ 'portfolio-upload-grid__dropzone--drag-over': isDragOver }"
      data-testid="portfolio-drop-zone"
      @drop.prevent="onDrop"
      @dragover.prevent="onDragOver"
      @dragleave.prevent="onDragLeave"
    >
      <input
        type="file"
        :accept="acceptAttr"
        multiple
        class="portfolio-upload-grid__input"
        data-testid="portfolio-file-input"
        aria-describedby="portfolio-upload-hint"
        @change="onChange"
      />
      <v-icon icon="mdi-cloud-upload-outline" size="32" />
      <span class="portfolio-upload-grid__primary">
        {{ t('creator.ui.upload.portfolio_prompt') }}
      </span>
      <span id="portfolio-upload-hint" class="portfolio-upload-grid__hint">{{ sizeHint }}</span>
      <span class="portfolio-upload-grid__slots">{{ slotsLabel }}</span>
    </label>

    <div
      v-if="items.length > 0"
      class="portfolio-upload-grid__items"
      data-testid="portfolio-upload-list"
    >
      <div
        v-for="item in items"
        :key="item.id"
        class="portfolio-upload-grid__item"
        :data-status="item.status"
        :data-testid="`portfolio-upload-item-${item.status}`"
      >
        <div class="portfolio-upload-grid__item-meta">
          <v-icon
            :icon="item.kind === 'image' ? 'mdi-image-outline' : 'mdi-video-outline'"
            size="20"
          />
          <span class="portfolio-upload-grid__item-name">{{ item.file.name }}</span>
          <span class="portfolio-upload-grid__item-status">{{ statusLabel(item.status) }}</span>
        </div>
        <v-progress-linear
          v-if="item.status === 'uploading'"
          indeterminate
          color="primary"
          height="4"
        />
        <div
          v-if="item.status === 'error'"
          role="alert"
          class="portfolio-upload-grid__item-error"
          :data-testid="`portfolio-upload-error-${item.id}`"
        >
          {{ itemErrorMessage(item.errorKey, item.errorValues) }}
        </div>
        <v-btn
          variant="text"
          size="small"
          :aria-label="t('creator.ui.upload.remove_portfolio_item_label', { name: item.file.name })"
          :data-testid="`portfolio-upload-remove-${item.id}`"
          @click="onRemove(item.id)"
        >
          {{ t('creator.ui.upload.remove') }}
        </v-btn>
      </div>
    </div>

    <div
      class="portfolio-upload-grid__sr-status"
      role="status"
      aria-live="polite"
      aria-atomic="true"
    >
      {{ queueLiveText }}
    </div>
  </div>
</template>

<style scoped>
.portfolio-upload-grid {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.portfolio-upload-grid__dropzone {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 24px;
  border: 2px dashed rgb(var(--v-theme-outline));
  border-radius: 8px;
  cursor: pointer;
  text-align: center;
  background-color: rgb(var(--v-theme-surface));
  transition: border-color 120ms ease;
}

.portfolio-upload-grid__dropzone:focus-within {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.portfolio-upload-grid__dropzone--drag-over {
  border-color: rgb(var(--v-theme-primary));
  background-color: rgb(var(--v-theme-primary-container, var(--v-theme-surface-variant)));
}

.portfolio-upload-grid__input {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

.portfolio-upload-grid__primary {
  font-weight: 600;
  font-size: 0.875rem;
}

.portfolio-upload-grid__hint,
.portfolio-upload-grid__slots {
  font-size: 0.75rem;
  color: rgb(var(--v-theme-on-surface-variant));
}

.portfolio-upload-grid__items {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.portfolio-upload-grid__item {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 12px;
  border: 1px solid rgb(var(--v-theme-outline-variant, var(--v-theme-outline)));
  border-radius: 6px;
}

.portfolio-upload-grid__item-meta {
  display: flex;
  align-items: center;
  gap: 8px;
}

.portfolio-upload-grid__item-name {
  flex: 1;
  font-size: 0.875rem;
  word-break: break-all;
}

.portfolio-upload-grid__item-status {
  font-size: 0.75rem;
  color: rgb(var(--v-theme-on-surface-variant));
}

.portfolio-upload-grid__item-error {
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}

.portfolio-upload-grid__sr-status {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
