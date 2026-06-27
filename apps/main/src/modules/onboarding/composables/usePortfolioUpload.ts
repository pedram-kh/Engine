/**
 * Portfolio-upload composable with bounded concurrency (Sprint 3
 * Chunk 3 sub-step 3, Decision F1=a — concurrency cap = 3).
 *
 * Manages the per-creator portfolio upload queue. Each file enqueued
 * via {@link enqueue} progresses through:
 *
 *   - `'pending'`  — accepted by validation, waiting for a slot.
 *   - `'uploading'` — actively in flight (one of up to 3 concurrent).
 *   - `'done'`     — successfully persisted; backend created the
 *                    portfolio item; the consumer's gallery refreshes.
 *   - `'error'`    — validation failure or upload failure; consumer
 *                    renders the per-file error key.
 *
 * Why bounded concurrency 3:
 *   - The platform's bandwidth budget tolerates more than 1 in
 *     flight (we don't serialise) but not unbounded (a creator
 *     bulk-dropping 50 files would saturate the upload pipe and
 *     trigger backend rate-limit responses).
 *   - 3 is the chunk-3 plan's locked answer (Q-portfolio-concurrency = (a)),
 *     mirroring the Sprint 5 OAuth job pattern.
 *
 * Image vs video routing:
 *   - `image/*` MIME → direct-multipart POST to /portfolio/images.
 *   - `video/*` MIME → presigned-S3 two-phase shape:
 *       1. POST /portfolio/videos/init with mime + size →
 *          { upload_id, upload_url }.
 *       2. PUT to S3 upload_url with the file body.
 *       3. POST /portfolio/videos/complete with upload_id + meta →
 *          backend confirms the S3 object and persists the row.
 *     If step 2 or 3 fails, the row is NOT persisted and the SPA
 *     surfaces the upload as `error`. The presigned URL expires
 *     after 30 min server-side.
 *
 * Pre-flight validation (mirrors backend rules):
 *   - Image: ≤ 10 MB, MIME in {image/jpeg, image/png, image/webp}.
 *   - Video: ≤ 500 MB, MIME in {video/mp4, video/webm, video/quicktime}.
 *   - Per-creator cap: 10 items total (asserted at server; SPA
 *     refuses to enqueue when the current count + queue would
 *     exceed the cap).
 *
 * a11y (F2=b): each item exposes a `status` field consumers wire
 * into an `aria-live="polite"` region; the screen-reader hears
 * "Uploading 1 of 3, video.mp4… upload complete." in sequence.
 *
 * The composable does NOT manage display of the gallery itself —
 * that's `PortfolioGallery.vue` reading from `creator.portfolio`
 * (post-bootstrap state). It manages only the upload pipeline.
 */

import { uploadToPresignedUrl } from '@catalyst/api-client'
import { computed, ref, type ComputedRef, type Ref } from 'vue'

import { onboardingApi } from '../api/onboarding.api'
import { captureVideoPoster } from '../internal/captureVideoPoster'
import { useOnboardingStore } from '../stores/useOnboardingStore'

// AH-004 D8: 10 → 30 items/creator, uniform 500 MB ceiling for ALL file types
// (images now ride the same presigned-PUT path as video).
export const PORTFOLIO_MAX_ITEMS = 30
export const PORTFOLIO_CONCURRENCY = 3
export const PORTFOLIO_IMAGE_MAX_BYTES = 500 * 1024 * 1024
export const PORTFOLIO_VIDEO_MAX_BYTES = 500 * 1024 * 1024
export const PORTFOLIO_IMAGE_MAX_MB = PORTFOLIO_IMAGE_MAX_BYTES / (1024 * 1024)
export const PORTFOLIO_VIDEO_MAX_MB = PORTFOLIO_VIDEO_MAX_BYTES / (1024 * 1024)
export const PORTFOLIO_IMAGE_ALLOWED_MIME_TYPES: ReadonlyArray<string> = [
  'image/jpeg',
  'image/png',
  'image/webp',
]
export const PORTFOLIO_VIDEO_ALLOWED_MIME_TYPES: ReadonlyArray<string> = [
  'video/mp4',
  'video/webm',
  'video/quicktime',
]

export type PortfolioUploadStatus = 'pending' | 'uploading' | 'done' | 'error'

export type PortfolioErrorKey =
  | 'creator.ui.errors.upload_too_large'
  | 'creator.ui.errors.upload_wrong_type'
  | 'creator.ui.errors.upload_failed'
  | 'creator.ui.errors.portfolio_max_reached'

export interface PortfolioUploadItem {
  /** Stable identifier for the v-for key + the consumer to track. */
  id: string
  file: File
  kind: 'image' | 'video'
  status: PortfolioUploadStatus
  /** 0..100 — best-effort approximation; image uploads emit 0/100. */
  progress: number
  errorKey: PortfolioErrorKey | null
  errorValues: Record<string, string | number>
  /**
   * The backend-issued ULID for the persisted row, populated only
   * once `status === 'done'`. Consumers use this to delete the
   * item via {@link onboardingApi.deletePortfolioItem}.
   */
  portfolioId: string | null
}

interface ValidationError {
  key: PortfolioErrorKey
  values: Record<string, string | number>
}

function validateFile(file: File): ValidationError | null {
  const isImage = PORTFOLIO_IMAGE_ALLOWED_MIME_TYPES.includes(file.type)
  const isVideo = PORTFOLIO_VIDEO_ALLOWED_MIME_TYPES.includes(file.type)
  if (!isImage && !isVideo) {
    return {
      key: 'creator.ui.errors.upload_wrong_type',
      values: {
        allowed_types: [
          ...PORTFOLIO_IMAGE_ALLOWED_MIME_TYPES,
          ...PORTFOLIO_VIDEO_ALLOWED_MIME_TYPES,
        ].join(', '),
      },
    }
  }
  const cap = isImage ? PORTFOLIO_IMAGE_MAX_BYTES : PORTFOLIO_VIDEO_MAX_BYTES
  if (file.size > cap) {
    return {
      key: 'creator.ui.errors.upload_too_large',
      values: { max_mb: isImage ? PORTFOLIO_IMAGE_MAX_MB : PORTFOLIO_VIDEO_MAX_MB },
    }
  }
  return null
}

function newId(): string {
  return `upload-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

export interface PortfolioUploadHandle {
  items: Ref<PortfolioUploadItem[]>
  hasError: ComputedRef<boolean>
  inFlightCount: ComputedRef<number>
  remainingSlots: ComputedRef<number>
  enqueue: (file: File) => PortfolioUploadItem
  addLink: (externalUrl: string, title?: string) => Promise<boolean>
  remove: (id: string) => Promise<void>
  reset: () => void
}

export function usePortfolioUpload(): PortfolioUploadHandle {
  const store = useOnboardingStore()
  const items = ref<PortfolioUploadItem[]>([])

  const inFlightCount = computed(() => items.value.filter((it) => it.status === 'uploading').length)

  /**
   * Already-persisted portfolio items, sourced from the canonical
   * bootstrap state. A `done` upload is folded into this list once
   * its post-upload `store.bootstrap()` resolves, so it must NOT also
   * be counted from the local queue (see `inFlightSlotCount`) or the
   * item would be double-charged against the cap.
   */
  const persistedCount = computed(() => store.creator?.attributes.portfolio?.length ?? 0)

  /**
   * Local-queue items still occupying a slot but NOT yet reflected in
   * the persisted list: `pending` + `uploading`. `error` items never
   * reached the server; `done` items are already counted via
   * `persistedCount` after their bootstrap refresh.
   */
  const inFlightSlotCount = computed(
    () => items.value.filter((it) => it.status === 'pending' || it.status === 'uploading').length,
  )

  /**
   * Slots remaining before the per-creator cap of 10 items kicks in:
   * `cap - (persisted items) - (in-flight local uploads)`. The backend
   * is the canonical source for what's persisted; bootstrap state
   * exposes the full `portfolio` array, so we count it directly rather
   * than guessing. Server-side 409s remain the final backstop and are
   * surfaced per-file as `upload_failed`.
   */
  const remainingSlots = computed(
    () => PORTFOLIO_MAX_ITEMS - persistedCount.value - inFlightSlotCount.value,
  )

  const hasError = computed(() => items.value.some((it) => it.status === 'error'))

  /**
   * Add a file to the queue. Validation fires immediately; if the
   * file passes, the item enters `pending` and the scheduler picks
   * it up.
   */
  function enqueue(file: File): PortfolioUploadItem {
    const validation = validateFile(file)
    if (validation !== null) {
      const item: PortfolioUploadItem = {
        id: newId(),
        file,
        kind: PORTFOLIO_IMAGE_ALLOWED_MIME_TYPES.includes(file.type) ? 'image' : 'video',
        status: 'error',
        progress: 0,
        errorKey: validation.key,
        errorValues: validation.values,
        portfolioId: null,
      }
      items.value.push(item)
      return item
    }
    if (remainingSlots.value <= 0) {
      const item: PortfolioUploadItem = {
        id: newId(),
        file,
        kind: PORTFOLIO_IMAGE_ALLOWED_MIME_TYPES.includes(file.type) ? 'image' : 'video',
        status: 'error',
        progress: 0,
        errorKey: 'creator.ui.errors.portfolio_max_reached',
        errorValues: { max: PORTFOLIO_MAX_ITEMS },
        portfolioId: null,
      }
      items.value.push(item)
      return item
    }
    const item: PortfolioUploadItem = {
      id: newId(),
      file,
      kind: PORTFOLIO_IMAGE_ALLOWED_MIME_TYPES.includes(file.type) ? 'image' : 'video',
      status: 'pending',
      progress: 0,
      errorKey: null,
      errorValues: {},
      portfolioId: null,
    }
    items.value.push(item)
    void schedule()
    return item
  }

  async function remove(id: string): Promise<void> {
    const idx = items.value.findIndex((it) => it.id === id)
    if (idx === -1) return
    const item = items.value[idx]
    if (item === undefined) return
    if (item.status === 'done' && item.portfolioId !== null) {
      try {
        await onboardingApi.deletePortfolioItem(item.portfolioId)
        await store.bootstrap()
      } catch {
        // Silent — the item was already done; removal is a best
        // effort. The consumer's gallery refreshes via bootstrap.
      }
    }
    // Remove from local queue regardless of backend outcome.
    items.value.splice(idx, 1)
  }

  function reset(): void {
    items.value = []
  }

  /**
   * Scheduler: while there's free capacity AND a pending item,
   * pick the next pending and start it. Re-fires on every item
   * status change.
   */
  async function schedule(): Promise<void> {
    while (inFlightCount.value < PORTFOLIO_CONCURRENCY) {
      const next = items.value.find((it) => it.status === 'pending')
      if (next === undefined) return
      next.status = 'uploading'
      // Don't await — let the loop continue picking up more pending
      // items up to the concurrency cap.
      void uploadOne(next).finally(() => {
        void schedule()
      })
    }
  }

  async function uploadOne(item: PortfolioUploadItem): Promise<void> {
    try {
      const onProgress = (percent: number): void => {
        item.progress = percent
      }

      if (item.kind === 'image') {
        // AH-004 Q5/D8: images now use the presigned-PUT path (uniform 500 MB).
        // The completed item starts `processing` server-side while the worker
        // strips EXIF + builds the thumbnail; the gallery reflects that state.
        const init = await onboardingApi.initiatePortfolioImageUpload({
          mime_type: item.file.type,
          declared_bytes: item.file.size,
        })
        await uploadToPresignedUrl(init.data.upload_url, item.file, { onProgress })
        const complete = await onboardingApi.completePortfolioImageUpload({
          upload_id: init.data.upload_id,
          mime_type: item.file.type,
          size_bytes: item.file.size,
        })
        item.portfolioId = complete.data.id
      } else {
        const init = await onboardingApi.initiatePortfolioVideoUpload({
          mime_type: item.file.type,
          declared_bytes: item.file.size,
        })
        await uploadToPresignedUrl(init.data.upload_url, item.file, { onProgress })
        // Best-effort poster frame so the gallery shows a real preview.
        // Returns null on any failure — the upload proceeds regardless.
        const poster = await captureVideoPoster(item.file)
        const complete = await onboardingApi.completePortfolioVideoUpload({
          upload_id: init.data.upload_id,
          mime_type: item.file.type,
          size_bytes: item.file.size,
          thumbnail: poster,
        })
        item.portfolioId = complete.data.id
      }
      item.progress = 100
      item.status = 'done'
      // Refresh the store so the wizard step's completion flag flips
      // when the first portfolio item lands.
      await store.bootstrap()
    } catch {
      item.status = 'error'
      item.errorKey = 'creator.ui.errors.upload_failed'
      item.errorValues = {}
    }
  }

  /**
   * Add a titled external link (AH-004 D9/D11). Distinct from the file-upload
   * queue: a link is created server-side immediately (no S3), then the store
   * re-bootstraps so the gallery shows it. Honors the same per-creator cap.
   * Returns true on success, false when the cap is reached or the create fails.
   */
  async function addLink(externalUrl: string, title?: string): Promise<boolean> {
    if (remainingSlots.value <= 0) {
      return false
    }
    try {
      await onboardingApi.createPortfolioLink({
        external_url: externalUrl,
        ...(title !== undefined && title !== '' ? { title } : {}),
      })
      await store.bootstrap()
      return true
    } catch {
      return false
    }
  }

  return {
    items,
    hasError,
    inFlightCount,
    remainingSlots,
    enqueue,
    addLink,
    remove,
    reset,
  }
}
