/**
 * AH-119 (S2) — paste and drag-and-drop image attach for a message composer.
 *
 * Attaches `paste` / `dragenter` / `dragover` / `dragleave` / `drop` listeners to the target
 * element and hands ONLY accepted images (png / jpeg / webp / gif, ≤ 25 MB) to `onFiles`; the
 * component appends them to the same pending list the paperclip fills. Feature-detected: with no
 * `clipboardData` / `dataTransfer` nothing happens. Native paste is not assumed — if the events
 * never fire (WKWebView) the paperclip stays the route.
 */
import { getCurrentInstance, getCurrentScope, onMounted, onScopeDispose, ref, watch } from 'vue'
import type { Ref } from 'vue'

import { ALLOWED_IMAGE_TYPES, MAX_IMAGE_BYTES } from '../utils/messageImages'

export type ComposerImageRejection = 'type' | 'size'

export interface ComposerImageOptions {
  maxBytes?: number
  onRejected?: (reason: ComposerImageRejection) => void
}

export function useComposerImages(
  target: Ref<HTMLElement | null>,
  onFiles: (files: File[]) => void,
  options: ComposerImageOptions = {},
): { dragging: Ref<boolean> } {
  const dragging = ref(false)
  const maxBytes = options.maxBytes ?? MAX_IMAGE_BYTES
  let depth = 0

  /** Sort files into accepted images and report why the rest were dropped. */
  function accept(files: File[]): File[] {
    const images = files.filter((f) => ALLOWED_IMAGE_TYPES.includes(f.type.toLowerCase()))
    if (images.length < files.length) {
      options.onRejected?.('type')
    }
    const ok = images.filter((f) => f.size <= maxBytes)
    if (ok.length < images.length) {
      options.onRejected?.('size')
    }
    return ok
  }

  function onPaste(event: ClipboardEvent): void {
    const files = Array.from(event.clipboardData?.files ?? [])
    if (files.length === 0) {
      return // plain text (or no clipboardData): leave the paste alone
    }
    if (!files.some((f) => ALLOWED_IMAGE_TYPES.includes(f.type.toLowerCase()))) {
      options.onRejected?.('type')
      return
    }
    event.preventDefault()
    const ok = accept(files)
    if (ok.length > 0) {
      onFiles(ok)
    }
  }

  function hasFiles(event: DragEvent): boolean {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files')
  }

  function onDragEnter(event: DragEvent): void {
    if (!hasFiles(event)) return
    event.preventDefault()
    depth++
    dragging.value = true
  }

  function onDragOver(event: DragEvent): void {
    if (!hasFiles(event)) return
    event.preventDefault()
  }

  function onDragLeave(event: DragEvent): void {
    if (!hasFiles(event)) return
    depth = Math.max(0, depth - 1)
    if (depth === 0) dragging.value = false
  }

  function onDrop(event: DragEvent): void {
    if (!hasFiles(event)) return
    event.preventDefault()
    depth = 0
    dragging.value = false
    const ok = accept(Array.from(event.dataTransfer?.files ?? []))
    if (ok.length > 0) {
      onFiles(ok)
    }
  }

  function attach(el: HTMLElement): void {
    el.addEventListener('paste', onPaste)
    el.addEventListener('dragenter', onDragEnter)
    el.addEventListener('dragover', onDragOver)
    el.addEventListener('dragleave', onDragLeave)
    el.addEventListener('drop', onDrop)
  }

  function detach(el: HTMLElement): void {
    el.removeEventListener('paste', onPaste)
    el.removeEventListener('dragenter', onDragEnter)
    el.removeEventListener('dragover', onDragOver)
    el.removeEventListener('dragleave', onDragLeave)
    el.removeEventListener('drop', onDrop)
    depth = 0
    dragging.value = false
  }

  // The composer can come and go (closed thread), so follow the ref rather than onMounted once.
  const stop = watch(
    target,
    (el, previous) => {
      if (previous) detach(previous)
      if (el) attach(el)
    },
    { immediate: true, flush: 'post' },
  )

  // Attach synchronously at mount (the watcher above only catches later ref changes).
  // addEventListener de-duplicates identical listeners, so doing both is harmless.
  if (getCurrentInstance()) {
    onMounted(() => {
      if (target.value) attach(target.value)
    })
  }

  if (getCurrentScope()) {
    onScopeDispose(() => {
      stop()
      if (target.value) detach(target.value)
    })
  }

  return { dragging }
}
