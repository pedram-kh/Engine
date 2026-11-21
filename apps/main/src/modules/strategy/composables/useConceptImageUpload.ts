/**
 * PL-2.3 — image uploads of one concept. The browser PUTs to the presigned address the server reserved (the server
 * counts the reservation, so parallel uploads cannot pass the limits); up to three uploads run at once, each with its
 * own `init`. Before any request the files are checked against the type, the 10-image count and the 300 MB total, and a
 * refused file produces a message instead of a request. A failed upload keeps its reservation and its progress line
 * with a Retry that resumes where it stopped (re-PUT, or only `complete` when the bytes already arrived).
 */
import { ApiError, uploadToPresignedUrl } from '@catalyst/api-client'
import { computed, ref, type Ref } from 'vue'

import { strategyApi, type WaveScope } from '../api/strategy.api'
import { formatMegabytes, planUploads, type UploadRefusal } from '../conceptsLogic'
import { strategyErrorMessage } from '../strategyErrors'

type Translate = (key: string, named?: Record<string, unknown>) => string

export interface UploadItem {
  key: number
  name: string
  progress: number
  state: 'uploading' | 'failed'
  error: string | null
}

interface Internal extends UploadItem {
  file: File
  uploadId: string | null
  url: string | null
  putDone: boolean
}

export const MAX_PARALLEL_UPLOADS = 3

export function useConceptImageUpload(opts: {
  scope: () => WaveScope | null
  conceptId: () => string
  /** What the concept already holds (ready images and their real sizes). */
  used: () => { count: number; bytes: number }
  limits: () => { images: number; bytes: number }
  t: Translate
  /** Asked once before the first byte moves; false cancels the whole choice and changes nothing. */
  beforeStart: () => Promise<boolean>
  onUploaded: () => void
}): {
  items: Ref<UploadItem[]>
  notices: Ref<string[]>
  busy: Ref<boolean>
  add: (files: File[]) => Promise<void>
  retry: (key: number) => Promise<void>
  dismiss: (key: number) => void
  clearNotices: () => void
} {
  const state = ref<Internal[]>([])
  const notices = ref<string[]>([])
  let nextKey = 1
  let running = 0
  const waiting: Internal[] = []

  const items = computed<UploadItem[]>(() =>
    state.value.map(({ key, name, progress, state: s, error }) => ({
      key,
      name,
      progress,
      state: s,
      error,
    })),
  ) as unknown as Ref<UploadItem[]>
  const busy = computed(() =>
    state.value.some((i) => i.state === 'uploading'),
  ) as unknown as Ref<boolean>

  function refusalText(reason: UploadRefusal, name: string): string {
    const limits = opts.limits()
    switch (reason) {
      case 'type':
        return opts.t('app.strategy.concepts.upload.refusedType', { name })
      case 'empty':
        return opts.t('app.strategy.concepts.upload.refusedEmpty', { name })
      case 'count':
        return opts.t('app.strategy.concepts.upload.refusedCount', { name, max: limits.images })
      case 'bytes':
        return opts.t('app.strategy.concepts.upload.refusedBytes', {
          name,
          max: formatMegabytes(limits.bytes),
        })
    }
  }

  function failure(err: unknown): string {
    if (err instanceof ApiError && err.code === 'presigned.upload_failed') {
      return opts.t('app.strategy.concepts.upload.failed')
    }
    return strategyErrorMessage(err, opts.t)
  }

  async function run(item: Internal): Promise<void> {
    const scope = opts.scope()
    if (scope === null) return
    const conceptId = opts.conceptId()
    item.state = 'uploading'
    item.error = null
    try {
      if (item.uploadId === null) {
        const init = await strategyApi.initImage(scope, conceptId, {
          mime_type: item.file.type,
          declared_bytes: item.file.size,
          original_filename: item.file.name,
        })
        item.uploadId = init.data.upload_id
        item.url = init.data.upload_url
      }
      if (!item.putDone && item.url !== null) {
        await uploadToPresignedUrl(item.url, item.file, {
          contentType: item.file.type,
          onProgress: (p) => {
            item.progress = p
          },
        })
        item.putDone = true
        item.progress = 100
      }
      await strategyApi.completeImage(scope, conceptId, item.uploadId)
      state.value = state.value.filter((i) => i.key !== item.key)
      opts.onUploaded()
    } catch (err) {
      item.state = 'failed'
      item.error = failure(err)
      // A refusal that no retry can fix (limits, a file that is not an image): drop the line, keep the message.
      if (err instanceof ApiError && err.status === 422) {
        notices.value = [...notices.value, `${item.name}: ${item.error}`]
        state.value = state.value.filter((i) => i.key !== item.key)
      }
    }
  }

  function pump(): void {
    while (running < MAX_PARALLEL_UPLOADS && waiting.length > 0) {
      const next = waiting.shift()
      if (next === undefined) break
      running += 1
      void run(next).finally(() => {
        running -= 1
        pump()
      })
    }
  }

  async function add(files: File[]): Promise<void> {
    notices.value = []
    const inflight = state.value.filter((i) => i.state === 'uploading')
    const used = opts.used()
    const plan = planUploads(
      files.map((f) => ({ name: f.name, type: f.type, size: f.size })),
      {
        count: used.count + inflight.length,
        bytes: used.bytes + inflight.reduce((n, i) => n + i.file.size, 0),
      },
      opts.limits(),
    )
    notices.value = plan.refused.map((r) => refusalText(r.reason, files[r.index]?.name ?? ''))
    if (plan.accepted.length === 0) return
    if (!(await opts.beforeStart())) return
    for (const index of plan.accepted) {
      const file = files[index]
      if (file === undefined) continue
      const item: Internal = {
        key: nextKey++,
        name: file.name,
        progress: 0,
        state: 'uploading',
        error: null,
        file,
        uploadId: null,
        url: null,
        putDone: false,
      }
      state.value = [...state.value, item]
      waiting.push(state.value[state.value.length - 1] as Internal)
    }
    pump()
  }

  async function retry(key: number): Promise<void> {
    const item = state.value.find((i) => i.key === key)
    if (item === undefined || item.state !== 'failed') return
    item.state = 'uploading'
    item.error = null
    waiting.push(item)
    pump()
  }

  function dismiss(key: number): void {
    state.value = state.value.filter((i) => i.key !== key)
  }

  return {
    items,
    notices,
    busy,
    add,
    retry,
    dismiss,
    clearNotices: () => {
      notices.value = []
    },
  }
}
