import { setActivePinia, createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    uploadAvatar: vi.fn(),
    deleteAvatar: vi.fn(),
  },
}))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import {
  AVATAR_ALLOWED_DESCRIPTORS,
  AVATAR_MAX_BYTES,
  AVATAR_MAX_MB,
  useAvatarUpload,
} from './useAvatarUpload'

// Stub the blob-URL constructor — JSDOM doesn't provide one and the
// composable creates/revokes preview URLs in its happy path.
const createObjectURLSpy = vi.fn((blob: Blob | MediaSource) => {
  void blob
  return 'blob://stub'
})
const revokeObjectURLSpy = vi.fn()
beforeEach(() => {
  Object.defineProperty(globalThis.URL, 'createObjectURL', {
    configurable: true,
    value: createObjectURLSpy,
  })
  Object.defineProperty(globalThis.URL, 'revokeObjectURL', {
    configurable: true,
    value: revokeObjectURLSpy,
  })
  createObjectURLSpy.mockClear()
  revokeObjectURLSpy.mockClear()
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

function makeFile({
  name = 'avatar.jpg',
  size = 1024,
  type = 'image/jpeg',
}: { name?: string; size?: number; type?: string } = {}): File {
  // We synthesise a File whose `size` is a fixed test value; the
  // composable reads `file.size` directly, so spoofing it here is
  // the cleanest way to test the size-cap branch.
  const file = new File(['x'], name, { type })
  Object.defineProperty(file, 'size', { value: size, configurable: true })
  return file
}

describe('useAvatarUpload', () => {
  describe('validate', () => {
    it('returns null for a valid image under the size cap', () => {
      const { validate } = useAvatarUpload()
      expect(validate(makeFile({ size: 1000 }))).toBeNull()
    })

    it('rejects files over the size cap with upload_too_large', () => {
      const { validate } = useAvatarUpload()
      const file = makeFile({ size: AVATAR_MAX_BYTES + 1 })
      const result = validate(file)
      expect(result).toEqual({
        key: 'creator.ui.errors.upload_too_large',
        values: { max_mb: AVATAR_MAX_MB },
      })
    })

    it('rejects disallowed MIME types with upload_wrong_type', () => {
      const { validate } = useAvatarUpload()
      const file = makeFile({ type: 'image/gif' })
      const result = validate(file)
      expect(result).toEqual({
        key: 'creator.ui.errors.upload_wrong_type',
        values: { allowed_types: AVATAR_ALLOWED_DESCRIPTORS },
      })
    })

    it('accepts png and webp in addition to jpeg', () => {
      const { validate } = useAvatarUpload()
      expect(validate(makeFile({ type: 'image/png' }))).toBeNull()
      expect(validate(makeFile({ type: 'image/webp' }))).toBeNull()
    })
  })

  describe('selectFile', () => {
    it('creates a preview URL on a valid file', () => {
      const { previewUrl, selectFile, error } = useAvatarUpload()
      const ok = selectFile(makeFile())
      expect(ok).toBe(true)
      expect(previewUrl.value).toBe('blob://stub')
      expect(error.value).toBeNull()
      expect(createObjectURLSpy).toHaveBeenCalledTimes(1)
    })

    it('rejects invalid file and emits error, no preview', () => {
      const { previewUrl, selectFile, error } = useAvatarUpload()
      const ok = selectFile(makeFile({ size: AVATAR_MAX_BYTES + 1 }))
      expect(ok).toBe(false)
      expect(previewUrl.value).toBeNull()
      expect(error.value?.key).toBe('creator.ui.errors.upload_too_large')
    })

    it('revokes the previous preview URL when a new file is selected', () => {
      const { selectFile } = useAvatarUpload()
      selectFile(makeFile())
      selectFile(makeFile())
      expect(revokeObjectURLSpy).toHaveBeenCalledWith('blob://stub')
    })
  })

  describe('upload', () => {
    it('calls the store action on a valid file and clears preview on success', async () => {
      const { selectFile, upload, error } = useAvatarUpload()
      const store = useOnboardingStore()
      const uploadAvatarSpy = vi.spyOn(store, 'uploadAvatar').mockResolvedValue(undefined)

      selectFile(makeFile())
      const result = await upload(makeFile({ name: 'final.png', type: 'image/png' }))

      expect(result).toBe(true)
      expect(uploadAvatarSpy).toHaveBeenCalledTimes(1)
      expect(error.value).toBeNull()
      // Preview URL revoked on success.
      expect(revokeObjectURLSpy).toHaveBeenCalled()
    })

    it('sets upload_failed when the store action rejects', async () => {
      const { upload, error } = useAvatarUpload()
      const store = useOnboardingStore()
      vi.spyOn(store, 'uploadAvatar').mockRejectedValue(new Error('boom'))

      const result = await upload(makeFile())

      expect(result).toBe(false)
      expect(error.value?.key).toBe('creator.ui.errors.upload_failed')
    })

    it('refuses to call the store action on an oversize file', async () => {
      const { upload, error } = useAvatarUpload()
      const store = useOnboardingStore()
      const uploadAvatarSpy = vi.spyOn(store, 'uploadAvatar')

      const result = await upload(makeFile({ size: AVATAR_MAX_BYTES + 1 }))

      expect(result).toBe(false)
      expect(uploadAvatarSpy).not.toHaveBeenCalled()
      expect(error.value?.key).toBe('creator.ui.errors.upload_too_large')
    })
  })

  describe('remove', () => {
    it('calls deleteAvatar on the store and clears the preview', async () => {
      const { selectFile, remove } = useAvatarUpload()
      const store = useOnboardingStore()
      const deleteAvatarSpy = vi.spyOn(store, 'deleteAvatar').mockResolvedValue(undefined)

      selectFile(makeFile())
      await remove()

      expect(deleteAvatarSpy).toHaveBeenCalledTimes(1)
      expect(revokeObjectURLSpy).toHaveBeenCalledWith('blob://stub')
    })
  })

  describe('isUploading', () => {
    it('mirrors the store flag', () => {
      const { isUploading } = useAvatarUpload()
      const store = useOnboardingStore()
      expect(isUploading.value).toBe(false)
      store.isUploadingAvatar = true
      expect(isUploading.value).toBe(true)
    })
  })

  it('exposes the api module reference (for completeness)', () => {
    // No-op assertion that the api module is the expected mock — this
    // keeps the test file honest about which dependency surface
    // useAvatarUpload sits on top of.
    expect(onboardingApi.uploadAvatar).toBeDefined()
  })
})
