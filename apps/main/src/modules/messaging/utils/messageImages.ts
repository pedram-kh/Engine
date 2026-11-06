/** AH-119 (S2) — the image types a message may carry inline / accept from paste and drop. */
export const ALLOWED_IMAGE_TYPES: readonly string[] = [
  'image/png',
  'image/jpeg',
  'image/webp',
  'image/gif',
]

/** Server limit per attachment (25 MB). */
export const MAX_IMAGE_BYTES = 25 * 1024 * 1024

export function isInlineImageMime(mime: string | null | undefined): boolean {
  return mime !== null && mime !== undefined && ALLOWED_IMAGE_TYPES.includes(mime.toLowerCase())
}
