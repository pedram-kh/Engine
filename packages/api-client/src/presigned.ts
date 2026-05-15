import axios, { type AxiosError } from 'axios'

import { ApiError } from './errors'

/**
 * Uploads a file body directly to a presigned third-party URL (e.g.
 * an S3 / R2 / GCS PUT URL returned by the platform's
 * `POST /portfolio/videos/init` endpoint).
 *
 * This transport intentionally bypasses {@link createHttpClient}:
 *
 *   - The presigned URL is vendor-hosted (S3 / R2 / GCS). Sending
 *     it through the platform's CSRF-preflight chain would either
 *     fail (no XSRF cookie at vendor.s3.amazonaws.com) or leak the
 *     `X-XSRF-TOKEN` header to a third party — both bad.
 *   - The body is the raw `File` blob, not a JSON envelope; the
 *     `Content-Type` header must match the MIME the upload was
 *     signed for, not `application/json`.
 *   - `withCredentials: false` — we explicitly do NOT want the
 *     platform's session cookie sent to the vendor.
 *
 * Lives inside `@catalyst/api-client` so that the
 * `no-direct-http.spec.ts` architecture test stays meaningful:
 * the rule is "all transport flows through the api-client"; this
 * is the api-client's curated presigned-upload primitive, with the
 * vendor-specific isolation policy documented above.
 *
 * Throws an {@link ApiError} on non-2xx response or network failure,
 * matching the rest of the api-client's error contract.
 */
export async function uploadToPresignedUrl(
  url: string,
  body: File | Blob,
  options: { contentType?: string } = {},
): Promise<void> {
  const contentType = options.contentType ?? body.type
  try {
    await axios.put(url, body, {
      withCredentials: false,
      headers: {
        'Content-Type': contentType,
      },
      // The Sprint 3 chunk 1 backend signs presigned URLs with a
      // 30-minute expiry; the upload itself can legitimately take
      // several minutes for a 500 MB video, so we set a generous
      // 10-minute total timeout to defend against truly stalled
      // connections without aborting healthy uploads.
      timeout: 10 * 60 * 1000,
    })
  } catch (error) {
    const axiosError = error as AxiosError
    throw new ApiError({
      status: axiosError.response?.status ?? 0,
      code: 'presigned.upload_failed',
      message: axiosError.message,
      cause: error,
    })
  }
}
