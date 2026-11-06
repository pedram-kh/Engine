import { downloadPresignedBlob } from '@catalyst/api-client'
import { Share } from '@capacitor/share'

/**
 * AH-119 (walk) — "download" for an inline message image.
 *
 * Web: the image is read (through the api-client's presigned-GET helper) and saved as a file from a Blob, so the page never navigates and no tab
 * opens. A cross-origin signed URL only downloads through a plain `<a download>` when the response
 * says so, which it does not; if the fetch is refused (the bucket's CORS does not allow a GET from
 * this origin) the saved-file anchor falls back to the URL itself, which the browser then opens or
 * saves by its own rules.
 *
 * Capacitor app: the image is handed to the system share sheet (`@capacitor/share`), where "Save
 * Image" and the other targets live. It shares the signed URL, so the link a receiving app gets
 * expires with the URL.
 *
 * Neither path throws: a cancelled share sheet or a failed fetch is the user's "never mind".
 */

function fileNameFor(name: string | null, url: string): string {
  if (name !== null && name.trim() !== '') {
    return name.trim()
  }
  const last = url.split('?')[0]?.split('/').pop() ?? ''
  return last === '' ? 'image' : last
}

function saveAnchor(href: string, fileName: string): void {
  const anchor = document.createElement('a')
  anchor.href = href
  anchor.download = fileName
  anchor.rel = 'noopener noreferrer'
  anchor.style.display = 'none'
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
}

export async function downloadImageOnWeb(url: string, name: string | null): Promise<void> {
  const fileName = fileNameFor(name, url)
  try {
    const objectUrl = URL.createObjectURL(await downloadPresignedBlob(url))
    saveAnchor(objectUrl, fileName)
    setTimeout(() => URL.revokeObjectURL(objectUrl), 10_000)
  } catch {
    saveAnchor(url, fileName)
  }
}

export async function shareImageOnNative(url: string, name: string | null): Promise<void> {
  try {
    await Share.share({ title: fileNameFor(name, url), url })
  } catch {
    // The share sheet was dismissed or is unavailable.
  }
}
