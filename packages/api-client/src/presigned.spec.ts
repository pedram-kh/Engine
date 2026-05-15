/**
 * Tests for {@link uploadToPresignedUrl}.
 *
 * The helper sits OUTSIDE the {@link createHttpClient} Sanctum
 * envelope: it must NOT send the platform's session cookie or
 * CSRF preflight to a third-party signed URL. The tests assert
 * that contract explicitly (`withCredentials: false`, no JSON
 * envelope, axios `put` invocation).
 */

import axios, { AxiosError } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from './errors'
import { uploadToPresignedUrl } from './presigned'

vi.mock('axios')

describe('uploadToPresignedUrl', () => {
  const fileLike = new File(['x'], 'clip.mp4', { type: 'video/mp4' })

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    vi.resetAllMocks()
  })

  it('PUTs the file body with withCredentials=false and the file MIME', async () => {
    vi.mocked(axios.put).mockResolvedValue({ status: 200, data: '' })

    await uploadToPresignedUrl('https://vendor.s3.example/upload-1', fileLike)

    expect(axios.put).toHaveBeenCalledTimes(1)
    const call = vi.mocked(axios.put).mock.calls[0]
    expect(call).toBeDefined()
    if (call === undefined) throw new Error('expected call')
    expect(call[0]).toBe('https://vendor.s3.example/upload-1')
    expect(call[1]).toBe(fileLike)
    expect(call[2]).toEqual(
      expect.objectContaining({
        withCredentials: false,
        headers: expect.objectContaining({ 'Content-Type': 'video/mp4' }),
      }),
    )
  })

  it('honors a contentType override over the File.type default', async () => {
    vi.mocked(axios.put).mockResolvedValue({ status: 200, data: '' })

    await uploadToPresignedUrl('https://vendor.s3.example/upload-2', fileLike, {
      contentType: 'application/octet-stream',
    })

    const call = vi.mocked(axios.put).mock.calls[0]
    expect(call).toBeDefined()
    if (call === undefined) throw new Error('expected call')
    expect(call[2]).toEqual(
      expect.objectContaining({
        headers: expect.objectContaining({ 'Content-Type': 'application/octet-stream' }),
      }),
    )
  })

  it('wraps an axios error into ApiError with presigned.upload_failed code', async () => {
    const axiosError = new AxiosError('Request failed', 'ERR_BAD_RESPONSE')
    Object.defineProperty(axiosError, 'response', {
      value: { status: 403, data: '' },
      configurable: true,
    })
    vi.mocked(axios.put).mockRejectedValue(axiosError)

    await expect(
      uploadToPresignedUrl('https://vendor.s3.example/upload-3', fileLike),
    ).rejects.toMatchObject({
      // ApiError instance + the canonical code
      status: 403,
      code: 'presigned.upload_failed',
    })
  })

  it('reports status 0 when the network call has no response', async () => {
    const axiosError = new AxiosError('Network Error', 'ERR_NETWORK')
    vi.mocked(axios.put).mockRejectedValue(axiosError)

    try {
      await uploadToPresignedUrl('https://vendor.s3.example/upload-4', fileLike)
      throw new Error('expected throw')
    } catch (error) {
      expect(error).toBeInstanceOf(ApiError)
      expect((error as ApiError).status).toBe(0)
      expect((error as ApiError).code).toBe('presigned.upload_failed')
    }
  })
})
