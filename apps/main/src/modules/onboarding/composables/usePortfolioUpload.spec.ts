import { setActivePinia, createPinia } from 'pinia'
import { flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    uploadPortfolioImage: vi.fn(),
    initiatePortfolioImageUpload: vi.fn(),
    completePortfolioImageUpload: vi.fn(),
    initiatePortfolioVideoUpload: vi.fn(),
    completePortfolioVideoUpload: vi.fn(),
    createPortfolioLink: vi.fn(),
    deletePortfolioItem: vi.fn(),
  },
}))

vi.mock('../internal/captureVideoPoster', () => ({
  captureVideoPoster: vi.fn(async () => null),
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
import { captureVideoPoster } from '../internal/captureVideoPoster'
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

/** Default happy-path presign mocks for the image flow (init → PUT → complete). */
function mockImageSuccess(): void {
  vi.mocked(uploadToPresignedUrl).mockResolvedValue(undefined)
  vi.mocked(onboardingApi.initiatePortfolioImageUpload).mockResolvedValue({
    data: {
      upload_id: 'img-u',
      upload_url: 'https://s3/img-u',
      storage_path: 'media/img-u',
      expires_at: '2026-05-14T12:00:00+00:00',
    },
  })
  vi.mocked(onboardingApi.completePortfolioImageUpload).mockImplementation(async (payload) => ({
    data: {
      id: `pid-${payload.upload_id}`,
      kind: 'image',
      s3_path: 'media/img-u',
      position: 1,
    },
  }))
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
  mockImageSuccess()
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
    it('uploads a single image through the presigned-PUT path (AH-004)', async () => {
      const { enqueue, items } = usePortfolioUpload()
      const item = enqueue(makeFile())
      // The scheduler's synchronous prelude bumps status to 'uploading'
      // before enqueue() returns; the externally visible journey is
      // pending → uploading → done.
      expect(item.status).toBe('uploading')
      await flushPromises()
      await flushPromises()

      expect(onboardingApi.initiatePortfolioImageUpload).toHaveBeenCalledWith({
        mime_type: 'image/jpeg',
        declared_bytes: 1024,
      })
      expect(uploadToPresignedUrl).toHaveBeenCalledWith(
        'https://s3/img-u',
        expect.any(File),
        expect.objectContaining({ onProgress: expect.any(Function) }),
      )
      expect(onboardingApi.completePortfolioImageUpload).toHaveBeenCalledWith(
        expect.objectContaining({ upload_id: 'img-u', mime_type: 'image/jpeg', size_bytes: 1024 }),
      )
      // The legacy direct-multipart endpoint is no longer used for images.
      expect(onboardingApi.uploadPortfolioImage).not.toHaveBeenCalled()

      const final = items.value[0]
      expect(final?.status).toBe('done')
      expect(final?.portfolioId).toBe('pid-img-u')
    })

    it('marks status=error when the complete call rejects', async () => {
      vi.mocked(onboardingApi.completePortfolioImageUpload).mockRejectedValueOnce(new Error('boom'))
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
      // Hold the complete-call promise so we can inspect the in-flight count.
      const pendingResolvers: Array<() => void> = []
      vi.mocked(onboardingApi.completePortfolioImageUpload).mockImplementation(async (payload) => {
        await new Promise<void>((resolve) => pendingResolvers.push(resolve))
        return {
          data: { id: `pid-${payload.upload_id}`, kind: 'image', s3_path: 'x', position: 1 },
        }
      })

      const { enqueue, inFlightCount, items } = usePortfolioUpload()
      for (let i = 0; i < 5; i++) {
        enqueue(makeFile({ name: `f${i}.jpg` }))
      }
      await flushPromises()

      expect(inFlightCount.value).toBe(PORTFOLIO_CONCURRENCY)
      expect(items.value.filter((i) => i.status === 'pending')).toHaveLength(
        5 - PORTFOLIO_CONCURRENCY,
      )

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
      const heldResolvers: Array<() => void> = []
      vi.mocked(onboardingApi.completePortfolioImageUpload).mockImplementation(async (payload) => {
        await new Promise<void>((resolve) => heldResolvers.push(resolve))
        return {
          data: { id: `pid-${payload.upload_id}`, kind: 'image', s3_path: 'x', position: 1 },
        }
      })

      const { enqueue } = usePortfolioUpload()
      for (let i = 0; i < PORTFOLIO_MAX_ITEMS; i++) {
        enqueue(makeFile({ name: `f${i}.jpg` }))
      }
      await flushPromises()
      const overflow = enqueue(makeFile({ name: 'extra.jpg' }))
      expect(overflow.status).toBe('error')
      expect(overflow.errorKey).toBe('creator.ui.errors.portfolio_max_reached')

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
      expect(uploadToPresignedUrl).toHaveBeenCalledWith(
        'https://s3/u-1',
        expect.any(File),
        expect.objectContaining({ onProgress: expect.any(Function) }),
      )
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

    it('captures a poster frame and forwards it to the complete call', async () => {
      const poster = new Blob(['jpeg-bytes'], { type: 'image/jpeg' })
      vi.mocked(captureVideoPoster).mockResolvedValueOnce(poster)
      vi.mocked(uploadToPresignedUrl).mockResolvedValue(undefined)
      vi.mocked(onboardingApi.initiatePortfolioVideoUpload).mockResolvedValue({
        data: {
          upload_id: 'u-3',
          upload_url: 'https://s3/u-3',
          storage_path: 'media/u-3',
          expires_at: '2026-05-14T12:00:00+00:00',
        },
      })
      vi.mocked(onboardingApi.completePortfolioVideoUpload).mockResolvedValue({
        data: { id: 'vid-3', kind: 'video', s3_path: 'media/u-3', position: 1 },
      })

      const file = makeFile({ name: 'clip.mp4', type: 'video/mp4', size: 5_000_000 })
      const { enqueue } = usePortfolioUpload()
      enqueue(file)
      await flushPromises()
      await flushPromises()
      await flushPromises()

      expect(captureVideoPoster).toHaveBeenCalledWith(file)
      expect(onboardingApi.completePortfolioVideoUpload).toHaveBeenCalledWith(
        expect.objectContaining({ upload_id: 'u-3', thumbnail: poster }),
      )
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

  describe('add link (AH-004 D9/D11)', () => {
    it('creates a link and re-bootstraps, returning true', async () => {
      const store = useOnboardingStore()
      const bootstrapSpy = vi.spyOn(store, 'bootstrap').mockResolvedValue(undefined)
      vi.mocked(onboardingApi.createPortfolioLink).mockResolvedValue({
        data: { id: 'link-1', kind: 'link', s3_path: '', position: 1 },
      } as never)

      const { addLink } = usePortfolioUpload()
      const ok = await addLink('https://example.com/reel', 'My reel')

      expect(ok).toBe(true)
      expect(onboardingApi.createPortfolioLink).toHaveBeenCalledWith({
        external_url: 'https://example.com/reel',
        title: 'My reel',
      })
      expect(bootstrapSpy).toHaveBeenCalled()
    })

    it('returns false (and does not call the API) when the cap is reached', async () => {
      const store = useOnboardingStore()
      store.creator = {
        id: '01H',
        type: 'creators',
        attributes: {
          portfolio: Array.from({ length: PORTFOLIO_MAX_ITEMS }, (_, i) => ({
            id: `seed-${i}`,
            kind: 'image',
          })),
        } as never,
        wizard: {} as never,
      } as never

      const { addLink } = usePortfolioUpload()
      const ok = await addLink('https://example.com/reel')

      expect(ok).toBe(false)
      expect(onboardingApi.createPortfolioLink).not.toHaveBeenCalled()
    })

    it('returns false when the create call rejects', async () => {
      const store = useOnboardingStore()
      vi.spyOn(store, 'bootstrap').mockResolvedValue(undefined)
      vi.mocked(onboardingApi.createPortfolioLink).mockRejectedValue(new Error('boom'))

      const { addLink } = usePortfolioUpload()
      expect(await addLink('https://example.com/reel')).toBe(false)
    })
  })

  describe('remove', () => {
    it('removes a pending item without calling the backend', async () => {
      const heldResolvers: Array<() => void> = []
      vi.mocked(onboardingApi.completePortfolioImageUpload).mockImplementation(async (payload) => {
        await new Promise<void>((r) => heldResolvers.push(r))
        return {
          data: { id: `pid-${payload.upload_id}`, kind: 'image', s3_path: 'x', position: 1 },
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
      expect(onboardingApi.deletePortfolioItem).toHaveBeenCalledWith('pid-img-u')
      expect(items.value).toHaveLength(0)
    })
  })

  describe('slot accounting', () => {
    function seedPersistedPortfolio(count: number): void {
      const store = useOnboardingStore()
      store.creator = {
        id: '01H',
        type: 'creators',
        attributes: {
          portfolio: Array.from({ length: count }, (_, i) => ({ id: `seed-${i}`, kind: 'image' })),
        } as never,
        wizard: {} as never,
      } as never
    }

    it('counts already-persisted portfolio items against the cap', () => {
      seedPersistedPortfolio(3)
      const { remainingSlots } = usePortfolioUpload()
      // cap − 3 persisted − 0 in-flight.
      expect(remainingSlots.value).toBe(PORTFOLIO_MAX_ITEMS - 3)
    })

    it('subtracts in-flight uploads on top of persisted items', async () => {
      seedPersistedPortfolio(PORTFOLIO_MAX_ITEMS - 2)
      const heldResolvers: Array<() => void> = []
      vi.mocked(onboardingApi.completePortfolioImageUpload).mockImplementation(async (payload) => {
        await new Promise<void>((r) => heldResolvers.push(r))
        return {
          data: { id: `pid-${payload.upload_id}`, kind: 'image', s3_path: 'x', position: 1 },
        }
      })

      const { enqueue, remainingSlots } = usePortfolioUpload()
      enqueue(makeFile({ name: 'a.jpg' }))
      await flushPromises()
      // cap − (cap−2) persisted − 1 uploading = 1 slot left.
      expect(remainingSlots.value).toBe(1)

      enqueue(makeFile({ name: 'b.jpg' }))
      await flushPromises()
      // Now full.
      expect(remainingSlots.value).toBe(0)

      const overflow = enqueue(makeFile({ name: 'c.jpg' }))
      expect(overflow.status).toBe('error')
      expect(overflow.errorKey).toBe('creator.ui.errors.portfolio_max_reached')

      while (heldResolvers.length > 0) heldResolvers.shift()?.()
      await flushPromises()
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
