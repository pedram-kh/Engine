/**
 * Avatar-upload composable (Sprint 3 Chunk 3 sub-step 3).
 *
 * Direct-multipart upload to `POST /api/v1/creators/me/avatar`. The
 * backend processes the image via Intervention/Image — resizes,
 * strips EXIF, re-encodes to a small set of allowed formats — and
 * returns the refreshed `CreatorResource` with `avatar_path`
 * populated on a 2xx.
 *
 * Pre-flight validation (mirrors the backend rules so the SPA
 * surfaces a friendly error without round-tripping):
 *   - File size ≤ 5 MB.
 *   - MIME type in {image/jpeg, image/png, image/webp}.
 *
 * The composable exposes three reactive fields:
 *   - `previewUrl` — a blob URL for the chosen file, suitable for
 *     `<img :src="previewUrl">`. Revoked on unmount + on every new
 *     file selection (no blob-URL leaks).
 *   - `error` — `null` | a `creator.ui.errors.*` i18n key the
 *     consumer's `<v-alert>` can render. Cleared on successful
 *     upload / new file selection.
 *   - `isUploading` — mirrors the store's flag for the consumer's
 *     disabled-state UI.
 *
 * Decision F1=a (bounded concurrency 3) applies only to portfolio
 * uploads; avatar is a single file at a time, no concurrency knob
 * needed.
 *
 * a11y (F2=b): the consumer (`AvatarUploadDrop`) emits an
 * `aria-live="polite"` region wired to `error` + a success banner
 * key — both come from this composable so the consumer stays a
 * presentational layer.
 */

import { computed, getCurrentInstance, onBeforeUnmount, ref, type ComputedRef, type Ref } from 'vue'

import { useOnboardingStore } from '../stores/useOnboardingStore'

export const AVATAR_MAX_BYTES = 5 * 1024 * 1024
export const AVATAR_ALLOWED_MIME_TYPES: ReadonlyArray<string> = [
  'image/jpeg',
  'image/png',
  'image/webp',
]
export const AVATAR_MAX_MB = AVATAR_MAX_BYTES / (1024 * 1024)
export const AVATAR_ALLOWED_DESCRIPTORS = 'JPG, PNG, WebP'

export type AvatarErrorKey =
  | 'creator.ui.errors.upload_too_large'
  | 'creator.ui.errors.upload_wrong_type'
  | 'creator.ui.errors.upload_failed'

export interface AvatarError {
  key: AvatarErrorKey
  values: Record<string, string | number>
}

export interface AvatarUploadHandle {
  previewUrl: Ref<string | null>
  error: Ref<AvatarError | null>
  isUploading: ComputedRef<boolean>
  validate: (file: File) => AvatarError | null
  selectFile: (file: File) => boolean
  upload: (file: File) => Promise<boolean>
  remove: () => Promise<void>
  reset: () => void
}

export function useAvatarUpload(): AvatarUploadHandle {
  const store = useOnboardingStore()
  const previewUrl = ref<string | null>(null)
  const error = ref<AvatarError | null>(null)
  const isUploading = computed(() => store.isUploadingAvatar)

  function revokeCurrentPreview(): void {
    if (previewUrl.value !== null) {
      URL.revokeObjectURL(previewUrl.value)
      previewUrl.value = null
    }
  }

  function validate(file: File): AvatarError | null {
    if (file.size > AVATAR_MAX_BYTES) {
      return {
        key: 'creator.ui.errors.upload_too_large',
        values: { max_mb: AVATAR_MAX_MB },
      }
    }
    if (!AVATAR_ALLOWED_MIME_TYPES.includes(file.type)) {
      return {
        key: 'creator.ui.errors.upload_wrong_type',
        values: { allowed_types: AVATAR_ALLOWED_DESCRIPTORS },
      }
    }
    return null
  }

  function selectFile(file: File): boolean {
    const validation = validate(file)
    revokeCurrentPreview()
    if (validation !== null) {
      error.value = validation
      return false
    }
    error.value = null
    previewUrl.value = URL.createObjectURL(file)
    return true
  }

  async function upload(file: File): Promise<boolean> {
    const validation = validate(file)
    if (validation !== null) {
      error.value = validation
      return false
    }
    error.value = null
    try {
      await store.uploadAvatar(file)
      revokeCurrentPreview()
      return true
    } catch {
      error.value = { key: 'creator.ui.errors.upload_failed', values: {} }
      return false
    }
  }

  async function remove(): Promise<void> {
    revokeCurrentPreview()
    error.value = null
    await store.deleteAvatar()
  }

  function reset(): void {
    revokeCurrentPreview()
    error.value = null
  }

  // Only register the lifecycle hook when called from inside a Vue
  // component setup(); standalone use (e.g. tests, server-side
  // glue) gets the explicit `reset()` escape hatch instead.
  if (getCurrentInstance() !== null) {
    onBeforeUnmount(() => {
      revokeCurrentPreview()
    })
  }

  return {
    previewUrl,
    error,
    isUploading,
    validate,
    selectFile,
    upload,
    remove,
    reset,
  }
}
