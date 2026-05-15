<script setup lang="ts">
/**
 * Avatar upload drop zone (Sprint 3 Chunk 3 sub-step 3).
 *
 * A presentational component that wraps {@link useAvatarUpload} and
 * renders the visual affordances. Consumers (Step2ProfileBasicsPage)
 * mount this with no props — all state flows through the composable.
 *
 * a11y (F2=b):
 *   - The drop zone is a `<label>` that wraps a visually-hidden
 *     `<input type="file">`, so keyboard + screen-reader users can
 *     `Tab` to it and `Enter`/`Space` to open the file picker.
 *   - Visible label + aria-describedby on the input for SR-only
 *     hints + size/format constraints.
 *   - Drag-and-drop state changes are pointer-only and progressively
 *     enhance the keyboard-accessible base experience — they don't
 *     hide functionality.
 *   - Error + status announcements via `role="status"` and
 *     `aria-live="polite"`.
 */

import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import {
  AVATAR_ALLOWED_DESCRIPTORS,
  AVATAR_ALLOWED_MIME_TYPES,
  AVATAR_MAX_MB,
  useAvatarUpload,
} from '../composables/useAvatarUpload'
import { useOnboardingStore } from '../stores/useOnboardingStore'

const { t } = useI18n()
const store = useOnboardingStore()
const { previewUrl, error, isUploading, upload, remove } = useAvatarUpload()

const isDragOver = ref(false)
const inputRef = ref<HTMLInputElement | null>(null)

const acceptAttr = AVATAR_ALLOWED_MIME_TYPES.join(',')
const sizeHint = computed(() =>
  t('creator.ui.upload.size_hint', { max_mb: AVATAR_MAX_MB, types: AVATAR_ALLOWED_DESCRIPTORS }),
)

const persistedAvatarPath = computed(() => store.creator?.attributes.avatar_path ?? null)
const hasPersisted = computed(() => persistedAvatarPath.value !== null)
const displayUrl = computed(() => previewUrl.value ?? persistedAvatarPath.value)

const errorMessage = computed(() => {
  if (error.value === null) return null
  return t(error.value.key, error.value.values)
})

async function handleFiles(fileList: FileList | null): Promise<void> {
  if (fileList === null || fileList.length === 0) return
  const file = fileList[0]
  if (file === undefined) return
  await upload(file)
}

function onClickRemove(): void {
  void remove()
}

function onChange(event: Event): void {
  const input = event.target as HTMLInputElement
  void handleFiles(input.files)
  input.value = ''
}

function onDrop(event: DragEvent): void {
  isDragOver.value = false
  void handleFiles(event.dataTransfer?.files ?? null)
}

function onDragOver(): void {
  isDragOver.value = true
}

function onDragLeave(): void {
  isDragOver.value = false
}
</script>

<template>
  <div class="avatar-upload-drop">
    <div
      v-if="displayUrl !== null"
      class="avatar-upload-drop__preview"
      data-testid="avatar-preview"
    >
      <img
        :src="displayUrl"
        :alt="t('creator.ui.upload.avatar_preview_alt')"
        class="avatar-upload-drop__image"
      />
    </div>

    <label
      class="avatar-upload-drop__zone"
      :class="{
        'avatar-upload-drop__zone--drag-over': isDragOver,
        'avatar-upload-drop__zone--uploading': isUploading,
      }"
      data-testid="avatar-drop-zone"
      @drop.prevent="onDrop"
      @dragover.prevent="onDragOver"
      @dragleave.prevent="onDragLeave"
    >
      <input
        ref="inputRef"
        type="file"
        :accept="acceptAttr"
        :disabled="isUploading"
        :aria-describedby="errorMessage !== null ? 'avatar-upload-error' : 'avatar-upload-hint'"
        class="avatar-upload-drop__input"
        data-testid="avatar-file-input"
        @change="onChange"
      />
      <v-icon icon="mdi-cloud-upload-outline" size="32" />
      <span class="avatar-upload-drop__primary">
        {{ isUploading ? t('creator.ui.upload.uploading') : t('creator.ui.upload.avatar_prompt') }}
      </span>
      <span id="avatar-upload-hint" class="avatar-upload-drop__hint">{{ sizeHint }}</span>
    </label>

    <v-btn
      v-if="hasPersisted && !isUploading"
      variant="text"
      size="small"
      color="error"
      data-testid="avatar-remove-button"
      @click="onClickRemove"
    >
      {{ t('creator.ui.upload.remove_avatar') }}
    </v-btn>

    <div
      v-if="errorMessage !== null"
      id="avatar-upload-error"
      role="alert"
      class="avatar-upload-drop__error"
      data-testid="avatar-upload-error"
    >
      {{ errorMessage }}
    </div>
  </div>
</template>

<style scoped>
.avatar-upload-drop {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}

.avatar-upload-drop__preview {
  width: 128px;
  height: 128px;
  border-radius: 50%;
  overflow: hidden;
  background-color: rgb(var(--v-theme-surface-variant));
}

.avatar-upload-drop__image {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.avatar-upload-drop__zone {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 16px 24px;
  border: 2px dashed rgb(var(--v-theme-outline));
  border-radius: 8px;
  cursor: pointer;
  text-align: center;
  background-color: rgb(var(--v-theme-surface));
  transition: border-color 120ms ease;
}

.avatar-upload-drop__zone:focus-within {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.avatar-upload-drop__zone--drag-over {
  border-color: rgb(var(--v-theme-primary));
  background-color: rgb(var(--v-theme-primary-container, var(--v-theme-surface-variant)));
}

.avatar-upload-drop__zone--uploading {
  cursor: progress;
  opacity: 0.7;
}

.avatar-upload-drop__input {
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

.avatar-upload-drop__primary {
  font-weight: 600;
  font-size: 0.875rem;
}

.avatar-upload-drop__hint {
  font-size: 0.75rem;
  color: rgb(var(--v-theme-on-surface-variant));
}

.avatar-upload-drop__error {
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
  text-align: center;
}
</style>
