import { setActivePinia, createPinia } from 'pinia'
import { flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    uploadPortfolioImage: vi.fn(),
    initiatePortfolioVideoUpload: vi.fn(),
    completePortfolioVideoUpload: vi.fn(),
    deletePortfolioItem: vi.fn(),
  },
}))

vi.mock('@catalyst/api-client', async () => {
  const actual =
    await vi.importActual<typeof import('@catalyst/api-client')>('@catalyst/api-client')
  return {
    ...actual,
    uploadToPresignedUrl: vi.fn(),
  }
})

import { uploadToPresignedUrl } from '@catalyst/api-client'
import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import {
  PORTFOLIO_CONCURRENCY,
  PORTFOLIO_IMAGE_MAX_BYTES,
  PORTFOLIO_IMAGE_MAX_MB,
  PORTFOLIO_VIDEO_MAX_BYTES,
  PORTFOLIO_VIDEO_MAX_MB,
  PORTFOLIO_MAX_ITEMS,
  usePortfolioUpload,
} from './usePortfolioUpload'

function makeFile({
  name = 'asset.jpg',
  size = 1024,
  type = 'image/jpeg',
}: { name?: string; size?: number; type?: string } = {}): File {
  const f = new File(['x'], name, { type })
  Object.defineProperty(f, 'size', { value: size, configurable: true })
  return f
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  // Default — backend always reports success.
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue({
    data: {
      id: '01H',
      type: 'creators',
      attributes: {} as never,
      wizard: {} as never,
    },
  } as never)
  vi.mocked(onboardingApi.uploadPortfolioImage).mockImplementation(async (file) => {
    return {
      data: {
        id: `pid-${file.name}`,
        kind: 'image',
        s3_path: 'media/x',
        position: 1,
      },
    }
  })
})

describe('usePortfolioUpload', () => {
  describe('validation', () => {
    it('flags oversize images as upload_too_large with image cap', () => {
      const { enqueue, items } = usePortfolioUpload()
      const item = enqueue(makeFile({ size: PORTFOLIO_IMAGE_MAX_BYTES + 1 }))
      expect(item.status).toBe('error')
      expect(item.errorKey).toBe('creator.ui.errors.upload_too_large')
      expect(item.errorValues['max_mb']).toBe(PORTFOLIO_IMAGE_MAX_MB)
      expect(items.value).toHaveLength(1)
    })

    it('flags oversize videos with video cap', () => {
      const { enqueue } = usePortfolioUpload()
      const item = enqueue(
        makeFile({
          name: 'vid.mp4',
          type: 'video/mp4',
          size: PORTFOLIO_VIDEO_MAX_BYTES + 1,
        }),
      )
      expect(item.errorKey).toBe('creator.ui.errors.upload_too_large')
      expect(item.errorValues['max_mb']).toBe(PORTFOLIO_VIDEO_MAX_MB)
    })

    it('flags unsupported MIME types as upload_wrong_type', () => {
      const { enqueue } = usePortfolioUpload()
      const item = enqueue(makeFile({ name: 'doc.pdf', type: 'application/pdf' }))
      expect(item.status).toBe('error')
      expect(item.errorKey).toBe('creator.ui.errors.upload_wrong_type')
    })

    it('accepts image/jpeg, image/png, image/webp', () => {
      const { enqueue } = usePortfolioUpload()
      expect(enqueue(makeFile({ type: 'image/jpeg' })).status).not.toBe('error')
      expect(enqueue(makeFile({ type: 'image/png' })).status).not.toBe('error')
      expect(enqueue(makeFile({ type: 'image/webp' })).status).not.toBe('error')
    })

    it('accepts video/mp4, video/webm, video/quicktime', () => {
      vi.mocked(uploadToPresignedUrl).mockResolvedValue(undefined)
      vi.mocked(onboardingApi.initiatePortfolioVideoUpload).mockResolvedValue({
        data: {
          upload_id: 'u',
          upload_url: 'https://s3/u',
          storage_path: 'media/u',
          expires_at: '2026-05-14T12:00:00+00:00',
        },
      })
      vi.mocked(onboardingApi.completePortfolioVideoUpload).mockResolvedValue({
        data: { id: 'p', kind: 'video', s3_path: 'media/u', position: 1 },
      })
      const { enqueue } = usePortfolioUpload()
      expect(enqueue(makeFile({ name: 'a.mp4', type: 'video/mp4' })).status).not.toBe('error')
      expect(enqueue(makeFile({ name: 'b.webm', type: 'video/webm' })).status).not.toBe('error')
      expect(enqueue(makeFile({ name: 'c.mov', type: 'video/quicktime' })).status).not.toBe('error')
    })
  })

  describe('image uploads', () => {
    it('uploads a single image through the direct-multipart endpoint', async () => {
      const { enqueue, items } = usePortfolioUpload()
      const item = enqueue(makeFile())
      // The scheduler's synchronous prelude bumps status to
      // 'uploading' before enqueue() returns (no microtask yield
      // until the first await inside uploadOne); the externally
      // visible journey is therefore pending → uploading → done.
      expect(item.status).toBe('uploading')
      await flushPromises()
      await flushPromises()
      expect(onboardingApi.uploadPortfolioImage).toHaveBeenCalledTimes(1)
      const final = items.value[0]
      expect(final?.status).toBe('done')
      expect(final?.portfolioId).toBe('pid-asset.jpg')
    })

    it('marks status=error when the API rejects', async () => {
      vi.mocked(onboardingApi.uploadPortfolioImage).mockRejectedValueOnce(new Error('boom'))
      const { enqueue, items } = usePortfolioUpload()
      enqueue(makeFile({ name: 'fail.jpg' }))
      await flushPromises()
      await flushPromises()
      const final = items.value[0]
      expect(final?.status).toBe('error')
      expect(final?.errorKey).toBe('creator.ui.errors.upload_failed')
    })
  })

  describe('bounded concurrency', () => {
    it('runs no more than PORTFOLIO_CONCURRENCY uploads in flight', async () => {
      // Use a deferred image-upload promise so we can inspect the
      // in-flight count mid-flight.
      let pendingResolvers: Array<() => void> = []
      vi.mocked(onboardingApi.uploadPortfolioImage).mockImplementation(async (file) => {
        await new Promise<void>((resolve) => pendingResolvers.push(resolve))
        return {
          data: { id: `pid-${file.name}`, kind: 'image', s3_path: 'x', position: 1 },
        }
      })

      const { enqueue, inFlightCount, items } = usePortfolioUpload()
      for (let i = 0; i < 5; i++) {
        enqueue(makeFile({ name: `f${i}.jpg` }))
      }
      // Let the scheduler microtask the first batch.
      await flushPromises()

      expect(inFlightCount.value).toBe(PORTFOLIO_CONCURRENCY)
      expect(items.value.filter((i) => i.status === 'pending')).toHaveLength(
        5 - PORTFOLIO_CONCURRENCY,
      )

      // Drain — release each resolver and let the scheduler pick up
      // the queued items.
      while (pendingResolvers.length > 0) {
        const next = pendingResolvers.shift()
        next?.()
        await flushPromises()
        await flushPromises()
      }

      expect(items.value.filter((i) => i.status === 'done')).toHaveLength(5)
    })
  })

  describe('per-creator cap', () => {
    it('rejects enqueue past the per-creator cap with portfolio_max_reached', async () => {
      // Hold the upload promises open so the items stay in
      // `uploading` (counted against the cap) while we test the
      // overflow branch.
      const heldResolvers: Array<() => void> = []
      vi.mocked(onboardingApi.uploadPortfolioImage).mockImplementation(async (file) => {
        await new Promise<void>((resolve) => heldResolvers.push(resolve))
        return {
          data: { id: `pid-${file.name}`, kind: 'image', s3_path: 'x', position: 1 },
        }
      })

      const { enqueue } = usePortfolioUpload()
      for (let i = 0; i < PORTFOLIO_MAX_ITEMS; i++) {
        enqueue(makeFile({ name: `f${i}.jpg` }))
      }
      await flushPromises()
      // 11th item should be rejected as portfolio_max_reached.
      const overflow = enqueue(makeFile({ name: 'extra.jpg' }))
      expect(overflow.status).toBe('error')
      expect(overflow.errorKey).toBe('creator.ui.errors.portfolio_max_reached')

      // Drain held uploads to clean up.
      while (heldResolvers.length > 0) {
        const next = heldResolvers.shift()
        next?.()
      }
      await flushPromises()
    })
  })

  describe('video uploads', () => {
    it('runs init → presigned PUT → complete', async () => {
      vi.mocked(uploadToPresignedUrl).mockResolvedValue(undefined)
      vi.mocked(onboardingApi.initiatePortfolioVideoUpload).mockResolvedValue({
        data: {
          upload_id: 'u-1',
          upload_url: 'https://s3/u-1',
          storage_path: 'media/u-1',
          expires_at: '2026-05-14T12:00:00+00:00',
        },
      })
      vi.mocked(onboardingApi.completePortfolioVideoUpload).mockResolvedValue({
        data: { id: 'vid-1', kind: 'video', s3_path: 'media/u-1', position: 1 },
      })

      const { enqueue, items } = usePortfolioUpload()
      enqueue(makeFile({ name: 'clip.mp4', type: 'video/mp4', size: 5_000_000 }))
      await flushPromises()
      await flushPromises()
      await flushPromises()

      expect(onboardingApi.initiatePortfolioVideoUpload).toHaveBeenCalledWith({
        mime_type: 'video/mp4',
        declared_bytes: 5_000_000,
      })
      expect(uploadToPresignedUrl).toHaveBeenCalledWith('https://s3/u-1', expect.any(File))
      expect(onboardingApi.completePortfolioVideoUpload).toHaveBeenCalledWith(
        expect.objectContaining({
          upload_id: 'u-1',
          mime_type: 'video/mp4',
          size_bytes: 5_000_000,
        }),
      )
      expect(items.value[0]?.status).toBe('done')
      expect(items.value[0]?.portfolioId).toBe('vid-1')
    })

    it('marks status=error if the presigned PUT fails', async () => {
      vi.mocked(uploadToPresignedUrl).mockRejectedValue(new Error('presigned 500'))
      vi.mocked(onboardingApi.initiatePortfolioVideoUpload).mockResolvedValue({
        data: {
          upload_id: 'u-2',
          upload_url: 'https://s3/u-2',
          storage_path: 'media/u-2',
          expires_at: '2026-05-14T12:00:00+00:00',
        },
      })

      const { enqueue, items } = usePortfolioUpload()
      enqueue(makeFile({ name: 'clip.mp4', type: 'video/mp4', size: 5_000_000 }))
      await flushPromises()
      await flushPromises()
      await flushPromises()

      expect(items.value[0]?.status).toBe('error')
      expect(items.value[0]?.errorKey).toBe('creator.ui.errors.upload_failed')
      expect(onboardingApi.completePortfolioVideoUpload).not.toHaveBeenCalled()
    })
  })

  describe('remove', () => {
    it('removes a pending item without calling the backend', async () => {
      // Hold the first upload so the second item stays pending.
      const heldResolvers: Array<() => void> = []
      vi.mocked(onboardingApi.uploadPortfolioImage).mockImplementation(async (file) => {
        await new Promise<void>((r) => heldResolvers.push(r))
        return {
          data: { id: `pid-${file.name}`, kind: 'image', s3_path: 'x', position: 1 },
        }
      })

      const { enqueue, remove, items } = usePortfolioUpload()
      enqueue(makeFile({ name: 'a.jpg' }))
      enqueue(makeFile({ name: 'b.jpg' }))
      enqueue(makeFile({ name: 'c.jpg' }))
      enqueue(makeFile({ name: 'd.jpg' }))
      await flushPromises()

      const pending = items.value.find((i) => i.status === 'pending')
      expect(pending).toBeDefined()
      const pendingId = pending?.id
      if (pendingId === undefined) throw new Error('expected pending')

      await remove(pendingId)
      expect(items.value.find((i) => i.id === pendingId)).toBeUndefined()
      expect(onboardingApi.deletePortfolioItem).not.toHaveBeenCalled()

      // Drain held.
      while (heldResolvers.length > 0) {
        heldResolvers.shift()?.()
      }
      await flushPromises()
    })

    it('calls deletePortfolioItem for a done item', async () => {
      const store = useOnboardingStore()
      vi.spyOn(store, 'bootstrap').mockResolvedValue(undefined)
      vi.mocked(onboardingApi.deletePortfolioItem).mockResolvedValue(undefined)

      const { enqueue, remove, items } = usePortfolioUpload()
      enqueue(makeFile({ name: 'a.jpg' }))
      await flushPromises()
      await flushPromises()

      const done = items.value[0]
      expect(done?.status).toBe('done')
      const id = done?.id
      if (id === undefined) throw new Error('expected id')

      await remove(id)
      expect(onboardingApi.deletePortfolioItem).toHaveBeenCalledWith('pid-a.jpg')
      expect(items.value).toHaveLength(0)
    })
  })

  describe('reset', () => {
    it('clears the queue', async () => {
      const { enqueue, reset, items } = usePortfolioUpload()
      enqueue(makeFile({ name: 'a.jpg' }))
      enqueue(makeFile({ name: 'b.jpg' }))
      reset()
      expect(items.value).toHaveLength(0)
    })
  })
})
