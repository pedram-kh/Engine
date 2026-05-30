/**
 * Capture a still poster frame from a local video File, entirely
 * client-side, so the portfolio gallery can show a real thumbnail
 * instead of a generic play-badge placeholder.
 *
 * Why client-side: browsers can't render a raw `.mp4` into an `<img>`,
 * and the backend stores videos without extracting a poster. We decode
 * one frame here (hidden <video> → <canvas> → JPEG) and hand it to the
 * `videos/complete` call, which persists it as the item's thumbnail.
 *
 * Resilience: this is a best-effort enhancement. ANY failure (codec the
 * browser can't decode, autoplay/seek restrictions, missing canvas in a
 * non-DOM runtime, timeout) resolves to `null` — the upload still
 * succeeds, the gallery just falls back to the play-badge placeholder.
 */

/** Longest-edge cap for the generated poster (keeps the upload small). */
const POSTER_MAX_EDGE = 640

/** Give up if the browser hasn't produced a frame within this window. */
const CAPTURE_TIMEOUT_MS = 5000

/** JPEG quality for the encoded poster. */
const POSTER_QUALITY = 0.8

export async function captureVideoPoster(file: File): Promise<Blob | null> {
  // Guard for non-DOM runtimes (SSR / unit tests without jsdom canvas).
  if (typeof document === 'undefined' || typeof URL?.createObjectURL !== 'function') {
    return null
  }

  return new Promise<Blob | null>((resolve) => {
    const objectUrl = URL.createObjectURL(file)
    const video = document.createElement('video')
    let settled = false

    const cleanup = (): void => {
      window.clearTimeout(timer)
      video.removeAttribute('src')
      try {
        video.load()
      } catch {
        // ignore — element is being discarded anyway
      }
      URL.revokeObjectURL(objectUrl)
    }

    const finish = (blob: Blob | null): void => {
      if (settled) return
      settled = true
      cleanup()
      resolve(blob)
    }

    const timer = window.setTimeout(() => finish(null), CAPTURE_TIMEOUT_MS)

    video.muted = true
    video.preload = 'metadata'
    video.playsInline = true

    video.addEventListener('error', () => finish(null))

    video.addEventListener('loadedmetadata', () => {
      // Seek a touch into the clip — frame 0 is frequently black/blank.
      const target = Number.isFinite(video.duration)
        ? Math.min(Math.max(video.duration * 0.1, 0.1), Math.max(video.duration - 0.1, 0.1))
        : 0.1
      try {
        video.currentTime = target
      } catch {
        finish(null)
      }
    })

    video.addEventListener('seeked', () => {
      try {
        const width = video.videoWidth
        const height = video.videoHeight
        if (width === 0 || height === 0) {
          finish(null)
          return
        }

        const scale = Math.min(1, POSTER_MAX_EDGE / Math.max(width, height))
        const canvas = document.createElement('canvas')
        canvas.width = Math.round(width * scale)
        canvas.height = Math.round(height * scale)

        const ctx = canvas.getContext('2d')
        if (ctx === null) {
          finish(null)
          return
        }

        ctx.drawImage(video, 0, 0, canvas.width, canvas.height)
        canvas.toBlob((blob) => finish(blob), 'image/jpeg', POSTER_QUALITY)
      } catch {
        finish(null)
      }
    })

    video.src = objectUrl
  })
}
