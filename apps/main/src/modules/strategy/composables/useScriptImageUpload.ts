/**
 * PL-3.2b — reference image uploads of ONE block (a hook or a line) of a script. The same shape as the concept uploads
 * (`useConceptImageUpload`): the browser PUTs to the presigned address the server reserved, up to three uploads at once, each with
 * its own `init`; a refused file produces a message instead of a request; a failed upload keeps its reservation and its line with a
 * Retry that resumes where it stopped. Differences: GIF is accepted, the limits are ten per block and 300 MB per SCRIPT (the bytes
 * of every block of the script count), and nothing is asked before the first byte (a script has no approval to reset).
 */
import { ApiError, uploadToPresignedUrl } from '@catalyst/api-client'
import { computed, ref, type Ref } from 'vue'

import { strategyApi, type WaveScope } from '../api/strategy.api'
import { formatMegabytes } from '../conceptsLogic'
import { planScriptUploads, type ScriptUploadRefusal } from '../scriptsLogic'
import { strategyErrorMessage } from '../strategyErrors'

type Translate = (key: string, named?: Record<string, unknown>) => string

export interface ScriptUploadItem {
  key: number
  name: string
  progress: number
  state: 'uploading' | 'failed'
  error: string | null
}

interface Internal extends ScriptUploadItem {
  file: File
  uploadId: string | null
  url: string | null
  putDone: boolean
}

export const MAX_PARALLEL_SCRIPT_UPLOADS = 3

export function useScriptImageUpload(opts: {
  scope: () => WaveScope | null
  scriptId: () => string
  block: () => 'hook' | 'line'
  blockId: () => string
  /** What the block and the script already hold (ready images and their real sizes). */
  used: () => { blockCount: number; scriptBytes: number }
  limits: () => { images: number; bytes: number }
  t: Translate
  onUploaded: () => void
}): {
  items: Ref<ScriptUploadItem[]>
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

  const items = computed<ScriptUploadItem[]>(() =>
    state.value.map(({ key, name, progress, state: s, error }) => ({
      key,
      name,
      progress,
      state: s,
      error,
    })),
  ) as unknown as Ref<ScriptUploadItem[]>
  const busy = computed(() =>
    state.value.some((i) => i.state === 'uploading'),
  ) as unknown as Ref<boolean>

  function refusalText(reason: ScriptUploadRefusal, name: string): string {
    const limits = opts.limits()
    switch (reason) {
      case 'type':
      case 'empty':
        return opts.t('app.strategy.scripts.images.refusedType', { name })
      case 'count':
        return opts.t('app.strategy.scripts.images.refusedCount', { name, max: limits.images })
      case 'bytes':
        return opts.t('app.strategy.scripts.images.refusedBytes', {
          name,
          max: formatMegabytes(limits.bytes),
        })
    }
  }

  function failure(err: unknown): string {
    if (err instanceof ApiError && err.code === 'presigned.upload_failed') {
      return opts.t('app.strategy.scripts.images.failed')
    }
    return strategyErrorMessage(err, opts.t)
  }

  async function run(item: Internal): Promise<void> {
    const scope = opts.scope()
    if (scope === null) return
    item.state = 'uploading'
    item.error = null
    try {
      if (item.uploadId === null) {
        const init = await strategyApi.initScriptImage(scope, opts.scriptId(), {
          block: opts.block(),
          id: opts.blockId(),
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
      await strategyApi.completeScriptImage(scope, opts.scriptId(), item.uploadId)
      state.value = state.value.filter((i) => i.key !== item.key)
      opts.onUploaded()
    } catch (err) {
      item.state = 'failed'
      item.error = failure(err)
      // A refusal no retry can fix (a limit, a file that is not an image, a block that was deleted): drop the line, keep the message.
      if (err instanceof ApiError && (err.status === 422 || err.status === 404)) {
        notices.value = [...notices.value, `${item.name}: ${item.error}`]
        state.value = state.value.filter((i) => i.key !== item.key)
      }
    }
  }

  function pump(): void {
    while (running < MAX_PARALLEL_SCRIPT_UPLOADS && waiting.length > 0) {
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
    const plan = planScriptUploads(
      files.map((f) => ({ name: f.name, type: f.type, size: f.size })),
      {
        blockCount: used.blockCount + inflight.length,
        scriptBytes: used.scriptBytes + inflight.reduce((n, i) => n + i.file.size, 0),
      },
      opts.limits(),
    )
    notices.value = plan.refused.map((r) => refusalText(r.reason, files[r.index]?.name ?? ''))
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
